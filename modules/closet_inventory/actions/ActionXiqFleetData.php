<?php declare(strict_types=1);

namespace Modules\ClosetInventory\Actions;

use CControllerResponseData;
use Modules\ClosetInventory\Lib\Config;
use Modules\ClosetInventory\Lib\DebugLog;
use Modules\ClosetInventory\Lib\XIQClient;
use Modules\ClosetInventory\Lib\XIQFleetClient;
use Modules\ClosetInventory\Lib\XIQRateLimitException;
use Throwable;

/**
 * GET zabbix.php?action=closet.xiq.fleet.data
 *
 * Returns a normalized list of XIQ devices for the Add-Switch autofill modal.
 *
 * Source values: "ok" | "unconfigured" | "down" | "rate_limited"
 * On any failure mode other than "ok", devices is [] but the response stays
 * HTTP 200 with ok:true so the UI degrades gracefully.
 */
class ActionXiqFleetData extends ActionDataBase {

    protected function checkInput(): bool {
        return $this->validateInput([]);
    }

    protected function doAction(): void {
        $token = Config::xiqToken();
        if ($token === null) {
            $this->respond([], 'unconfigured', null, null);
            return;
        }

        try {
            $fleet  = XIQFleetClient::fromToken($token);
            $client = XIQClient::fromToken($token);
        }
        catch (Throwable $e) {
            DebugLog::log('ActionXiqFleetData.initFail', ['error' => $e->getMessage()]);
            $this->respond([], 'down', null, null);
            return;
        }

        try {
            $raw = $fleet->getDevices();
        }
        catch (XIQRateLimitException $e) {
            $this->respond([], 'rate_limited', $fleet->getRateLimitRemaining(), $fleet->getRateLimitReset());
            return;
        }
        catch (Throwable $e) {
            DebugLog::log('ActionXiqFleetData.fetchFail', ['error' => $e->getMessage()]);
            $this->respond([], 'down', $fleet->getRateLimitRemaining(), $fleet->getRateLimitReset());
            return;
        }

        $devices = [];
        foreach ((array) $raw as $r) {
            if (!is_array($r)) continue;
            $devices[] = self::shapeFleetRow($r);
        }

        DebugLog::log('ActionXiqFleetData.return', [
            'count'  => count($devices),
            'source' => 'ok',
        ]);

        $this->respond($devices, 'ok', $fleet->getRateLimitRemaining(), $fleet->getRateLimitReset());
    }

    /**
     * Normalize a raw /devices?views=BASIC row to the dashboard row shape used
     * by both the fleet and the single-device endpoint.
     *
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    public static function shapeFleetRow(array $r): array {
        $macRaw = (string) ($r['mac_address'] ?? '');
        $mac    = $macRaw !== '' ? XIQClient::macInsertColons($macRaw) : '';

        $lastConnect = $r['last_connect_time'] ?? $r['last_connect_time_ms'] ?? $r['last_connect'] ?? 0;
        $lastConnectSec = self::normaliseUnixTime($lastConnect);

        return [
            'id'              => (int)    ($r['id'] ?? 0),
            'hostname'        => (string) ($r['hostname'] ?? ''),
            'model'           => (string) ($r['product_type'] ?? ''),
            'serial'          => (string) ($r['serial_number'] ?? ''),
            'mgmtIp'          => (string) ($r['ip_address'] ?? ''),
            'macAddress'      => $mac,
            'connected'       => (bool)   ($r['connected'] ?? false),
            'lastSeen'        => $lastConnectSec > 0 ? gmdate('c', $lastConnectSec) : null,
            'policy'          => (string) ($r['network_policy_name'] ?? $r['policy_name'] ?? ''),
            'softwareVersion' => (string) ($r['software_version'] ?? $r['firmware_version'] ?? ''),
            'function'        => (string) ($r['device_function'] ?? ''),
        ];
    }

    /**
     * Mirror of XIQClient::normaliseUnixTime — copy here so the action layer
     * doesn't need to reach into the client's private helpers. Same > 1e10
     * heuristic for "ms vs s" the client uses.
     */
    private static function normaliseUnixTime($v): int {
        if (!is_numeric($v)) return 0;
        $n = (int) $v;
        if ($n <= 0) return 0;
        return $n > 10_000_000_000 ? (int) ($n / 1000) : $n;
    }

    private function respond(array $devices, string $source, ?int $remaining, ?int $resetAt): void {
        $this->setResponse(new CControllerResponseData([
            'main_block' => json_encode([
                'ok'        => true,
                'devices'   => $devices,
                'rateLimit' => [
                    'remaining' => $remaining,
                    'resetAt'   => $resetAt && $resetAt > 0 ? gmdate('c', $resetAt) : null,
                ],
                'source'    => $source,
            ])
        ]));
    }
}
