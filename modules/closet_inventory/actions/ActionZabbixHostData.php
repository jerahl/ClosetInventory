<?php declare(strict_types=1);

namespace Modules\ClosetInventory\Actions;

use API;
use CControllerResponseData;
use Modules\ClosetInventory\Lib\Cache;
use Modules\ClosetInventory\Lib\SchoolMapper;
use Throwable;

/**
 * GET zabbix.php?action=closet.zabbix.host.data&hostid=NN
 *                                              &hostname=RHS-IDF-SW1
 *                                              &ip=10.10.0.2
 *
 * Exactly one of hostid|hostname|ip must be provided.
 *
 * Returns:
 *   { ok:true, host:{ hostid, hostname, visibleName, mgmtIp,
 *                     interfaces:[{ip,type,main}], groups:[{groupid,name}],
 *                     schoolIdGuess:"RHS"|null }, source:"ok" }
 *   { ok:true, host:null, source:"not_found" }
 *
 * Same graceful-failure shape as ActionXiqDeviceData.
 */
class ActionZabbixHostData extends ActionDataBase {

    /** Cache TTL for the host search index (seconds). */
    private const TTL_SEARCH = 60;

    protected function checkInput(): bool {
        return $this->validateInput([
            'hostid'   => 'string',
            'hostname' => 'string',
            'ip'       => 'string'
        ]);
    }

    protected function doAction(): void {
        $hostid   = trim((string) $this->getInput('hostid', ''));
        $hostname = trim((string) $this->getInput('hostname', ''));
        $ip       = trim((string) $this->getInput('ip', ''));

        $given = (int) ($hostid !== '')
               + (int) ($hostname !== '')
               + (int) ($ip !== '');
        if ($given !== 1) {
            http_response_code(400);
            $this->setResponse(new CControllerResponseData([
                'main_block' => json_encode([
                    'ok'    => false,
                    'error' => 'provide exactly one of: hostid, hostname, ip'
                ])
            ]));
            return;
        }

        $cacheKey = 'closet_inv:zbx_host:'.md5($hostid.'|'.$hostname.'|'.$ip);
        $hit = Cache::get($cacheKey);
        if ($hit !== null) {
            $decoded = json_decode($hit, true);
            if (is_array($decoded)) {
                $this->setResponse(new CControllerResponseData([
                    'main_block' => json_encode($decoded)
                ]));
                return;
            }
        }

        try {
            $row = $this->lookupHost($hostid, $hostname, $ip);
        }
        catch (Throwable $e) {
            $this->setResponse(new CControllerResponseData([
                'main_block' => json_encode([
                    'ok'     => true,
                    'host'   => null,
                    'source' => 'down'
                ])
            ]));
            return;
        }

        if ($row === null) {
            $payload = ['ok' => true, 'host' => null, 'source' => 'not_found'];
            Cache::set($cacheKey, (string) json_encode($payload), self::TTL_SEARCH);
            $this->setResponse(new CControllerResponseData([
                'main_block' => json_encode($payload)
            ]));
            return;
        }

        $payload = [
            'ok'     => true,
            'host'   => $row,
            'source' => 'ok'
        ];
        Cache::set($cacheKey, (string) json_encode($payload), self::TTL_SEARCH);
        $this->setResponse(new CControllerResponseData([
            'main_block' => json_encode($payload)
        ]));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lookupHost(string $hostid, string $hostname, string $ip): ?array {
        $get = [
            'output'           => ['hostid', 'host', 'name'],
            'selectInterfaces' => ['ip', 'main', 'type'],
            'selectGroups'     => ['groupid', 'name']
        ];

        if ($hostid !== '') {
            $get['hostids'] = [$hostid];
        }
        elseif ($hostname !== '') {
            $get['search']      = ['host' => $hostname, 'name' => $hostname];
            $get['searchByAny'] = true;
            $get['limit']       = 5;
        }
        elseif ($ip !== '') {
            // Filter on interface IP via the interface filter param.
            $get['filter']         = ['ip' => $ip];
            $get['searchByAny']    = false;
            $get['limit']          = 5;
        }

        $rows = API::Host()->get($get);
        if (!is_array($rows) || $rows === []) {
            return null;
        }

        // Exact-match preference for hostname queries.
        if ($hostname !== '' && count($rows) > 1) {
            $needle = strtolower($hostname);
            foreach ($rows as $r) {
                if (strtolower((string) ($r['host'] ?? '')) === $needle
                 || strtolower((string) ($r['name'] ?? '')) === $needle) {
                    return $this->shapeRow($r);
                }
            }
        }

        // For IP queries, walk interfaces to confirm.
        if ($ip !== '' && count($rows) > 1) {
            foreach ($rows as $r) {
                foreach (($r['interfaces'] ?? []) as $i) {
                    if (is_array($i) && (string) ($i['ip'] ?? '') === $ip) {
                        return $this->shapeRow($r);
                    }
                }
            }
        }

        return $this->shapeRow($rows[0]);
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private function shapeRow(array $r): array {
        $interfaces = [];
        $mgmtIp     = '';
        foreach (($r['interfaces'] ?? []) as $i) {
            if (!is_array($i)) continue;
            $row = [
                'ip'   => (string) ($i['ip']   ?? ''),
                'type' => (int)    ($i['type'] ?? 0),
                'main' => (int)    ($i['main'] ?? 0)
            ];
            $interfaces[] = $row;
            if ($mgmtIp === '' && $row['ip'] !== '' && $row['ip'] !== '0.0.0.0'
             && $row['main'] === 1 && $row['type'] === 1) {
                $mgmtIp = $row['ip'];
            }
        }
        if ($mgmtIp === '') {
            // Fall back to the first non-empty IP.
            foreach ($interfaces as $row) {
                if ($row['ip'] !== '' && $row['ip'] !== '0.0.0.0') {
                    $mgmtIp = $row['ip'];
                    break;
                }
            }
        }

        $groups = [];
        $schoolIdGuess = null;
        foreach (($r['groups'] ?? []) as $g) {
            if (!is_array($g)) continue;
            $name = (string) ($g['name'] ?? '');
            $groups[] = [
                'groupid' => (string) ($g['groupid'] ?? ''),
                'name'    => $name
            ];
            if ($schoolIdGuess === null && str_starts_with($name, 'Site/')) {
                $schoolIdGuess = SchoolMapper::deriveSchoolIdFromGroup($name);
            }
        }

        return [
            'hostid'        => (string) ($r['hostid'] ?? ''),
            'hostname'      => (string) ($r['host']   ?? ''),
            'visibleName'   => (string) ($r['name']   ?? ''),
            'mgmtIp'        => $mgmtIp,
            'interfaces'    => $interfaces,
            'groups'        => $groups,
            'schoolIdGuess' => $schoolIdGuess
        ];
    }
}
