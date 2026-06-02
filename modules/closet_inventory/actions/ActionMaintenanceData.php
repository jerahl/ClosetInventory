<?php declare(strict_types=1);

namespace Modules\ClosetInventory\Actions;

use CControllerResponseData;

/**
 * GET zabbix.php?action=closet.maintenance.data
 *
 * District-wide maintenance log. Joins tcs_closet_maintenance with closets
 * and schools so the UI can render context without N+1 lookups. All filters
 * are optional; missing values mean "unbounded".
 *
 * Always returns JSON; on failure emits {ok:false, error:...} with HTTP 500
 * so the frontend toast layer can surface the error without crashing the
 * page (per §9 of the plan).
 */
class ActionMaintenanceData extends ActionDataBase {

    /** Hard cap on row count to bound payload size. */
    private const MAX_LIMIT = 1000;
    /** Default cap when caller doesn't specify. */
    private const DEFAULT_LIMIT = 200;
    /** Cap on the distinct techs/types arrays returned for the filter UI. */
    private const FACET_CAP = 50;

    protected function checkInput(): bool {
        $fields = [
            'from'      => 'string',
            'to'        => 'string',
            'tech'      => 'string',
            'type'      => 'string',
            'closetUid' => 'int32',
            'limit'     => 'int32'
        ];
        return $this->validateInput($fields);
    }

    protected function doAction(): void {
        try {
            $from      = trim((string) $this->getInput('from', ''));
            $to        = trim((string) $this->getInput('to', ''));
            $tech      = trim((string) $this->getInput('tech', ''));
            $type      = trim((string) $this->getInput('type', ''));
            $closetUid = (int) $this->getInput('closetUid', 0);
            $limit     = (int) $this->getInput('limit', self::DEFAULT_LIMIT);
            if ($limit <= 0)                $limit = self::DEFAULT_LIMIT;
            if ($limit > self::MAX_LIMIT)   $limit = self::MAX_LIMIT;

            $where = ['1=1'];
            if ($from !== '' && self::isYmd($from)) {
                $where[] = 'm.date >= '.\zbx_dbstr($from);
            }
            if ($to !== '' && self::isYmd($to)) {
                $where[] = 'm.date <= '.\zbx_dbstr($to);
            }
            if ($tech !== '') {
                $where[] = 'm.tech = '.\zbx_dbstr($tech);
            }
            if ($type !== '') {
                $where[] = 'm.type = '.\zbx_dbstr($type);
            }
            if ($closetUid > 0) {
                $where[] = 'm.closet_uid = '.$closetUid;
            }

            $sql = 'SELECT m.id, m.closet_uid, m.date, m.type, m.tone, m.tech, m.notes,'
                .' c.code AS closet_code, c.school_id, s.name AS school_name'
                .' FROM tcs_closet_maintenance m'
                .' JOIN tcs_closet_closets c ON c.uid = m.closet_uid'
                .' LEFT JOIN tcs_closet_schools s ON s.id = c.school_id'
                .' WHERE '.implode(' AND ', $where)
                .' ORDER BY m.date DESC, m.id DESC';

            $rs = \DBselect($sql, $limit);
            $entries = [];
            $closetSet = [];
            $techSet = [];
            $typeSet = [];
            while ($row = \DBfetch($rs)) {
                $t = (string) ($row['tech'] ?? '');
                $ty = (string) ($row['type'] ?? '');
                $uid = (int) $row['closet_uid'];
                $entries[] = [
                    'id'         => (int) $row['id'],
                    'closetUid'  => $uid,
                    'closetCode' => (string) ($row['closet_code'] ?? ''),
                    'schoolId'   => (string) ($row['school_id'] ?? ''),
                    'schoolName' => (string) ($row['school_name'] ?? ''),
                    'date'       => (string) ($row['date'] ?? ''),
                    'type'       => $ty,
                    'tone'       => (string) ($row['tone'] ?? 'default'),
                    'tech'       => $t,
                    'notes'      => (string) ($row['notes'] ?? '')
                ];
                $closetSet[$uid] = true;
                if ($t  !== '') $techSet[$t]  = true;
                if ($ty !== '') $typeSet[$ty] = true;
            }

            $techs = array_keys($techSet);
            sort($techs, SORT_STRING);
            if (count($techs) > self::FACET_CAP) $techs = array_slice($techs, 0, self::FACET_CAP);

            $types = array_keys($typeSet);
            sort($types, SORT_STRING);
            if (count($types) > self::FACET_CAP) $types = array_slice($types, 0, self::FACET_CAP);

            $payload = [
                'ok'      => true,
                'entries' => $entries,
                'totals'  => [
                    'entries'       => count($entries),
                    'uniqueClosets' => count($closetSet),
                    'uniqueTechs'   => count($techSet)
                ],
                'techs'   => $techs,
                'types'   => $types
            ];

            $this->setResponse(new CControllerResponseData([
                'main_block' => json_encode($payload)
            ]));
        }
        catch (\Throwable $e) {
            http_response_code(500);
            $this->setResponse(new CControllerResponseData([
                'main_block' => json_encode(['ok' => false, 'error' => $e->getMessage()])
            ]));
        }
    }

    private static function isYmd(string $s): bool {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $s);
    }
}
