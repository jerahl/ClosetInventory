<?php declare(strict_types=1);

namespace Modules\ClosetInventory\Lib;

use CWebUser;

/**
 * CRUD for closet_inventory's authored tables. All access goes through
 * Zabbix's DB helpers so we share the configured connection / transaction
 * model.
 *
 * Live operational data (Zabbix port state, XIQ telemetry, rConfig backups)
 * never lives in here — that's the enrichment layer, wired in Phase 2+.
 */
class InventoryStore {

    /**
     * Raw INSERT for a custom table not registered in Zabbix's schema metadata.
     * Returns the LAST_INSERT_ID for AUTO_INCREMENT tables, or 0 for tables
     * with a non-auto primary key (caller knows what to expect).
     *
     * @param array<string, mixed> $row column => value pairs
     */
    private function dbInsert(string $table, array $row): int {
        $cols = [];
        $vals = [];
        foreach ($row as $col => $val) {
            $cols[] = $col;
            $vals[] = self::sqlLiteral($val);
        }
        \DBexecute('INSERT INTO ' . $table . ' (' . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ')');
        $row = \DBfetch(\DBselect('SELECT LAST_INSERT_ID() AS id'));
        return $row !== false ? (int)$row['id'] : 0;
    }

    /**
     * Raw UPDATE for a custom table not registered in Zabbix's schema metadata.
     *
     * @param array<string, mixed> $values column => value pairs to SET
     * @param array<string, mixed> $where  column => value pairs to AND in WHERE
     */
    private function dbUpdate(string $table, array $values, array $where): void {
        if (empty($values) || empty($where)) {
            return;
        }
        $set = [];
        foreach ($values as $col => $val) {
            $set[] = $col . '=' . self::sqlLiteral($val);
        }
        $cond = [];
        foreach ($where as $col => $val) {
            $cond[] = $col . '=' . self::sqlLiteral($val);
        }
        \DBexecute('UPDATE ' . $table . ' SET ' . implode(',', $set) . ' WHERE ' . implode(' AND ', $cond));
    }

    private static function sqlLiteral($val): string {
        if ($val === null)            return 'NULL';
        if (is_bool($val))            return $val ? '1' : '0';
        if (is_int($val))             return (string) $val;
        if (is_float($val))           return rtrim(rtrim(sprintf('%.6F', $val), '0'), '.');
        return \zbx_dbstr((string) $val);
    }

    /** @return array<int, array<string, mixed>> */
    public function listClosets(): array {
        $sql = 'SELECT c.uid, c.code, c.type, c.school_id, c.building, c.floor, c.room,'
            .' c.flagged, c.flag_reason, c.flag_tech, c.flag_date,'
            .' c.ports_total, c.ports_used, c.updated_at,'
            .' s.name AS school_name,'
            .' (SELECT COUNT(*) FROM tcs_closet_switches sw WHERE sw.closet_uid = c.uid) AS switches_count'
            .' FROM tcs_closet_closets c'
            .' LEFT JOIN tcs_closet_schools s ON s.id = c.school_id'
            .' ORDER BY c.code ASC';

        $rs = \DBselect($sql);
        $out = [];
        while ($row = \DBfetch($rs)) {
            $out[] = [
                'uid'           => (int) $row['uid'],
                'code'          => (string) $row['code'],
                'type'          => (string) $row['type'],
                'schoolId'      => (string) $row['school_id'],
                'schoolName'    => (string) ($row['school_name'] ?? ''),
                'building'      => (string) ($row['building'] ?? ''),
                'floor'         => $row['floor'] === null ? null : (int) $row['floor'],
                'room'          => (string) ($row['room'] ?? ''),
                'flagged'       => (int) $row['flagged'] === 1,
                'flagReason'    => $row['flag_reason'] !== null ? (string) $row['flag_reason'] : null,
                'flagTech'      => $row['flag_tech']   !== null ? (string) $row['flag_tech']   : null,
                'flagDate'      => $row['flag_date']   !== null ? (string) $row['flag_date']   : null,
                'portsTotal'    => (int) $row['ports_total'],
                'portsUsed'     => (int) $row['ports_used'],
                'updated'       => $row['updated_at'] !== null ? substr((string) $row['updated_at'], 0, 10) : null,
                'switchesCount' => (int) $row['switches_count']
            ];
        }
        return $out;
    }

    /** @return array<int, array<string, mixed>> */
    public function listSchools(): array {
        $rs = \DBselect('SELECT id, name, type, zbx_group, color_hue FROM tcs_closet_schools ORDER BY name ASC');
        $out = [];
        while ($row = \DBfetch($rs)) {
            $hue = $row['color_hue'] !== null ? (int) $row['color_hue'] : 256;
            $out[] = [
                'id'       => (string) $row['id'],
                'name'     => (string) $row['name'],
                'type'     => (string) $row['type'],
                'zbxGroup' => $row['zbx_group'] !== null ? (string) $row['zbx_group'] : null,
                // Provide a CSS color pair compatible with components.jsx's
                // SchoolTag, which reads s.color.{bg,fg}.
                'color' => [
                    'bg' => "oklch(0.95 0.03 {$hue})",
                    'fg' => "oklch(0.45 0.13 {$hue})"
                ]
            ];
        }
        return $out;
    }

    /** @return array<string, mixed>|null */
    public function getCloset(int $uid): ?array {
        $row = \DBfetch(\DBselect(
            'SELECT * FROM tcs_closet_closets WHERE uid='.(int) $uid
        ));
        if ($row === false || $row === null) {
            return null;
        }

        // switches
        $switches = [];
        $rs = \DBselect('SELECT * FROM tcs_closet_switches WHERE closet_uid='.(int) $uid.' ORDER BY id ASC');
        while ($sw = \DBfetch($rs)) {
            $switches[] = [
                'id'                  => (int) $sw['id'],
                'name'                => (string) ($sw['name'] ?? ''),
                'vendor'              => (string) ($sw['vendor'] ?? ''),
                'model'               => (string) ($sw['model'] ?? ''),
                'ports'               => (int) ($sw['ports'] ?? 0),
                // 'used' is the AUTHORED used-port count. Phase 2 replaces
                // this on the fly via SwitchClient. The DB column stays so
                // a closet renders sensibly even when Zabbix is down.
                'used'                => (int) ($sw['used'] ?? 0),
                'poe'                 => (int) $sw['poe'] === 1,
                'uplinks'             => (int) ($sw['uplinks'] ?? 0),
                'uplinkSpeed'         => (string) ($sw['uplink_speed'] ?? ''),
                'mgmtIp'              => (string) ($sw['mgmt_ip'] ?? ''),
                'serial'              => (string) ($sw['serial'] ?? ''),
                'stack'               => (int) ($sw['stack_size'] ?? 1),
                'zabbixHostid'        => $sw['zabbix_hostid']     !== null ? (string) $sw['zabbix_hostid']     : null,
                'xiqDeviceId'         => $sw['xiq_device_id']     !== null ? (int)    $sw['xiq_device_id']    : null,
                'rconfigDeviceId'     => $sw['rconfig_device_id'] !== null ? (int)    $sw['rconfig_device_id'] : null,
                // Live fields — always emitted, always null in Phase 1.
                'poeStatus'           => null,
                'configBackupAgeDays' => null
            ];
        }

        // power
        $upsList = [];
        $pduList = [];
        $rs = \DBselect('SELECT * FROM tcs_closet_power_units WHERE closet_uid='.(int) $uid.' ORDER BY id ASC');
        while ($p = \DBfetch($rs)) {
            $kind = strtoupper((string) ($p['kind'] ?? ''));
            if ($kind === 'UPS') {
                $upsList[] = [
                    'model'            => (string) ($p['model'] ?? ''),
                    'va'               => $p['va']          !== null ? (int) $p['va']          : 0,
                    'loadPct'          => $p['load_pct']    !== null ? (int) $p['load_pct']    : 0,
                    'batteryHealthPct' => $p['battery_pct'] !== null ? (int) $p['battery_pct'] : 100,
                    'runtimeMin'       => $p['runtime_min'] !== null ? (int) $p['runtime_min'] : 0
                ];
            }
            else if ($kind === 'PDU') {
                $pduList[] = [
                    'model'    => (string) ($p['model'] ?? ''),
                    'outlets'  => $p['outlets']   !== null ? (int)   $p['outlets']   : 0,
                    'loadAmps' => $p['load_amps'] !== null ? (float) $p['load_amps'] : null
                ];
            }
        }

        $circuits = [];
        $rs = \DBselect('SELECT label FROM tcs_closet_circuits WHERE closet_uid='.(int) $uid.' ORDER BY id ASC');
        while ($cr = \DBfetch($rs)) {
            $circuits[] = (string) ($cr['label'] ?? '');
        }

        // maintenance (newest first to match the React timeline)
        $maintenance = [];
        $rs = \DBselect(
            'SELECT date, type, tone, tech, notes FROM tcs_closet_maintenance'
            .' WHERE closet_uid='.(int) $uid.' ORDER BY date DESC, id DESC'
        );
        while ($m = \DBfetch($rs)) {
            $maintenance[] = [
                'date'  => (string) ($m['date'] ?? ''),
                'type'  => (string) ($m['type'] ?? ''),
                'tone'  => (string) ($m['tone'] ?? 'default'),
                'tech'  => (string) ($m['tech'] ?? ''),
                'notes' => (string) ($m['notes'] ?? '')
            ];
        }

        // photos
        $photos = [];
        $rs = \DBselect('SELECT id, label, path FROM tcs_closet_photos WHERE closet_uid='.(int) $uid.' ORDER BY id ASC');
        while ($ph = \DBfetch($rs)) {
            $photos[] = [
                'id'    => (int) $ph['id'],
                'label' => (string) ($ph['label'] ?? ''),
                'path'  => (string) ($ph['path']  ?? '')
            ];
        }

        return [
            'uid'         => (int) $row['uid'],
            'code'        => (string) $row['code'],
            'type'        => (string) $row['type'],
            'schoolId'    => (string) $row['school_id'],
            'building'    => (string) ($row['building'] ?? ''),
            'floor'       => $row['floor'] === null ? null : (int) $row['floor'],
            'room'        => (string) ($row['room'] ?? ''),
            'switches'    => $switches,
            'power'       => ['upsList' => $upsList, 'pduList' => $pduList, 'circuits' => $circuits],
            'maintenance' => $maintenance,
            'photos'      => $photos,
            'portsTotal'  => (int) $row['ports_total'],
            'portsUsed'   => (int) $row['ports_used'],
            'flagged'     => (int) $row['flagged'] === 1,
            'flagReason'  => $row['flag_reason'] !== null ? (string) $row['flag_reason'] : null,
            'flagTech'    => $row['flag_tech']   !== null ? (string) $row['flag_tech']   : null,
            'flagDate'    => $row['flag_date']   !== null ? (string) $row['flag_date']   : null,
            'updated'     => $row['updated_at'] !== null ? substr((string) $row['updated_at'], 0, 10) : null,
            '_live' => [
                'sources' => [
                    'zabbix'   => 'unconfigured',
                    'xiq'      => 'unconfigured',
                    'rconfig'  => 'unconfigured'
                ],
                'xiqRateLimitRemaining' => null
            ]
        ];
    }

    /**
     * Create or update a closet. Returns the uid.
     *
     * @param array<string, mixed> $p
     */
    public function saveCloset(array $p): int {
        $now = date('Y-m-d H:i:s');
        $fields = [
            'code'       => (string) ($p['code'] ?? ''),
            'type'       => (string) ($p['type'] ?? 'IDF'),
            'school_id'  => (string) ($p['schoolId'] ?? ''),
            'building'   => isset($p['building']) ? (string) $p['building'] : null,
            'floor'      => isset($p['floor'])    ? (int)    $p['floor']    : null,
            'room'       => isset($p['room'])     ? (string) $p['room']     : null,
            'updated_at' => $now
        ];

        $uid = isset($p['uid']) && (int) $p['uid'] > 0 ? (int) $p['uid'] : 0;

        if ($uid > 0) {
            $this->dbUpdate('tcs_closet_closets', $fields, ['uid' => $uid]);
        }
        else {
            // Auto-generate code if caller didn't provide one. Match the
            // pattern App.jsx used: SCHOOL-MDF or SCHOOL-IDF-<floor><seq>.
            if ($fields['code'] === '') {
                $seq = 0;
                $row = \DBfetch(\DBselect(
                    'SELECT COUNT(*) AS n FROM tcs_closet_closets WHERE school_id='.\zbx_dbstr($fields['school_id'])
                ));
                if ($row !== false) {
                    $seq = (int) $row['n'];
                }
                $fields['code'] = $fields['type'] === 'MDF'
                    ? $fields['school_id'].'-MDF'
                    : sprintf('%s-IDF-%d%02d', $fields['school_id'], (int) ($fields['floor'] ?? 1), $seq);
            }
            $uid = $this->dbInsert('tcs_closet_closets', $fields);
        }

        $this->recomputePortCounters($uid);
        return $uid;
    }

    /**
     * Insert or update a switch row under $closetUid. Returns the switch id.
     *
     * @param array<string, mixed> $p
     */
    public function saveSwitch(int $closetUid, array $p, ?int $id): int {
        $fields = [
            'closet_uid'        => $closetUid,
            'name'              => (string) ($p['name']   ?? ''),
            'vendor'            => (string) ($p['vendor'] ?? ''),
            'model'             => (string) ($p['model']  ?? ''),
            'ports'             => (int)    ($p['ports']  ?? 0),
            'used'              => (int)    ($p['used']   ?? 0),
            'poe'               => !empty($p['poe']) ? 1 : 0,
            'uplinks'           => (int)    ($p['uplinks'] ?? 0),
            'uplink_speed'      => (string) ($p['uplinkSpeed'] ?? ''),
            'mgmt_ip'           => (string) ($p['mgmtIp']  ?? ''),
            'serial'            => (string) ($p['serial']  ?? ''),
            'stack_size'        => (int)    ($p['stack']   ?? 1),
            // External keys: array_key_exists + null-preserving so callers can
            // explicitly NULL a column (a 0 / "" coming in from the form is
            // already coerced to null by the action layer).
            'zabbix_hostid'     => (array_key_exists('zabbixHostid', $p)    && $p['zabbixHostid']    !== null && $p['zabbixHostid']    !== '') ? (string) $p['zabbixHostid']    : null,
            'xiq_device_id'     => (array_key_exists('xiqDeviceId', $p)     && $p['xiqDeviceId']     !== null && (int) $p['xiqDeviceId']     > 0) ? (int) $p['xiqDeviceId']     : null,
            'rconfig_device_id' => (array_key_exists('rconfigDeviceId', $p) && $p['rconfigDeviceId'] !== null && (int) $p['rconfigDeviceId'] > 0) ? (int) $p['rconfigDeviceId'] : null
        ];

        if ($id !== null && $id > 0) {
            $this->dbUpdate('tcs_closet_switches', $fields, ['id' => $id]);
            $out = $id;
        }
        else {
            $out = $this->dbInsert('tcs_closet_switches', $fields);
        }

        $this->recomputePortCounters($closetUid);
        return $out;
    }

    /**
     * Replace the closet's power block. The on-disk model uses one row per
     * UPS/PDU plus a label-only circuits table — wiping and reinserting keeps
     * the editor's "save the entire form" UX trivial.
     *
     * @param array<string, mixed> $p
     */
    public function savePower(int $closetUid, array $p): void {
        \DBexecute('DELETE FROM tcs_closet_power_units WHERE closet_uid='.$closetUid);
        \DBexecute('DELETE FROM tcs_closet_circuits    WHERE closet_uid='.$closetUid);

        foreach (($p['upsList'] ?? []) as $u) {
            $this->dbInsert('tcs_closet_power_units', [
                'closet_uid' => $closetUid,
                'kind'       => 'UPS',
                'model'      => (string) ($u['model'] ?? ''),
                'va'         => (int)    ($u['va']      ?? 0),
                'load_pct'   => (int)    ($u['loadPct'] ?? 0),
                'battery_pct'=> (int)    ($u['batteryHealthPct'] ?? 100),
                'runtime_min'=> (int)    ($u['runtimeMin'] ?? 0),
                'outlets'    => null,
                'load_amps'  => null
            ]);
        }

        foreach (($p['pduList'] ?? []) as $d) {
            $amps = $d['loadAmps'] ?? null;
            $this->dbInsert('tcs_closet_power_units', [
                'closet_uid' => $closetUid,
                'kind'       => 'PDU',
                'model'      => (string) ($d['model'] ?? ''),
                'va'         => null,
                'load_pct'   => null,
                'battery_pct'=> null,
                'runtime_min'=> null,
                'outlets'    => (int) ($d['outlets'] ?? 0),
                'load_amps'  => ($amps === null || $amps === '') ? null : (float) $amps
            ]);
        }

        foreach (($p['circuits'] ?? []) as $label) {
            $label = trim((string) $label);
            if ($label === '') continue;
            $this->dbInsert('tcs_closet_circuits', [
                'closet_uid' => $closetUid,
                'label'      => $label
            ]);
        }

        $this->touchUpdatedAt($closetUid);
    }

    /**
     * Append a maintenance row. Returns the new id.
     *
     * @param array<string, mixed> $p
     */
    public function appendMaintenance(int $closetUid, array $p): int {
        $newId = $this->dbInsert('tcs_closet_maintenance', [
            'closet_uid' => $closetUid,
            'date'       => (string) ($p['date'] ?? date('Y-m-d')),
            'type'       => (string) ($p['type'] ?? ''),
            'tone'       => (string) ($p['tone'] ?? 'default'),
            'tech'       => (string) ($p['tech'] ?? ''),
            'notes'      => (string) ($p['notes'] ?? '')
        ]);
        $this->touchUpdatedAt($closetUid);
        return $newId;
    }

    /**
     * Flag set/clear. When clearing we also append a resolution row to the
     * maintenance timeline so the audit story is intact.
     *
     * @param array<string, mixed> $p
     */
    public function setFlag(int $closetUid, array $p): void {
        $flagged = !empty($p['flagged']);
        $before  = \DBfetch(\DBselect('SELECT code, flag_reason FROM tcs_closet_closets WHERE uid='.$closetUid));
        $beforeReason = ($before !== false && isset($before['flag_reason'])) ? (string) $before['flag_reason'] : '';

        $this->dbUpdate('tcs_closet_closets', [
            'flagged'     => $flagged ? 1 : 0,
            'flag_reason' => $flagged ? (string) ($p['reason'] ?? '') : null,
            'flag_tech'   => $flagged ? (string) ($p['tech']   ?? '') : null,
            'flag_date'   => $flagged ? (string) ($p['date']   ?? date('Y-m-d')) : null,
            'updated_at'  => date('Y-m-d H:i:s')
        ], ['uid' => $closetUid]);

        if (!$flagged) {
            $this->appendMaintenance($closetUid, [
                'date'  => date('Y-m-d'),
                'type'  => 'Service flag resolved',
                'tone'  => 'default',
                'tech'  => (string) ($p['tech'] ?? ''),
                'notes' => 'Resolved: '.($beforeReason !== '' ? $beforeReason : 'issue addressed.')
            ]);
        }

        $this->audit(
            $flagged ? 'flag.raise' : 'flag.resolve',
            'closet:'.$closetUid,
            $flagged ? (string) ($p['reason'] ?? '') : 'resolved'
        );
    }

    /**
     * Photo metadata insert. The on-disk file is written by ActionPhotoUpload
     * before this is called.
     */
    public function recordPhoto(int $closetUid, string $label, string $path): int {
        $newId = $this->dbInsert('tcs_closet_photos', [
            'closet_uid' => $closetUid,
            'label'      => $label,
            'path'       => $path
        ]);
        $this->touchUpdatedAt($closetUid);
        return $newId;
    }

    /**
     * One-shot seeder. No-op if any closet rows already exist so a re-run
     * never clobbers operator edits.
     *
     * @param array<int, array<string, mixed>> $closets
     * @param array<int, array<string, mixed>> $schools
     */
    public function seedFromArray(array $closets, array $schools): void {
        $row = \DBfetch(\DBselect('SELECT COUNT(*) AS n FROM tcs_closet_closets'));
        $existing = ($row !== false) ? (int) $row['n'] : 0;
        if ($existing > 0) {
            return;
        }

        foreach ($schools as $s) {
            // INSERT IGNORE — schools may already be seeded by a previous run
            // that aborted before any closet rows landed.
            $exists = \DBfetch(\DBselect(
                'SELECT id FROM tcs_closet_schools WHERE id='.\zbx_dbstr((string) $s['id'])
            ));
            if ($exists !== false) {
                continue;
            }
            $this->dbInsert('tcs_closet_schools', [
                'id'        => (string) $s['id'],
                'name'      => (string) $s['name'],
                'type'      => (string) ($s['type'] ?? ''),
                'zbx_group' => isset($s['zbxGroup']) ? (string) $s['zbxGroup'] : null,
                'color_hue' => isset($s['colorHue']) ? (int)    $s['colorHue'] : null
            ]);
        }

        foreach ($closets as $c) {
            $uid = $this->saveCloset($c);
            foreach (($c['switches'] ?? []) as $sw) {
                $this->saveSwitch($uid, $sw, null);
            }
            if (!empty($c['power'])) {
                $this->savePower($uid, $c['power']);
            }
            foreach (($c['maintenance'] ?? []) as $m) {
                $this->appendMaintenance($uid, $m);
            }
            if (!empty($c['flagged'])) {
                $this->setFlag($uid, [
                    'flagged' => true,
                    'reason'  => (string) ($c['flagReason'] ?? ''),
                    'tech'    => (string) ($c['flagTech']   ?? ''),
                    'date'    => (string) ($c['flagDate']   ?? date('Y-m-d'))
                ]);
            }
        }
    }

    /**
     * Insert-only school upsert for the bulk importer. New rows get the full
     * payload; existing rows only have `zbx_group` refreshed (so a renamed
     * Zabbix group lights up here without clobbering operator-edited name /
     * type / color_hue). Never deletes.
     *
     * @param array<string, mixed> $payload  expects name, type, zbxGroup, colorHue
     * @return array{id:string, created:bool}
     */
    public function upsertSchool(string $id, array $payload): array {
        $id = trim($id);
        if ($id === '') {
            return ['id' => '', 'created' => false];
        }
        $existing = \DBfetch(\DBselect(
            'SELECT id, zbx_group FROM tcs_closet_schools WHERE id='.\zbx_dbstr($id)
        ));
        if ($existing !== false && $existing !== null) {
            $newGroup = isset($payload['zbxGroup']) ? (string) $payload['zbxGroup'] : null;
            if ($newGroup !== null && $newGroup !== '' && (string) ($existing['zbx_group'] ?? '') !== $newGroup) {
                $this->dbUpdate('tcs_closet_schools', ['zbx_group' => $newGroup], ['id' => $id]);
            }
            return ['id' => $id, 'created' => false];
        }

        $this->dbInsert('tcs_closet_schools', [
            'id'        => $id,
            'name'      => (string) ($payload['name'] ?? $id),
            'type'      => (string) ($payload['type'] ?? 'Elem'),
            'zbx_group' => isset($payload['zbxGroup']) ? (string) $payload['zbxGroup'] : null,
            'color_hue' => isset($payload['colorHue']) ? (int) $payload['colorHue'] : null
        ]);
        return ['id' => $id, 'created' => true];
    }

    /**
     * Idempotent closet upsert keyed on `code`. Inserts a new row when missing;
     * for an existing row, only sets the non-null fields supplied in $payload
     * (so authored-only fields like flagged / flag_reason / counters stay
     * untouched). Never deletes.
     *
     * @param array<string, mixed> $payload  may include type, schoolId, building, floor, room
     * @return array{uid:int, created:bool}
     */
    public function upsertCloset(string $code, array $payload): array {
        $code = trim($code);
        if ($code === '') {
            return ['uid' => 0, 'created' => false];
        }
        $row = \DBfetch(\DBselect(
            'SELECT uid FROM tcs_closet_closets WHERE code='.\zbx_dbstr($code)
        ));
        $now = date('Y-m-d H:i:s');

        if ($row !== false && $row !== null) {
            $uid = (int) $row['uid'];
            $updates = [];
            $map = [
                'type'      => 'type',
                'schoolId'  => 'school_id',
                'building'  => 'building',
                'floor'     => 'floor',
                'room'      => 'room'
            ];
            foreach ($map as $in => $col) {
                if (array_key_exists($in, $payload) && $payload[$in] !== null && $payload[$in] !== '') {
                    $updates[$col] = $in === 'floor' ? (int) $payload[$in] : (string) $payload[$in];
                }
            }
            if ($updates !== []) {
                $updates['updated_at'] = $now;
                $this->dbUpdate('tcs_closet_closets', $updates, ['uid' => $uid]);
            }
            return ['uid' => $uid, 'created' => false];
        }

        $fields = [
            'code'       => $code,
            'type'       => (string) ($payload['type']     ?? 'IDF'),
            'school_id'  => (string) ($payload['schoolId'] ?? ''),
            'building'   => isset($payload['building']) ? (string) $payload['building'] : null,
            'floor'      => isset($payload['floor'])    ? (int)    $payload['floor']    : null,
            'room'       => isset($payload['room'])     ? (string) $payload['room']     : null,
            'updated_at' => $now
        ];
        $newUid = $this->dbInsert('tcs_closet_closets', $fields);
        return ['uid' => $newUid, 'created' => true];
    }

    /**
     * Idempotent switch upsert keyed on `(closet_uid, name)`. Inserts a new row
     * when missing; for an existing row only sets the non-null fields supplied
     * in $payload — specifically used by the bulk importer to attach
     * `zabbix_hostid` and `mgmt_ip` to switches that were authored by hand.
     * Never deletes.
     *
     * @param array<string, mixed> $payload
     * @return array{id:int, created:bool}
     */
    public function upsertSwitch(int $closetUid, string $name, array $payload): array {
        $name = trim($name);
        if ($closetUid <= 0 || $name === '') {
            return ['id' => 0, 'created' => false];
        }
        $row = \DBfetch(\DBselect(
            'SELECT id FROM tcs_closet_switches WHERE closet_uid='.$closetUid
            .' AND name='.\zbx_dbstr($name)
        ));
        if ($row !== false && $row !== null) {
            $id = (int) $row['id'];
            $updates = [];
            $map = [
                'vendor'          => 'vendor',
                'model'           => 'model',
                'ports'           => 'ports',
                'poe'             => 'poe',
                'uplinks'         => 'uplinks',
                'uplinkSpeed'     => 'uplink_speed',
                'mgmtIp'          => 'mgmt_ip',
                'serial'          => 'serial',
                'stack'           => 'stack_size',
                'zabbixHostid'    => 'zabbix_hostid',
                'xiqDeviceId'     => 'xiq_device_id',
                'rconfigDeviceId' => 'rconfig_device_id'
            ];
            foreach ($map as $in => $col) {
                if (!array_key_exists($in, $payload)) continue;
                $v = $payload[$in];
                if ($v === null || $v === '') continue;
                if ($in === 'poe')             { $updates[$col] = $v ? 1 : 0; }
                elseif ($in === 'ports'
                     || $in === 'uplinks'
                     || $in === 'stack'
                     || $in === 'xiqDeviceId'
                     || $in === 'rconfigDeviceId') {
                    $updates[$col] = (int) $v;
                }
                else { $updates[$col] = (string) $v; }
            }
            if ($updates !== []) {
                $this->dbUpdate('tcs_closet_switches', $updates, ['id' => $id]);
                $this->touchUpdatedAt($closetUid);
            }
            return ['id' => $id, 'created' => false];
        }

        $fields = [
            'closet_uid'        => $closetUid,
            'name'              => $name,
            'vendor'            => (string) ($payload['vendor'] ?? ''),
            'model'             => (string) ($payload['model']  ?? ''),
            'ports'             => (int)    ($payload['ports']  ?? 0),
            'used'              => 0,
            'poe'               => !empty($payload['poe']) ? 1 : 0,
            'uplinks'           => (int)    ($payload['uplinks'] ?? 0),
            'uplink_speed'      => (string) ($payload['uplinkSpeed'] ?? ''),
            'mgmt_ip'           => (string) ($payload['mgmtIp']  ?? ''),
            'serial'            => (string) ($payload['serial']  ?? ''),
            'stack_size'        => (int)    ($payload['stack']   ?? 1),
            'zabbix_hostid'     => (isset($payload['zabbixHostid']) && $payload['zabbixHostid'] !== '' && $payload['zabbixHostid'] !== null) ? (string) $payload['zabbixHostid'] : null,
            'xiq_device_id'     => (isset($payload['xiqDeviceId'])     && (int) $payload['xiqDeviceId']     > 0) ? (int) $payload['xiqDeviceId']     : null,
            'rconfig_device_id' => (isset($payload['rconfigDeviceId']) && (int) $payload['rconfigDeviceId'] > 0) ? (int) $payload['rconfigDeviceId'] : null
        ];
        $newId = $this->dbInsert('tcs_closet_switches', $fields);
        $this->recomputePortCounters($closetUid);
        return ['id' => $newId, 'created' => true];
    }

    /**
     * Recompute ports_total/ports_used by summing the switches table. The
     * list view reads these straight off the closet row so we don't fan
     * out per closet.
     */
    public function recomputePortCounters(int $closetUid): void {
        $row = \DBfetch(\DBselect(
            'SELECT COALESCE(SUM(ports),0) AS tot, COALESCE(SUM(used),0) AS used'
            .' FROM tcs_closet_switches WHERE closet_uid='.$closetUid
        ));
        $tot  = $row !== false ? (int) $row['tot']  : 0;
        $used = $row !== false ? (int) $row['used'] : 0;

        $this->dbUpdate('tcs_closet_closets', [
            'ports_total' => $tot,
            'ports_used'  => $used,
            'updated_at'  => date('Y-m-d H:i:s')
        ], ['uid' => $closetUid]);
    }

    /**
     * Write Zabbix-enriched port counters back to the closet row. Used by
     * ActionCountersRefresh so the list view's cached ports_total /
     * ports_used reflect live state without forcing every list render to
     * fan out to Zabbix.
     */
    public function updateCounters(int $uid, int $total, int $used): void {
        $this->dbUpdate('tcs_closet_closets', [
            'ports_total' => $total,
            'ports_used'  => $used,
            'updated_at'  => date('Y-m-d H:i:s')
        ], ['uid' => $uid]);
    }

    private function touchUpdatedAt(int $closetUid): void {
        $this->dbUpdate('tcs_closet_closets', [
            'updated_at' => date('Y-m-d H:i:s')
        ], ['uid' => $closetUid]);
    }

    public function audit(string $action, string $target, string $result): void {
        try {
            $this->dbInsert('tcs_closet_audit', [
                'ts'     => date('Y-m-d H:i:s'),
                'userid' => (string) (CWebUser::$data['userid'] ?? '0'),
                'action' => $action,
                'target' => $target,
                'result' => $result
            ]);
        }
        catch (\Throwable $e) {
            // Auditing must never break the actual write.
        }
    }
}
