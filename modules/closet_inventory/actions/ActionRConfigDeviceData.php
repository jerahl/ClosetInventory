<?php declare(strict_types=1);

namespace Modules\ClosetInventory\Actions;

use API;
use CControllerResponseData;
use Modules\ClosetInventory\Lib\Cache;
use Modules\ClosetInventory\Lib\Config;
use Modules\ClosetInventory\Lib\RConfigAmbiguousMatchException;
use Modules\ClosetInventory\Lib\RConfigClient;
use Throwable;

/**
 * GET zabbix.php?action=closet.rconfig.device.data&zabbixHostid=NN
 *                                                 &hostname=RHS-IDF-SW1
 *
 * Resolves a Zabbix host to its rConfig device id (by SNMP IP / any-interface
 * IP / technical hostname / visible name) and returns backup-age info. Mirrors
 * the source-status envelope of the XIQ / Zabbix lookup endpoints.
 */
class ActionRConfigDeviceData extends ActionDataBase {

    /** Cache TTL for the per-device backup info (seconds). */
    private const TTL_DEVICE = 300;

    protected function checkInput(): bool {
        return $this->validateInput([
            'zabbixHostid' => 'string',
            'hostname'     => 'string'
        ]);
    }

    protected function doAction(): void {
        $hostid   = trim((string) $this->getInput('zabbixHostid', ''));
        $hostname = trim((string) $this->getInput('hostname', ''));

        if ($hostid === '' && $hostname === '') {
            http_response_code(400);
            $this->setResponse(new CControllerResponseData([
                'main_block' => json_encode([
                    'ok'    => false,
                    'error' => 'provide zabbixHostid or hostname'
                ])
            ]));
            return;
        }

        $url   = Config::rconfigUrl();
        $token = Config::rconfigToken();
        if ($url === null || $token === null) {
            $this->respondEmpty('unconfigured');
            return;
        }

        try {
            $client = new RConfigClient($url, $token);
        }
        catch (Throwable $e) {
            $this->respondEmpty('down');
            return;
        }

        // Build the Zabbix host array RConfigClient::resolveDeviceId() needs.
        $host = $this->resolveZabbixHost($hostid, $hostname);
        if ($host === null) {
            $this->respondEmpty('not_found');
            return;
        }

        $deviceId = 0;
        try {
            $deviceId = $client->resolveDeviceId(
                (string) ($host['hostid']      ?? ''),
                (string) ($host['hostname']    ?? ''),
                (string) ($host['visibleName'] ?? ''),
                $host['snmpIps'] ?? [],
                $host['anyIps']  ?? [],
                null
            );
        }
        catch (RConfigAmbiguousMatchException $e) {
            $this->respondEmpty('ambiguous');
            return;
        }
        catch (Throwable $e) {
            $msg = $e->getMessage();
            if (stripos($msg, 'multiple rConfig devices match') !== false) {
                $this->respondEmpty('ambiguous');
                return;
            }
            if (stripos($msg, 'no rConfig device matches') !== false
             || stripos($msg, 'no interface ip') !== false) {
                $this->respondEmpty('not_found');
                return;
            }
            $this->respondEmpty('down');
            return;
        }

        if ($deviceId <= 0) {
            $this->respondEmpty('not_found');
            return;
        }

        // Backup info, cached.
        $cacheKey = 'closet_inv:rconfig_device:'.$deviceId;
        $info = null;
        $hit = Cache::get($cacheKey);
        if ($hit !== null) {
            $decoded = json_decode($hit, true);
            if (is_array($decoded)) $info = $decoded;
        }
        if ($info === null) {
            try {
                $info = $client->getDeviceConfigBackupInfo($deviceId);
                Cache::set($cacheKey, (string) json_encode($info), self::TTL_DEVICE);
            }
            catch (Throwable $e) {
                $this->respondEmpty('down');
                return;
            }
        }

        $this->setResponse(new CControllerResponseData([
            'main_block' => json_encode([
                'ok'     => true,
                'device' => [
                    'id'                => (int)    ($info['id']                ?? $deviceId),
                    'name'              => (string) ($info['name']              ?? ''),
                    'lastBackupAt'      =>           $info['lastBackupAt']      ?? null,
                    'lastBackupAgeDays' =>           $info['lastBackupAgeDays'] ?? null
                ],
                'source' => 'ok'
            ])
        ]));
    }

    /**
     * Build the Zabbix host descriptor the RConfigClient needs:
     *   { hostid, hostname, visibleName, snmpIps[], anyIps[] }
     *
     * @return array<string, mixed>|null
     */
    private function resolveZabbixHost(string $hostid, string $hostname): ?array {
        $get = [
            'output'           => ['hostid', 'host', 'name'],
            'selectInterfaces' => ['ip', 'main', 'type']
        ];
        if ($hostid !== '') {
            $get['hostids'] = [$hostid];
        }
        else {
            $get['search']      = ['host' => $hostname, 'name' => $hostname];
            $get['searchByAny'] = true;
            $get['limit']       = 5;
        }

        try {
            $rows = API::Host()->get($get);
        }
        catch (Throwable $e) {
            return null;
        }
        if (!is_array($rows) || $rows === []) {
            return null;
        }

        // Prefer exact match by name when more than one row came back.
        $row = $rows[0];
        if ($hostname !== '' && count($rows) > 1) {
            $needle = strtolower($hostname);
            foreach ($rows as $r) {
                if (strtolower((string) ($r['host'] ?? '')) === $needle
                 || strtolower((string) ($r['name'] ?? '')) === $needle) {
                    $row = $r;
                    break;
                }
            }
        }

        $snmpIps = [];
        $anyIps  = [];
        foreach (($row['interfaces'] ?? []) as $i) {
            if (!is_array($i)) continue;
            $ip   = (string) ($i['ip']   ?? '');
            $type = (int)    ($i['type'] ?? 0);
            if ($ip === '' || $ip === '0.0.0.0') continue;
            $anyIps[] = $ip;
            if ($type === 2) {  // INTERFACE_TYPE_SNMP
                $snmpIps[] = $ip;
            }
        }

        return [
            'hostid'      => (string) ($row['hostid'] ?? ''),
            'hostname'    => (string) ($row['host']   ?? ''),
            'visibleName' => (string) ($row['name']   ?? ''),
            'snmpIps'     => array_values(array_unique($snmpIps)),
            'anyIps'      => array_values(array_unique($anyIps))
        ];
    }

    private function respondEmpty(string $source): void {
        $this->setResponse(new CControllerResponseData([
            'main_block' => json_encode([
                'ok'     => true,
                'device' => null,
                'source' => $source
            ])
        ]));
    }
}
