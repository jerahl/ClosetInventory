<?php declare(strict_types=1);

namespace Modules\ClosetInventory\Actions;

use CControllerResponseData;
use Modules\ClosetInventory\Lib\EnrichmentService;
use Modules\ClosetInventory\Lib\InventoryStore;

/**
 * GET zabbix.php?action=closet.queue.data
 *
 * Service queue payload — every authored "flagged" closet plus any closet
 * whose mapped Zabbix hosts currently have unresolved problems. The two
 * lists are disjoint: a closet that is both flagged and has live problems
 * is surfaced under "flagged" only (the authored signal is higher priority
 * and already implies a tech is in the loop).
 *
 * Failure-isolated per §9 of the plan: any Zabbix outage degrades to the
 * authored list with a warning, never a 500.
 */
class ActionServiceQueueData extends ActionDataBase {

    /** Cap on per-page live problem fan-out. */
    private const MAX_LIVE_LOOKUPS = 50;

    protected function checkInput(): bool {
        return true;
    }

    protected function doAction(): void {
        $store = new InventoryStore();

        // 1. Authored flagged closets — always available, never gated on Zabbix.
        $flagged = $this->loadFlagged();

        // 2. Live problems on mapped switches. Best-effort; collapse to empty
        //    on any hard failure but always return the flagged list.
        $withProblems = [];
        $warning = null;
        try {
            $withProblems = $this->loadWithProblems(array_column($flagged, 'uid'));
        }
        catch (\Throwable $e) {
            $warning = $e->getMessage();
        }

        $payload = [
            'ok'           => true,
            'flagged'      => $flagged,
            'withProblems' => $withProblems,
            'totals'       => [
                'flagged'        => count($flagged),
                'withProblems'   => count($withProblems),
                'uniqueClosets'  => count($flagged) + count($withProblems)
            ]
        ];
        if ($warning !== null) {
            $payload['warning'] = $warning;
        }

        $this->setResponse(new CControllerResponseData([
            'main_block' => json_encode($payload)
        ]));
    }

    /**
     * Every row from tcs_closet_closets where flagged = 1, joined with
     * tcs_closet_schools for the human-readable school name. Newest flag
     * first so techs see fresh items at the top.
     *
     * @return array<int, array<string, mixed>>
     */
    private function loadFlagged(): array {
        $sql = 'SELECT c.uid, c.code, c.type, c.school_id, c.flag_reason, c.flag_tech,'
            .' c.flag_date, c.updated_at, s.name AS school_name'
            .' FROM tcs_closet_closets c'
            .' LEFT JOIN tcs_closet_schools s ON s.id = c.school_id'
            .' WHERE c.flagged = 1'
            .' ORDER BY c.flag_date DESC, c.code ASC';
        $rs = \DBselect($sql);
        $out = [];
        while ($row = \DBfetch($rs)) {
            $out[] = [
                'uid'          => (int) $row['uid'],
                'code'         => (string) $row['code'],
                'type'         => (string) $row['type'],
                'schoolId'     => (string) $row['school_id'],
                'schoolName'   => (string) ($row['school_name'] ?? ''),
                'flagReason'   => $row['flag_reason'] !== null ? (string) $row['flag_reason'] : null,
                'flagTech'     => $row['flag_tech']   !== null ? (string) $row['flag_tech']   : null,
                'flagDate'     => $row['flag_date']   !== null ? (string) $row['flag_date']   : null,
                'updated'      => $row['updated_at']  !== null ? substr((string) $row['updated_at'], 0, 10) : null,
                'problemCount' => 0,
                'problems'     => []
            ];
        }
        return $out;
    }

    /**
     * Closets whose switches have a non-null zabbix_hostid, scanned for
     * active problems. De-duped against $excludeUids (the flagged list).
     *
     * Capped at MAX_LIVE_LOOKUPS closets per call to bound the Zabbix
     * fan-out; further closets are skipped silently for v1.
     *
     * @param array<int, int> $excludeUids
     * @return array<int, array<string, mixed>>
     */
    private function loadWithProblems(array $excludeUids): array {
        $exclude = array_flip(array_map('intval', $excludeUids));

        // Aggregate hostids per closet in a single query so we don't fan out
        // an extra SELECT per row. Only consider switches that actually have
        // a zabbix_hostid set.
        $sql = 'SELECT c.uid, c.code, c.type, c.school_id, c.updated_at,'
            .' s.name AS school_name, sw.zabbix_hostid'
            .' FROM tcs_closet_closets c'
            .' LEFT JOIN tcs_closet_schools s ON s.id = c.school_id'
            .' INNER JOIN tcs_closet_switches sw ON sw.closet_uid = c.uid'
            .' WHERE sw.zabbix_hostid IS NOT NULL AND sw.zabbix_hostid <> '.\zbx_dbstr('')
            .' ORDER BY c.code ASC';
        $rs = \DBselect($sql);

        $closets = [];
        while ($row = \DBfetch($rs)) {
            $uid = (int) $row['uid'];
            if (isset($exclude[$uid])) continue;
            if (!isset($closets[$uid])) {
                $closets[$uid] = [
                    'uid'        => $uid,
                    'code'       => (string) $row['code'],
                    'type'       => (string) $row['type'],
                    'schoolId'   => (string) $row['school_id'],
                    'schoolName' => (string) ($row['school_name'] ?? ''),
                    'updated'    => $row['updated_at'] !== null ? substr((string) $row['updated_at'], 0, 10) : null,
                    'switches'   => []
                ];
            }
            $closets[$uid]['switches'][] = [
                'zabbixHostid' => (string) $row['zabbix_hostid']
            ];
        }

        if ($closets === []) {
            return [];
        }

        $enrich = new EnrichmentService();
        $scanned = 0;
        $out = [];
        foreach ($closets as $c) {
            if ($scanned >= self::MAX_LIVE_LOOKUPS) break;
            $scanned++;
            $problems = $enrich->activeProblemsForCloset($c);
            if ($problems === []) continue;
            $out[] = [
                'uid'          => $c['uid'],
                'code'         => $c['code'],
                'schoolId'     => $c['schoolId'],
                'schoolName'   => $c['schoolName'],
                'type'         => $c['type'],
                'updated'      => $c['updated'],
                'problemCount' => count($problems),
                'problems'     => $problems
            ];
        }

        // Heaviest first so the UI can lead with the most-affected closets.
        usort($out, static fn($a, $b) => $b['problemCount'] <=> $a['problemCount']);
        return $out;
    }
}
