<?php declare(strict_types=1);

namespace Modules\ClosetInventory\Actions;

use API;
use CController;
use CControllerResponseData;
use CWebUser;
use Modules\ClosetInventory\Lib\DebugLog;
use Modules\ClosetInventory\Lib\InventoryStore;
use Modules\ClosetInventory\Lib\SchoolMapper;
use Throwable;

/**
 * POST zabbix.php?action=closet.populate.zabbix
 *
 * Walks every `Site/*` host group, parses each member host's name into
 * (schoolId, closetCode, switchName, closetType), and idempotently upserts
 * schools / closets / switches into the module's authored tables.
 *
 * Admin-only. Never deletes anything; only INSERTs missing rows and UPDATEs
 * external keys (zabbix_hostid, mgmt_ip) on switches that match an existing
 * (closet_uid, name) pair.
 *
 * Hostname grammar (see workstream A of the Phase 4 brief):
 *   RHS-IDF-203-SW1   → school RHS, closet RHS-IDF-203 (IDF), switch RHS-IDF-203-SW1
 *   RHS-MDF-SW2       → school RHS, closet RHS-MDF      (MDF), switch RHS-MDF-SW2
 *   RHS-IDF-203-CORE  → school RHS, closet RHS-IDF-203 (IDF), switch RHS-IDF-203-CORE
 *   ANYTHING-ELSE     → closet = full hostname, switch  = full hostname,
 *                       type guessed from any embedded MDF/IDF token else IDF.
 */
class ActionPopulateFromZabbix extends CController {

    /** Suffix tokens that indicate "the rest before this is the closet code". */
    private const SWITCH_SUFFIXES = ['CORE', 'EDGE', 'DIST', 'AGG', 'ACCESS'];

    protected function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkPermissions(): bool {
        if (!CWebUser::isLoggedIn()) {
            http_response_code(401);
            $this->setResponse(new CControllerResponseData([
                'main_block' => json_encode(['ok' => false, 'error' => 'unauthenticated'])
            ]));
            return false;
        }
        return $this->getUserType() >= USER_TYPE_ZABBIX_ADMIN;
    }

    protected function checkInput(): bool {
        return true;
    }

    protected function doAction(): void {
        try {
            $store = new InventoryStore();
            $report = [
                'schoolsCreated'   => 0,
                'closetsCreated'   => 0,
                'switchesCreated'  => 0,
                'hostsScanned'     => 0,
                'hostsExcluded'    => 0,
                'switchesRemoved'  => 0,
                'errors'           => []
            ];

            // Hosts in any of these top-level group prefixes are excluded from
            // import (wireless APs and IP-camera infrastructure live alongside
            // switches in Site/*, but we don't want them in the closet model).
            $excludedPrefixes = ['Wireless/', 'Video/'];

            // Collect hostids of every host belonging to any excluded-prefix
            // group. One API call per prefix, deduped into a set.
            $excludedHostids = [];
            foreach ($excludedPrefixes as $prefix) {
                try {
                    $grp = API::HostGroup()->get([
                        'output'      => ['groupid', 'name'],
                        'search'      => ['name' => $prefix],
                        'startSearch' => true
                    ]) ?: [];
                    if (empty($grp)) {
                        continue;
                    }
                    $hosts = API::Host()->get([
                        'output'   => ['hostid'],
                        'groupids' => array_map(fn($g) => (string) $g['groupid'], $grp)
                    ]) ?: [];
                    foreach ($hosts as $h) {
                        $excludedHostids[(string) $h['hostid']] = true;
                    }
                }
                catch (\Throwable $e) {
                    $report['errors'][] = "exclude lookup failed for '$prefix*': ".$e->getMessage();
                }
            }
            DebugLog::log('ActionPopulateFromZabbix.excludedHostids', [
                'count'    => count($excludedHostids),
                'prefixes' => $excludedPrefixes
            ]);

            // Cleanup pass: walk every switch we've imported and remove the
            // ones whose Zabbix host now belongs to an excluded-prefix group.
            // Closet rows themselves stay — operators may have authored fields
            // (room, photos, maintenance) under them; only the switch row is
            // wrong here. Counters on affected closets get recomputed.
            try {
                $touchedCloset = [];
                $rs = \DBselect('SELECT id, closet_uid, zabbix_hostid FROM tcs_closet_switches WHERE zabbix_hostid IS NOT NULL AND zabbix_hostid <> \'\'');
                while ($r = \DBfetch($rs)) {
                    $hid = (string) $r['zabbix_hostid'];
                    if ($hid === '' || !isset($excludedHostids[$hid])) {
                        continue;
                    }
                    \DBexecute('DELETE FROM tcs_closet_switches WHERE id='.(int) $r['id']);
                    $touchedCloset[(int) $r['closet_uid']] = true;
                    $report['switchesRemoved']++;
                }
                foreach (array_keys($touchedCloset) as $cuid) {
                    $store->recomputePortCounters($cuid);
                }
            }
            catch (\Throwable $e) {
                $report['errors'][] = 'cleanup pass failed: '.$e->getMessage();
            }

            $groups = SchoolMapper::fetchZbxGroupsByPrefix('Site/');
            DebugLog::log('ActionPopulateFromZabbix.groups', ['count' => count($groups)]);

            foreach ($groups as $g) {
                $groupName = (string) ($g['name']    ?? '');
                $groupId   = (string) ($g['groupid'] ?? '');
                if ($groupName === '' || $groupId === '') {
                    continue;
                }


                $schoolId = SchoolMapper::deriveSchoolIdFromGroup($groupName);
                if ($schoolId === null) {
                    $report['errors'][] = "could not derive school id from group '$groupName'";
                    continue;
                }

                $displayName = trim(substr($groupName, strlen('Site/')));
                $schoolType  = self::guessSchoolType($displayName);
                $hue         = self::stableHue($schoolId);

                $schoolUpsert = $store->upsertSchool($schoolId, [
                    'name'     => $displayName,
                    'type'     => $schoolType,
                    'zbxGroup' => $groupName,
                    'colorHue' => $hue
                ]);
                if ($schoolUpsert['created']) {
                    $report['schoolsCreated']++;
                }

                $hosts = [];
                try {
                    $hosts = API::Host()->get([
                        'output'           => ['hostid', 'host', 'name', 'status'],
                        'groupids'         => [$groupId],
                        'selectInterfaces' => ['ip', 'main', 'type']
                    ]);
                    if (!is_array($hosts)) $hosts = [];
                }
                catch (Throwable $e) {
                    $report['errors'][] = "host.get failed for group '$groupName': ".$e->getMessage();
                    DebugLog::log('ActionPopulateFromZabbix.host.get.fail', [
                        'group' => $groupName,
                        'error' => $e->getMessage()
                    ]);
                    continue;
                }

                foreach ($hosts as $h) {
                    $report['hostsScanned']++;
                    $technical = trim((string) ($h['host'] ?? ''));
                    $visible   = trim((string) ($h['name'] ?? ''));
                    $hostid    = (string) ($h['hostid'] ?? '');
                    if ($hostid === '' || $technical === '') {
                        continue;
                    }
                    // Wireless/* and Video/* hosts share Site/* membership with
                    // real switches; skip them so APs and cameras don't land
                    // in the closet inventory.
                    if (isset($excludedHostids[$hostid])) {
                        $report['hostsExcluded']++;
                        continue;
                    }

                    $parsed = self::parseHostname($technical, $schoolId);
                    $closetCode = $parsed['closetCode'];
                    $switchName = $parsed['switchName'];
                    $closetType = $parsed['closetType'];

                    if ($closetCode === '' || $switchName === '') {
                        $report['errors'][] = "could not parse hostname '$technical'";
                        continue;
                    }

                    $closetUpsert = $store->upsertCloset($closetCode, [
                        'type'     => $closetType,
                        'schoolId' => $schoolId
                    ]);
                    if ($closetUpsert['created']) {
                        $report['closetsCreated']++;
                    }
                    $closetUid = $closetUpsert['uid'];
                    if ($closetUid <= 0) {
                        continue;
                    }

                    $mgmtIp = self::pickMgmtIp($h['interfaces'] ?? []);

                    $payload = ['zabbixHostid' => $hostid];
                    if ($mgmtIp !== '') {
                        $payload['mgmtIp'] = $mgmtIp;
                    }
                    $swUpsert = $store->upsertSwitch($closetUid, $switchName, $payload);
                    if ($swUpsert['created']) {
                        $report['switchesCreated']++;
                    }
                }
            }

            $report['ok'] = true;
            $this->setResponse(new CControllerResponseData([
                'main_block' => json_encode($report)
            ]));
        }
        catch (Throwable $e) {
            DebugLog::log('ActionPopulateFromZabbix.fatal', [
                'msg'   => $e->getMessage(),
                'file'  => $e->getFile().':'.$e->getLine()
            ]);
            $this->setResponse(new CControllerResponseData([
                'main_block' => json_encode([
                    'ok'    => false,
                    'error' => $e->getMessage()
                ])
            ]));
        }
    }

    /**
     * Parse a switch hostname into a (closet_code, switch_name, closet_type)
     * tuple. See class docblock for the grammar.
     *
     * @return array{closetCode:string, switchName:string, closetType:string}
     */
    public static function parseHostname(string $hostname, string $schoolId): array {
        $hostname = trim($hostname);
        if ($hostname === '') {
            return ['closetCode' => '', 'switchName' => '', 'closetType' => 'IDF'];
        }

        $parts = explode('-', $hostname);
        $n = count($parts);

        $closetType = 'IDF';
        foreach ($parts as $p) {
            $u = strtoupper($p);
            if ($u === 'MDF') { $closetType = 'MDF'; break; }
            if ($u === 'IDF') { $closetType = 'IDF'; break; }
        }

        // Identify a trailing switch suffix: SW\d+ or a recognised role token.
        $lastIdx = $n - 1;
        $last    = strtoupper((string) $parts[$lastIdx]);
        $hasSuffix = false;

        if (preg_match('/^SW\d+$/i', $last) === 1) {
            $hasSuffix = true;
        }
        else if (in_array($last, self::SWITCH_SUFFIXES, true)) {
            $hasSuffix = true;
        }

        if ($hasSuffix && $n >= 2) {
            $closetCode = implode('-', array_slice($parts, 0, $lastIdx));
            $switchName = $hostname;
            return [
                'closetCode' => $closetCode,
                'switchName' => $switchName,
                'closetType' => $closetType
            ];
        }

        // No suffix — closet code IS the whole hostname.
        return [
            'closetCode' => $hostname,
            'switchName' => $hostname,
            'closetType' => $closetType
        ];
    }

    /**
     * Heuristic school type from display name.
     */
    private static function guessSchoolType(string $name): string {
        $l = strtolower($name);
        if (str_contains($l, 'high'))       return 'High';
        if (str_contains($l, 'middle'))     return 'Middle';
        if (str_contains($l, 'elementary')) return 'Elem';
        if (str_contains($l, 'elem'))       return 'Elem';
        if (str_contains($l, 'admin'))      return 'Admin';
        if (str_contains($l, 'office'))     return 'Admin';
        return 'Elem';
    }

    /**
     * Stable hue in [0,359] from a school id, so the school chip color is
     * deterministic across imports.
     */
    private static function stableHue(string $schoolId): int {
        $h = crc32($schoolId);
        return $h % 360;
    }

    /**
     * Pull the first main agent (type=1) interface IP, falling back to any
     * main interface and finally to the first non-empty IP.
     *
     * @param array<int, array<string, mixed>>|mixed $interfaces
     */
    private static function pickMgmtIp($interfaces): string {
        if (!is_array($interfaces)) {
            return '';
        }
        $any = '';
        foreach ($interfaces as $i) {
            if (!is_array($i)) continue;
            $ip = trim((string) ($i['ip'] ?? ''));
            if ($ip === '' || $ip === '0.0.0.0') continue;
            $main = (int) ($i['main'] ?? 0) === 1;
            $type = (int) ($i['type'] ?? 0);
            if ($main && $type === 1) {
                return $ip;
            }
            if ($any === '') {
                $any = $ip;
            }
        }
        return $any;
    }
}
