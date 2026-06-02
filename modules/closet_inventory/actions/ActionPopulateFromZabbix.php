<?php declare(strict_types=1);

namespace Modules\ClosetInventory\Actions;

use API;
use CController;
use CControllerResponseData;
use CWebUser;
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

            // Hosts in any group whose path contains one of these segments are
            // excluded from import (wireless APs and IP-camera infrastructure
            // live under Site/Wireless/... and Site/Video/...). Match both
            // top-level Wireless/... / Video/... and nested Site/Wireless/...
            // / Site/Video/... naming. Comparison is case-insensitive on the
            // suffix; substring compare against /Wireless/ catches every
            // nesting depth.
            $excludedSegments = ['/wireless/', '/video/', '/servers/', '/wireless aps/'];
            $isExcludedGroup = static function (string $name) use ($excludedSegments): bool {
                $needle = '/' . strtolower($name) . '/';
                foreach ($excludedSegments as $seg) {
                    if (strpos($needle, $seg) !== false) return true;
                }
                return false;
            };

            // Collect hostids of every host in an excluded-segment group. One
            // API call: fetch all groups (we'll filter client-side because the
            // search prefix doesn't help with the embedded-segment pattern),
            // then host.get against the matching groupids.
            $excludedHostids = [];
            try {
                $allGroups = API::HostGroup()->get([
                    'output' => ['groupid', 'name']
                ]) ?: [];
                $excludedGroupIds = [];
                foreach ($allGroups as $g) {
                    if ($isExcludedGroup((string) $g['name'])) {
                        $excludedGroupIds[] = (string) $g['groupid'];
                    }
                }
                if (!empty($excludedGroupIds)) {
                    $hosts = API::Host()->get([
                        'output'   => ['hostid'],
                        'groupids' => $excludedGroupIds
                    ]) ?: [];
                    foreach ($hosts as $h) {
                        $excludedHostids[(string) $h['hostid']] = true;
                    }
                }
            }
            catch (\Throwable $e) {
                $report['errors'][] = 'exclude lookup failed: '.$e->getMessage();
            }

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

            foreach ($groups as $g) {
                $groupName = (string) ($g['name']    ?? '');
                $groupId   = (string) ($g['groupid'] ?? '');
                if ($groupName === '' || $groupId === '') {
                    continue;
                }

                // Skip wireless / video subtrees so 'Site/Wireless/RHS' etc.
                // doesn't get treated as its own school.
                if ($isExcludedGroup($groupName)) {
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
                    $switchName = $parsed['switchName'];
                    $closetType = $parsed['closetType'];
                    // Closet code is the closet portion of the switch name:
                    // 'TCTA-IDF-203-SW1' → 'TCTA-IDF-203'. The school is
                    // always taken from the Zabbix host group membership
                    // ($schoolId), independent of the hostname's prefix —
                    // hostnames like TMS-MDF-SW1 inside Site/TASPA stay named
                    // TMS-MDF but get filed under TASPA.
                    $closetCode = $parsed['closetCode'];

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
     * Splits a switch hostname into:
     *   - closetCode: the closet portion of the name (full hostname minus
     *     the trailing SW#/CORE/EDGE/etc suffix).
     *   - switchName: the original hostname unchanged.
     *   - closetType: MDF or IDF, from the matching token, defaulting to IDF.
     *   - roomId: segments between the type token and the trailing suffix
     *     (e.g. '203' for TCTA-IDF-203-SW1). Useful when the consumer wants
     *     just the room portion without the prefix.
     *
     * @return array{closetCode:string, switchName:string, closetType:string, roomId:string}
     */
    public static function parseHostname(string $hostname, string $schoolId): array {
        $hostname = trim($hostname);
        if ($hostname === '') {
            return ['closetCode' => '', 'switchName' => '', 'closetType' => 'IDF', 'roomId' => ''];
        }

        $parts = explode('-', $hostname);
        $n = count($parts);

        $closetType = 'IDF';
        $typeIdx    = -1;
        foreach ($parts as $i => $p) {
            $u = strtoupper($p);
            if ($u === 'MDF') { $closetType = 'MDF'; $typeIdx = $i; break; }
            if ($u === 'IDF') { $closetType = 'IDF'; $typeIdx = $i; break; }
        }

        // Identify a trailing switch suffix: SW\d+ or a recognised role token.
        $lastIdx = $n - 1;
        $last    = strtoupper((string) $parts[$lastIdx]);
        $hasSuffix = (preg_match('/^SW\d+$/i', $last) === 1)
                  || in_array($last, self::SWITCH_SUFFIXES, true);

        $roomEnd = $hasSuffix ? $lastIdx - 1 : $lastIdx;
        $roomId = '';
        if ($typeIdx >= 0 && $roomEnd > $typeIdx) {
            $roomId = implode('-', array_slice($parts, $typeIdx + 1, $roomEnd - $typeIdx));
        }

        $closetCode = ($hasSuffix && $n >= 2)
            ? implode('-', array_slice($parts, 0, $lastIdx))
            : $hostname;

        return [
            'closetCode' => $closetCode,
            'switchName' => $hostname,
            'closetType' => $closetType,
            'roomId'     => $roomId
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
