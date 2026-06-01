<?php declare(strict_types=1);

namespace Modules\ClosetInventory\Actions;

use API;
use CControllerResponseData;
use Modules\ClosetInventory\Lib\Config;
use Modules\ClosetInventory\Lib\DebugLog;
use Modules\ClosetInventory\Lib\XIQClient;
use Modules\ClosetInventory\Lib\XIQFleetClient;
use Modules\ClosetInventory\Lib\XIQRateLimitException;
use Throwable;

/**
 * GET zabbix.php?action=closet.xiq.device.data&id=12345
 *                                              &hostname=RHS-IDF-SW1
 *                                              &mac=aa:bb:cc:dd:ee:ff
 *                                              &serial=FOC2340XYZ
 *
 * Exactly one of id|hostname|mac|serial must be provided.
 *
 * For non-id lookups we walk the cached fleet list locally so we don't burn a
 * per-device API call when the user is just typing a hostname.
 *
 * Adds a best-effort `zabbixHostidGuess` by Host.search()-ing the hostname.
 */
class ActionXiqDeviceData extends ActionDataBase {

    protected function checkInput(): bool {
        return $this->validateInput([
            'id'       => 'int32',
            'hostname' => 'string',
            'mac'      => 'string',
            'serial'   => 'string',
        ]);
    }

    protected function doAction(): void {
        $id       = (int)    $this->getInput('id', 0);
        $hostname = trim((string) $this->getInput('hostname', ''));
        $mac      = trim((string) $this->getInput('mac', ''));
        $serial   = trim((string) $this->getInput('serial', ''));

        $given = (int) ($id > 0) + (int) ($hostname !== '') + (int) ($mac !== '') + (int) ($serial !== '');
        if ($given !== 1) {
            http_response_code(400);
            $this->setResponse(new CControllerResponseData([
                'main_block' => json_encode([
                    'ok'    => false,
                    'error' => 'provide exactly one of: id, hostname, mac, serial'
                ])
            ]));
            return;
        }

        $token = Config::xiqToken();
        if ($token === null) {
            $this->respondEmpty('unconfigured');
            return;
        }

        try {
            $client = XIQClient::fromToken($token);
            $fleet  = XIQFleetClient::fromToken($token);
        }
        catch (Throwable $e) {
            DebugLog::log('ActionXiqDeviceData.initFail', ['error' => $e->getMessage()]);
            $this->respondEmpty('down');
            return;
        }

        $row = null;
        try {
            if ($id > 0) {
                $device = $client->getDevice($id);
                $row    = self::shapeFromNormalised($device);
            }
            else {
                // Lookup by hostname / mac / serial — use the cached fleet.
                $devices = $fleet->getDevices();
                $row     = self::findInFleet($devices, $hostname, $mac, $serial);
            }
        }
        catch (XIQRateLimitException $e) {
            $this->respondEmpty('rate_limited');
            return;
        }
        catch (Throwable $e) {
            DebugLog::log('ActionXiqDeviceData.lookupFail', ['error' => $e->getMessage()]);
            $this->respondEmpty('down');
            return;
        }

        if ($row === null) {
            // No match — still a successful round trip; just no device.
            $this->setResponse(new CControllerResponseData([
                'main_block' => json_encode([
                    'ok'     => true,
                    'device' => null,
                    'source' => 'ok',
                ])
            ]));
            return;
        }

        $row['zabbixHostidGuess'] = self::guessZabbixHostid((string) $row['hostname']);

        $this->setResponse(new CControllerResponseData([
            'main_block' => json_encode([
                'ok'     => true,
                'device' => $row,
                'source' => 'ok',
            ])
        ]));
    }

    /**
     * Best-effort: if exactly one Zabbix host matches the hostname, return it.
     *
     * @return string|null  hostid as string, or null
     */
    private static function guessZabbixHostid(string $hostname): ?string {
        $hostname = trim($hostname);
        if ($hostname === '') return null;
        try {
            $rows = API::Host()->get([
                'output' => ['hostid', 'name'],
                'search' => ['name' => $hostname],
                'limit'  => 2,
            ]);
            if (is_array($rows) && count($rows) === 1 && isset($rows[0]['hostid'])) {
                return (string) $rows[0]['hostid'];
            }
        }
        catch (Throwable $e) {
            return null;
        }
        return null;
    }

    /**
     * Convert XIQClient::getDevice() output (already normalised) to the
     * dashboard row shape used by the fleet endpoint.
     *
     * @param array<string,mixed> $d
     * @return array<string,mixed>
     */
    private static function shapeFromNormalised(array $d): array {
        $lastConnect = (int) ($d['last_connect'] ?? 0);
        return [
            'id'              => (int)    ($d['id'] ?? 0),
            'hostname'        => (string) ($d['hostname'] ?? ''),
            'model'           => (string) ($d['model'] ?? ''),
            'serial'          => (string) ($d['serial'] ?? ''),
            'mgmtIp'          => (string) ($d['ip'] ?? ''),
            'macAddress'      => (string) ($d['mac'] ?? ''),
            'connected'       => (bool)   ($d['connected'] ?? false),
            'lastSeen'        => $lastConnect > 0 ? gmdate('c', $lastConnect) : null,
            'policy'          => (string) ($d['network_policy_name'] ?? ''),
            'softwareVersion' => (string) ($d['firmware'] ?? ''),
            'function'        => (string) ($d['function'] ?? ''),
        ];
    }

    /**
     * Linear scan of the cached fleet. Hostname compare is case-insensitive;
     * MAC compare normalises to bare uppercase hex on both sides.
     *
     * @param array<int, array<string,mixed>> $devices  raw rows from XIQFleetClient::getDevices()
     * @return array<string,mixed>|null
     */
    private static function findInFleet(array $devices, string $hostname, string $mac, string $serial): ?array {
        $needleHostname = strtolower($hostname);
        $needleMac      = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $mac) ?? '');
        $needleSerial   = trim($serial);

        foreach ($devices as $r) {
            if (!is_array($r)) continue;

            if ($needleHostname !== '' && strtolower((string) ($r['hostname'] ?? '')) === $needleHostname) {
                return ActionXiqFleetData::shapeFleetRow($r);
            }
            if ($needleMac !== '') {
                $rowMac = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', (string) ($r['mac_address'] ?? '')) ?? '');
                if ($rowMac !== '' && $rowMac === $needleMac) {
                    return ActionXiqFleetData::shapeFleetRow($r);
                }
            }
            if ($needleSerial !== '' && (string) ($r['serial_number'] ?? '') === $needleSerial) {
                return ActionXiqFleetData::shapeFleetRow($r);
            }
        }
        return null;
    }

    private function respondEmpty(string $source): void {
        $this->setResponse(new CControllerResponseData([
            'main_block' => json_encode([
                'ok'     => true,
                'device' => null,
                'source' => $source,
            ])
        ]));
    }
}
