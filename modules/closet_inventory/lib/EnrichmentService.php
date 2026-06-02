<?php declare(strict_types=1);

namespace Modules\ClosetInventory\Lib;

use API;
use Throwable;

// XIQ exception classes live inside XIQClient.php in this same namespace.

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

    /** XIQ per-device cache TTL (seconds). */
    private const TTL_XIQ_DEVICE = 300;

    /** rConfig per-device cache TTL (seconds). */
    private const TTL_RCONFIG_DEVICE = 300;

    /** Server inventory cache TTL (seconds). */
    private const TTL_SERVER_INV = 300;

    private Cache $cache;

    /** Lazy-built XIQ clients; null when no credentials are configured. */
    private ?XIQClient $xiqClient = null;
    private ?XIQFleetClient $xiqFleet = null;
    private bool $xiqInitTried = false;
    /** True once the current enrich() pass caught a 429. */
    private bool $xiqRateLimitTripped = false;

    /** Lazy-built rConfig client; null when {$RCONFIG.URL}/{$RCONFIG.TOKEN} unset. */
    private ?RConfigClient $rconfigClient = null;
    private bool $rconfigInitTried = false;

    public function __construct(?Cache $cache = null) {
        // Cache is a static facade today; the param exists so tests can swap.
        $this->cache = $cache ?? new Cache();
    }

    /**
     * Build the XIQ clients on first use. Token preferred, credentials are a
     * fallback. Returns true when at least one client was constructed.
     */
    private function ensureXiqClients(): bool {
        if ($this->xiqInitTried) {
            return $this->xiqClient !== null;
        }
        $this->xiqInitTried = true;

        try {
            $token = Config::xiqToken();
            if ($token !== null) {
                $this->xiqClient = XIQClient::fromToken($token);
                $this->xiqFleet  = XIQFleetClient::fromToken($token);
                return true;
            }
            $creds = Config::xiqCredentials();
            if ($creds !== null) {
                $this->xiqClient = XIQClient::fromCredentials($creds['username'], $creds['password']);
                // XIQFleetClient is token-only; with credentials we leave it
                // null and the fleet endpoints will report "unconfigured".
                return true;
            }
        }
        catch (Throwable $e) {
            $this->xiqClient = null;
            $this->xiqFleet  = null;
        }
        return $this->xiqClient !== null;
    }

    /**
     * Build the rConfig client on first use. Returns null when either
     * {$RCONFIG.URL} or {$RCONFIG.TOKEN} is missing.
     */
    private function ensureRconfigClient(): ?RConfigClient {
        if ($this->rconfigInitTried) {
            return $this->rconfigClient;
        }
        $this->rconfigInitTried = true;

        $url   = Config::rconfigUrl();
        $token = Config::rconfigToken();
        if ($url === null || $token === null) {
            return null;
        }
        try {
            $this->rconfigClient = new RConfigClient($url, $token);
        }
        catch (Throwable $e) {
            $this->rconfigClient = null;
        }
        return $this->rconfigClient;
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

            $deviceType = strtolower((string) ($sw['deviceType'] ?? 'switch'));
            if ($deviceType !== 'switch') {
                // Server / "other" devices don't have port/PoE/stack items —
                // fetching them just wastes a Zabbix Item.get round-trip.
                // For servers, pull a small inventory snippet instead.
                if ($deviceType === 'server') {
                    $merged = $this->mergeServerInventory($sw, $hostid);
                    if ($merged['ok']) {
                        $anySuccess = true;
                        $switches[$i] = $merged['sw'];
                    }
                    else {
                        // Non-fatal — keep the row, just don't claim success.
                        $switches[$i] = $merged['sw'];
                        if (!empty($merged['warning'])) {
                            $warnings[] = $merged['warning'];
                        }
                    }
                }
                continue;
            }

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

        // -------- XIQ pass (wrapped — must never throw out of enrich()) ----
        $xiqResult = [
            'configured'   => false,
            'any_success'  => false,
            'any_failure'  => false,
            'rate_limited' => false,
            'remaining'    => null,
            'warnings'     => []
        ];
        try {
            $xiqResult = $this->runXiqPass($switches);
            $closet['switches'] = $switches; // mergeXiqInto wrote by-ref via runXiqPass
        }
        catch (Throwable $e) {
            $xiqResult['any_failure'] = true;
            $xiqResult['warnings'][]  = 'xiq fatal: '.$e->getMessage();
        }

        // -------- rConfig pass (wrapped — must never throw out of enrich()) ----
        $rcResult = [
            'configured'  => false,
            'any_success' => false,
            'any_failure' => false,
            'warnings'    => []
        ];
        try {
            $rcResult = $this->runRconfigPass($switches, $closet);
            $closet['switches'] = $switches;
        }
        catch (Throwable $e) {
            $rcResult['any_failure'] = true;
            $rcResult['warnings'][]  = 'rconfig fatal: '.$e->getMessage();
        }

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
        elseif ($anyFailure) {
            // At least one switch's snapshot was attempted and threw — that's
            // the real "Zabbix unreachable" signal.
            $sources['zabbix'] = 'down';
        }
        else {
            // Mapped devices exist but none of them were switches we tried to
            // snapshot (e.g. closets that contain only servers / 'other'
            // devices). A failed server-inventory merge is a soft warning, not
            // a Zabbix-down condition.
            $sources['zabbix'] = 'unconfigured';
        }

        // XIQ source aggregation from runXiqPass().
        if ($xiqResult['rate_limited']) {
            $sources['xiq'] = 'rate_limited';
        }
        elseif (!$xiqResult['configured']) {
            $sources['xiq'] = 'unconfigured';
        }
        elseif ($xiqResult['any_success']) {
            $sources['xiq'] = 'ok';
        }
        elseif ($xiqResult['any_failure']) {
            $sources['xiq'] = 'down';
        }
        else {
            $sources['xiq'] = 'unconfigured';
        }

        // rConfig source aggregation.
        if (!$rcResult['configured']) {
            $sources['rconfig'] = 'unconfigured';
        }
        elseif ($rcResult['any_success']) {
            $sources['rconfig'] = 'ok';
        }
        elseif ($rcResult['any_failure']) {
            $sources['rconfig'] = 'down';
        }
        else {
            $sources['rconfig'] = 'unconfigured';
        }

        $live['sources'] = $sources;
        $live['xiqRateLimitRemaining'] = $xiqResult['remaining'];

        $allWarnings = $warnings;
        if (!empty($xiqResult['warnings'])) {
            $allWarnings = array_merge($allWarnings, $xiqResult['warnings']);
        }
        if (!empty($rcResult['warnings'])) {
            $allWarnings = array_merge($allWarnings, $rcResult['warnings']);
        }
        if ($allWarnings !== []) {
            $live['warnings'] = $allWarnings;
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
     * Pull a tiny Zabbix host inventory snippet for a server-class device
     * (os / os_full / serialno_a) and merge non-empty values onto the row.
     * Cached per host id for TTL_SERVER_INV seconds. Failures degrade
     * silently; never throws.
     *
     * @param array<string, mixed> $sw
     * @return array{ok:bool, sw:array<string, mixed>, warning?:string}
     */
    /**
     * Collapse Zabbix's `os_full` (often a 70+ char build-string tail like
     * 'Windows Server 2019 Standard 17763.1.amd64fre.rs5_release.180914-1434
     * Build 17763.8146') to the part operators actually want to see.
     * Generic enough to leave anything we don't recognise untouched and
     * length-capped so a Linux uname spew can't blow up the chip either.
     */
    private static function shortOsLabel(string $full): string {
        // Windows Server <year> [<edition>] — trim everything after the edition.
        if (preg_match('/^(Windows(?: Server)?(?: \d+)?(?:\s+(?:Standard|Datacenter|Enterprise|Essentials|Core))?)/i', $full, $m)) {
            return trim($m[1]);
        }
        // Linux: take the first segment up to a comma or the build tail
        // ("Linux 5.14.0-...", "Ubuntu 22.04.3 LTS ...").
        $first = preg_split('/\s+(?:Build|build|\#|on)\s+/', $full)[0] ?? $full;
        $first = (string) (preg_split('/[,;]/', $first)[0] ?? $first);
        return strlen($first) > 48 ? substr($first, 0, 45).'...' : $first;
    }

    private function mergeServerInventory(array $sw, string $hostid): array {
        $cacheKey = 'closet_inv:server_inv:'.$hostid;
        $inv = null;

        $hit = Cache::get($cacheKey);
        if ($hit !== null) {
            $decoded = json_decode($hit, true);
            if (is_array($decoded)) {
                $inv = $decoded;
            }
        }

        if ($inv === null) {
            try {
                $rows = API::Host()->get([
                    'output'          => ['hostid'],
                    'hostids'         => [$hostid],
                    'selectInventory' => ['os', 'os_full', 'serialno_a']
                ]) ?: [];
                $row = is_array($rows[0] ?? null) ? $rows[0] : [];
                $invRaw = is_array($row['inventory'] ?? null) ? $row['inventory'] : [];
                $inv = [
                    'os'         => (string) ($invRaw['os']         ?? ''),
                    'os_full'    => (string) ($invRaw['os_full']    ?? ''),
                    'serialno_a' => (string) ($invRaw['serialno_a'] ?? '')
                ];
                Cache::set($cacheKey, (string) json_encode($inv), self::TTL_SERVER_INV);
            }
            catch (Throwable $e) {
                return ['ok' => false, 'sw' => $sw, 'warning' => 'server inv host:'.$hostid.' '.$e->getMessage()];
            }
        }

        $osLabel = trim((string) ($inv['os_full'] ?? '')) ?: trim((string) ($inv['os'] ?? ''));
        if ($osLabel !== '') {
            $sw['osLabel'] = self::shortOsLabel($osLabel);
        }
        $serial = trim((string) ($inv['serialno_a'] ?? ''));
        if ($serial !== '' && (string) ($sw['serial'] ?? '') === '') {
            $sw['serial'] = $serial;
        }
        return ['ok' => true, 'sw' => $sw];
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

    /**
     * XIQ enrichment pass. Mutates the supplied $switches array in-place;
     * returns a result envelope so enrich() can assemble _live.sources.xiq.
     *
     * @param array<int, array<string, mixed>> $switches  passed by reference
     * @return array{configured:bool,any_success:bool,any_failure:bool,rate_limited:bool,remaining:?int,warnings:array<int,string>}
     */
    private function runXiqPass(array &$switches): array {
        $out = [
            'configured'   => false,
            'any_success'  => false,
            'any_failure'  => false,
            'rate_limited' => false,
            'remaining'    => null,
            'warnings'     => []
        ];

        // Count switches that have an XIQ device id.
        $eligible = 0;
        foreach ($switches as $sw) {
            $id = (int) ($sw['xiqDeviceId'] ?? $sw['xiq_device_id'] ?? 0);
            if ($id > 0) {
                $eligible++;
            }
        }

        if ($eligible === 0) {
            return $out;
        }

        if (!$this->ensureXiqClients() || $this->xiqClient === null) {
            // Configured-ish (the switches have xiq ids) but no creds; treat as
            // unconfigured at the source level.
            return $out;
        }

        $out['configured'] = true;
        $this->xiqRateLimitTripped = false;

        foreach ($switches as $i => $sw) {
            if ($this->xiqRateLimitTripped) {
                break;
            }
            $id = (int) ($sw['xiqDeviceId'] ?? $sw['xiq_device_id'] ?? 0);
            if ($id <= 0) continue;

            try {
                $this->mergeXiqInto($switches[$i]);
                if (!empty($switches[$i]['_xiqMergedOk'])) {
                    $out['any_success'] = true;
                    unset($switches[$i]['_xiqMergedOk']);
                }
                else {
                    // mergeXiqInto sets _xiqMergedOk on a clean call. Absent
                    // means the call threw and was caught inside.
                    if (!empty($switches[$i]['_xiqFailed'])) {
                        $out['any_failure'] = true;
                        $out['warnings'][]  = 'xiq:device:'.$id.' '.((string) ($switches[$i]['_xiqFailReason'] ?? 'down'));
                        unset($switches[$i]['_xiqFailed'], $switches[$i]['_xiqFailReason']);
                    }
                    if (!empty($switches[$i]['_xiqRateLimited'])) {
                        $out['rate_limited'] = true;
                        $this->xiqRateLimitTripped = true;
                        unset($switches[$i]['_xiqRateLimited']);
                    }
                }
            }
            catch (Throwable $e) {
                // Defensive — mergeXiqInto should not throw.
                $out['any_failure'] = true;
                $out['warnings'][]  = 'xiq:device:'.$id.' '.$e->getMessage();
            }
        }

        if ($this->xiqClient !== null) {
            try {
                $out['remaining'] = $this->xiqClient->getRateLimitRemaining();
            }
            catch (Throwable $e) {
                $out['remaining'] = null;
            }
        }

        return $out;
    }

    /**
     * Merge XIQ device data onto a single switch row. Fills blanks only;
     * never overwrites authored, non-empty values.
     *
     * Internal signaling (consumed and stripped by runXiqPass):
     *   _xiqMergedOk     bool — call succeeded; some fields may have been set.
     *   _xiqFailed       bool — call threw (non-rate-limit).
     *   _xiqFailReason   string
     *   _xiqRateLimited  bool — 429 was caught.
     *
     * @param array<string, mixed> $switch by-reference
     */
    private function mergeXiqInto(array &$switch): void {
        $id = (int) ($switch['xiqDeviceId'] ?? $switch['xiq_device_id'] ?? 0);
        if ($id <= 0) {
            return;
        }
        if ($this->xiqClient === null) {
            return;
        }

        $cacheKey = 'closet_inv:xiq_device:'.$id;
        $device   = null;

        $hit = Cache::get($cacheKey);
        if ($hit !== null) {
            $decoded = @unserialize($hit, ['allowed_classes' => false]);
            if (is_array($decoded)) {
                $device = $decoded;
            }
        }

        if ($device === null) {
            try {
                $device = $this->xiqClient->getDevice($id);
                Cache::set($cacheKey, serialize($device), self::TTL_XIQ_DEVICE);
            }
            catch (XIQRateLimitException $e) {
                $switch['_xiqRateLimited'] = true;
                return;
            }
            catch (Throwable $e) {
                $switch['_xiqFailed']     = true;
                $switch['_xiqFailReason'] = $e->getMessage();
                return;
            }
        }

        // Fill blanks only. Helper preserves any non-empty authored string/int.
        $fillIfBlank = function (string $key, $newValue) use (&$switch): bool {
            if ($newValue === null || $newValue === '' || $newValue === 0) {
                return false;
            }
            $cur = $switch[$key] ?? null;
            if ($cur === null || $cur === '' || $cur === 0) {
                $switch[$key] = $newValue;
                return true;
            }
            return false;
        };

        $filled = [];
        if ($fillIfBlank('model',  (string) ($device['model']   ?? ''))) $filled[] = 'model';
        if ($fillIfBlank('serial', (string) ($device['serial']  ?? ''))) $filled[] = 'serial';
        if ($fillIfBlank('mgmtIp', (string) ($device['ip']      ?? ''))) $filled[] = 'mgmtIp';

        // New live-only fields. These always reflect XIQ state, not authored
        // data, so we set them unconditionally when the XIQ call succeeded.
        $switch['xiqConnected'] = (bool) ($device['connected'] ?? false);
        $lastConnect = (int) ($device['last_connect'] ?? 0);
        $switch['xiqLastSeen']  = $lastConnect > 0 ? gmdate('c', $lastConnect) : null;
        $switch['xiqSoftware']  = (string) ($device['firmware'] ?? '') ?: null;

        $switch['_xiqMergedOk'] = true;
    }

    /**
     * rConfig enrichment pass. Mutates $switches in place. Acts only on
     * switches that already carry a non-null `rconfig_device_id`
     * (resolving rConfig device ids belongs to the Add-Switch lookup flow,
     * not to the per-page enrichment hot path).
     *
     * @param array<int, array<string, mixed>> $switches  by-reference
     * @param array<string, mixed>             $closet    used for context only
     * @return array{configured:bool,any_success:bool,any_failure:bool,warnings:array<int,string>}
     */
    private function runRconfigPass(array &$switches, array $closet): array {
        $out = [
            'configured'  => false,
            'any_success' => false,
            'any_failure' => false,
            'warnings'    => []
        ];

        $eligible = 0;
        foreach ($switches as $sw) {
            $id = (int) ($sw['rconfigDeviceId'] ?? $sw['rconfig_device_id'] ?? 0);
            if ($id > 0) $eligible++;
        }
        if ($eligible === 0) {
            return $out;
        }

        $client = $this->ensureRconfigClient();
        if ($client === null) {
            // Switches reference rConfig but no creds — surface as unconfigured.
            return $out;
        }
        $out['configured'] = true;

        foreach ($switches as $i => $sw) {
            $id = (int) ($sw['rconfigDeviceId'] ?? $sw['rconfig_device_id'] ?? 0);
            if ($id <= 0) continue;
            try {
                $this->mergeRconfigInto($switches[$i], $closet);
                if (!empty($switches[$i]['_rconfigMergedOk'])) {
                    $out['any_success'] = true;
                    unset($switches[$i]['_rconfigMergedOk']);
                }
                if (!empty($switches[$i]['_rconfigFailed'])) {
                    $out['any_failure'] = true;
                    $out['warnings'][]  = 'rconfig:device:'.$id.' '
                        .((string) ($switches[$i]['_rconfigFailReason'] ?? 'down'));
                    unset($switches[$i]['_rconfigFailed'], $switches[$i]['_rconfigFailReason']);
                }
            }
            catch (Throwable $e) {
                $out['any_failure'] = true;
                $out['warnings'][]  = 'rconfig:device:'.$id.' '.$e->getMessage();
            }
        }

        return $out;
    }

    /**
     * Merge rConfig backup-age info onto a single switch row. Acts only when
     * the switch carries an rConfig device id. Cached for TTL_RCONFIG_DEVICE
     * seconds per device.
     *
     * Internal signaling consumed and stripped by runRconfigPass:
     *   _rconfigMergedOk     bool — call succeeded
     *   _rconfigFailed       bool — call threw
     *   _rconfigFailReason   string
     *
     * @param array<string, mixed> $switch by-reference
     * @param array<string, mixed> $closet unused for now
     */
    private function mergeRconfigInto(array &$switch, array $closet): void {
        $id = (int) ($switch['rconfigDeviceId'] ?? $switch['rconfig_device_id'] ?? 0);
        if ($id <= 0 || $this->rconfigClient === null) {
            return;
        }

        $cacheKey = 'closet_inv:rconfig_device:'.$id;
        $info = null;
        $hit = Cache::get($cacheKey);
        if ($hit !== null) {
            $decoded = json_decode($hit, true);
            if (is_array($decoded)) {
                $info = $decoded;
            }
        }

        if ($info === null) {
            try {
                $info = $this->rconfigClient->getDeviceConfigBackupInfo($id);
                Cache::set($cacheKey, (string) json_encode($info), self::TTL_RCONFIG_DEVICE);
            }
            catch (Throwable $e) {
                $switch['_rconfigFailed']     = true;
                $switch['_rconfigFailReason'] = $e->getMessage();
                return;
            }
        }

        $age = $info['lastBackupAgeDays'] ?? null;
        if ($age !== null) {
            $switch['configBackupAgeDays'] = (int) $age;
        }
        if (!empty($info['lastBackupAt'])) {
            $switch['configBackupAt'] = (string) $info['lastBackupAt'];
        }
        $switch['_rconfigMergedOk'] = true;
    }
}
