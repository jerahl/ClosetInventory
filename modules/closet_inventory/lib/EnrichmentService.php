<?php declare(strict_types=1);

namespace Modules\ClosetInventory\Lib;

use API;
use Throwable;

/**
 * Read-through enrichment layer over the authored closet record.
 *
 * Given an authored closet array from InventoryStore::getCloset(), enrich()
 * fans out to the Zabbix Item API via SwitchClient (per switch, once each),
 * merges live values (port-up count, PoE summary, stack size, mgmt IP, serial)
 * onto the row without overwriting authored values when the live value is
 * null/empty, attaches a short active-problems list, and reports per-source
 * health under _live.sources.
 *
 * Failure isolation per §9 of the plan: any external failure degrades to the
 * authored data and records "down" / a warning entry. The caller MUST still
 * receive a usable response.
 *
 * Phase 2 only handles Zabbix; XIQ and rConfig stay "unconfigured" until
 * phases 3 and 4 wire their own clients.
 */
class EnrichmentService {

    /** Snapshot cache TTL (seconds). */
    private const TTL_SNAPSHOT = 90;

    /** Problems cache TTL (seconds). */
    private const TTL_PROBLEMS = 60;

    /** Max problems surfaced per closet. */
    private const MAX_PROBLEMS = 10;

    private Cache $cache;

    public function __construct(?Cache $cache = null) {
        // Cache is a static facade today; the param exists so tests can swap.
        $this->cache = $cache ?? new Cache();
    }

    /**
     * Enrich a single closet record with live Zabbix data.
     *
     * Always returns a closet of the exact same shape. Live fields are filled
     * in when available; authored values stay put when they are not.
     *
     * @param array<string, mixed> $closet
     * @return array<string, mixed>
     */
    public function enrich(array $closet): array {
        $switches = $closet['switches'] ?? [];
        if (!is_array($switches)) {
            return $closet;
        }

        $client          = new SwitchClient();
        $anySuccess      = false;
        $anyFailure      = false;
        $anyConfigured   = false;
        $warnings        = [];

        foreach ($switches as $i => $sw) {
            $hostid = isset($sw['zabbixHostid']) ? (string) $sw['zabbixHostid'] : '';
            if ($hostid === '') {
                continue;
            }
            $anyConfigured = true;

            $snapshot = $this->snapshotFor($client, $hostid);
            if ($snapshot === null) {
                $anyFailure = true;
                $warnings[] = 'host:'.$hostid.' snapshot failed';
                continue;
            }
            $anySuccess = true;

            $switches[$i] = $this->mergeSwitch($sw, $snapshot);
        }

        $closet['switches'] = $switches;

        // Recompute closet-level counters from the (possibly updated) switch
        // rows so the design's port totals reflect the live numbers.
        $portsTotal = 0;
        $portsUsed  = 0;
        foreach ($switches as $sw) {
            $portsTotal += (int) ($sw['ports'] ?? 0);
            $portsUsed  += (int) ($sw['used']  ?? 0);
        }
        $closet['portsTotal'] = $portsTotal;
        $closet['portsUsed']  = $portsUsed;

        // _live block — preserve the Phase 1 shape and only mutate the
        // zabbix source / new problems + warnings fields.
        $live = isset($closet['_live']) && is_array($closet['_live']) ? $closet['_live'] : [];
        $sources = isset($live['sources']) && is_array($live['sources']) ? $live['sources'] : [];

        if (!$anyConfigured) {
            $sources['zabbix'] = 'unconfigured';
        }
        elseif ($anySuccess) {
            $sources['zabbix'] = 'ok';
        }
        else {
            $sources['zabbix'] = 'down';
        }

        // XIQ / rConfig stay where they were (phase 1 emits "unconfigured").
        $sources['xiq']      = $sources['xiq']      ?? 'unconfigured';
        $sources['rconfig']  = $sources['rconfig']  ?? 'unconfigured';

        $live['sources'] = $sources;
        $live['xiqRateLimitRemaining'] = $live['xiqRateLimitRemaining'] ?? null;
        if ($warnings !== []) {
            $live['warnings'] = $warnings;
        }

        // Active problems for the closet's mapped switches.
        try {
            $live['problems'] = $this->activeProblemsForCloset($closet);
        }
        catch (Throwable $e) {
            $live['problems'] = [];
            $live['warnings'] = array_merge($live['warnings'] ?? [], ['problems fetch failed']);
        }

        $closet['_live'] = $live;
        return $closet;
    }

    /**
     * Iterate enrich() over a list of closets. Used by the counter-refresh
     * action so the cached ports_total / ports_used columns reflect live
     * Zabbix counts.
     *
     * @param array<int, array<string, mixed>> $closets
     * @return array<int, array<string, mixed>>
     */
    public function enrichList(array $closets): array {
        $out = [];
        foreach ($closets as $c) {
            $out[] = $this->enrich($c);
        }
        return $out;
    }

    /**
     * Active (unresolved) problems for every switch in the closet that has a
     * Zabbix host id mapped. Returns up to MAX_PROBLEMS rows, newest first.
     * Cached per closet for TTL_PROBLEMS seconds.
     *
     * @param array<string, mixed> $closet
     * @return array<int, array<string, mixed>>
     */
    public function activeProblemsForCloset(array $closet): array {
        $hostids = [];
        foreach (($closet['switches'] ?? []) as $sw) {
            $h = isset($sw['zabbixHostid']) ? (string) $sw['zabbixHostid'] : '';
            if ($h !== '') $hostids[] = $h;
        }
        if ($hostids === []) {
            return [];
        }

        $uid = (int) ($closet['uid'] ?? 0);
        $cacheKey = 'closet_inv:problems:'.$uid;
        $hit = Cache::get($cacheKey);
        if ($hit !== null) {
            $decoded = json_decode($hit, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $rows = API::Problem()->get([
            'output'    => ['eventid', 'name', 'severity', 'clock'],
            'hostids'   => array_values(array_unique($hostids)),
            'recent'    => false,
            'sortfield' => ['eventid'],
            'sortorder' => 'DESC',
            'limit'     => self::MAX_PROBLEMS
        ]) ?: [];

        // problem.get returns the trigger host via selectHosts; we asked for
        // none to keep the call cheap. Hostid attribution stays best-effort
        // and is set from the source filter when only one host is involved.
        $singleHost = count(array_unique($hostids)) === 1 ? $hostids[0] : null;

        $out = [];
        foreach ($rows as $p) {
            $out[] = [
                'eventid'  => (string) ($p['eventid'] ?? ''),
                'severity' => (int)    ($p['severity'] ?? 0),
                'name'     => (string) ($p['name'] ?? ''),
                'hostid'   => $singleHost,
                'clock'    => (int)    ($p['clock'] ?? 0)
            ];
        }

        Cache::set($cacheKey, (string) json_encode($out), self::TTL_PROBLEMS);
        return $out;
    }

    /**
     * Cached SwitchClient::snapshot() for a single host. Returns null on
     * failure so the caller can record a "down" source without bubbling.
     *
     * @return array<string, mixed>|null
     */
    private function snapshotFor(SwitchClient $client, string $hostid): ?array {
        $key = 'closet_inv:switch_snapshot:'.$hostid;
        $hit = Cache::get($key);
        if ($hit !== null) {
            $decoded = @unserialize($hit, ['allowed_classes' => false]);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        try {
            $snap = $client->snapshot($hostid);
            Cache::set($key, serialize($snap), self::TTL_SNAPSHOT);
            return $snap;
        }
        catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Merge live snapshot values onto an authored switch row.
     *
     * Rules:
     *  - `used`         : count of ports[].label matching /up/i.
     *  - `poeStatus`    : the dominant non-Disabled/non-Searching PoE label,
     *                     preferring "DeliveringPower" when any port reports it.
     *                     Null if everything is Disabled (or PoE absent).
     *  - `stack`        : count(members) when ≥ 1, else keep authored.
     *  - `mgmtIp`       : from info block (any sensible key), else keep.
     *  - `serial`       : from info.serial, else keep.
     *
     * Authored values are never overwritten with empty / null live values.
     *
     * @param array<string, mixed> $sw
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function mergeSwitch(array $sw, array $snapshot): array {
        // Ports up: SwitchClient::extractPortStatus() emits rows whose `label`
        // is the IF-MIB ifOperStatus name ("up", "down", "notPresent", …).
        // Match anything beginning with "up" so 'up' counts but 'unknown' / 'lowerLayerDown' don't.
        $ports = is_array($snapshot['ports'] ?? null) ? $snapshot['ports'] : [];
        if ($ports !== []) {
            $used = 0;
            foreach ($ports as $p) {
                $label = strtolower((string) ($p['label'] ?? ''));
                if ($label === 'up') $used++;
            }
            $sw['used'] = $used;
        }

        // PoE summary: count labels excluding the "no PoE here" states.
        $poe = is_array($snapshot['poe'] ?? null) ? $snapshot['poe'] : [];
        $sw['poeStatus'] = $this->summarizePoe($poe);

        // Stack size from member rows.
        $members = is_array($snapshot['members'] ?? null) ? $snapshot['members'] : [];
        if (count($members) >= 1) {
            $sw['stack'] = count($members);
        }

        // Mgmt IP / serial from info block. SwitchClient::extractHostInfo()
        // only emits firmware/model/serial/version/swOs today — there is no
        // mgmtIp key, but keep the lookup tolerant in case the template adds
        // one later.
        $info = is_array($snapshot['info'] ?? null) ? $snapshot['info'] : [];
        $mgmtIp = (string) ($info['mgmtIp'] ?? $info['ip'] ?? '');
        if ($mgmtIp !== '') {
            $sw['mgmtIp'] = $mgmtIp;
        }
        $serial = (string) ($info['serial'] ?? '');
        if ($serial !== '') {
            $sw['serial'] = $serial;
        }

        return $sw;
    }

    /**
     * Reduce per-port PoE rows to a single status string. Prefer
     * "DeliveringPower" when any port is delivering; otherwise return the
     * dominant non-Disabled/non-Searching label; if everything is Disabled
     * (or PoE absent entirely), return null so the UI can hide the tag.
     *
     * The SwitchClient labels are lowercase ("disabled", "searching",
     * "delivering", "fault", "test", "otherFault"). Render with a friendly
     * camel-cased form ("DeliveringPower" / "Disabled" / …) to match the
     * mockup contract in §7.
     *
     * @param array<int, array<string, mixed>> $poe
     */
    private function summarizePoe(array $poe): ?string {
        if ($poe === []) {
            return null;
        }
        $counts = [];
        foreach ($poe as $p) {
            $label = strtolower((string) ($p['label'] ?? ''));
            if ($label === '') continue;
            $counts[$label] = ($counts[$label] ?? 0) + 1;
        }
        if ($counts === []) {
            return null;
        }
        if (($counts['delivering'] ?? 0) > 0) {
            return 'DeliveringPower';
        }
        // Strip non-informational states.
        unset($counts['disabled'], $counts['searching']);
        if ($counts === []) {
            return null;
        }
        arsort($counts);
        $dominant = (string) array_key_first($counts);
        return match ($dominant) {
            'fault'      => 'Fault',
            'test'       => 'Test',
            'otherfault' => 'OtherFault',
            default      => ucfirst($dominant)
        };
    }
}
