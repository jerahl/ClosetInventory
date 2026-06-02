<?php declare(strict_types=1);

namespace Modules\ClosetInventory\Actions;

use CControllerResponseData;

/**
 * GET zabbix.php?action=closet.schools.data
 *
 * Per-school rollup: closet count, switch count, port totals/used,
 * flagged-closet count, last-updated timestamp. Backs the Schools page
 * in the sidebar.
 *
 * Returns JSON. On failure emits {ok:false, error:...} with HTTP 500
 * so the frontend toast layer can surface the error.
 */
class ActionSchoolsData extends ActionDataBase {

    protected function checkInput(): bool {
        return $this->validateInput([]);
    }

    protected function doAction(): void {
        try {
            // Closet-level rollups: counts, port sums, flags, last-updated.
            // Join switches as a separate aggregate query to avoid the
            // double-counting trap that a single 3-way join would introduce
            // (port sums would explode by switch-row fan-out).
            $rs = \DBselect(
                'SELECT s.id, s.name, s.type, s.zbx_group, s.color_hue,'
                .' COUNT(c.uid) AS closet_count,'
                .' COALESCE(SUM(c.ports_total),0) AS ports_total,'
                .' COALESCE(SUM(c.ports_used),0)  AS ports_used,'
                .' COALESCE(SUM(c.flagged),0)    AS flagged_count,'
                .' MAX(c.updated_at) AS last_updated'
                .' FROM tcs_closet_schools s'
                .' LEFT JOIN tcs_closet_closets c ON c.school_id = s.id'
                .' GROUP BY s.id, s.name, s.type, s.zbx_group, s.color_hue'
                .' ORDER BY s.name ASC'
            );

            $schools = [];
            $byId    = [];
            while ($row = \DBfetch($rs)) {
                $id  = (string) $row['id'];
                $hue = $row['color_hue'] !== null ? (int) $row['color_hue'] : 256;
                $lu  = $row['last_updated'] !== null ? substr((string) $row['last_updated'], 0, 10) : null;
                $entry = [
                    'id'           => $id,
                    'name'         => (string) $row['name'],
                    'type'         => (string) ($row['type'] ?? ''),
                    'zbxGroup'     => $row['zbx_group'] !== null ? (string) $row['zbx_group'] : null,
                    'color'        => [
                        'bg' => "oklch(0.95 0.03 {$hue})",
                        'fg' => "oklch(0.45 0.13 {$hue})"
                    ],
                    'closetCount'  => (int) $row['closet_count'],
                    'switchCount'  => 0,
                    'portsTotal'   => (int) $row['ports_total'],
                    'portsUsed'    => (int) $row['ports_used'],
                    'flaggedCount' => (int) $row['flagged_count'],
                    'lastUpdated'  => $lu
                ];
                $schools[]    = $entry;
                $byId[$id]    = count($schools) - 1;
            }

            // Switch counts joined through closets.
            $rs2 = \DBselect(
                'SELECT c.school_id AS sid, COUNT(sw.id) AS n'
                .' FROM tcs_closet_closets c'
                .' JOIN tcs_closet_switches sw ON sw.closet_uid = c.uid'
                .' GROUP BY c.school_id'
            );
            while ($r = \DBfetch($rs2)) {
                $sid = (string) $r['sid'];
                if (isset($byId[$sid])) {
                    $schools[$byId[$sid]]['switchCount'] = (int) $r['n'];
                }
            }

            $totals = [
                'schools'    => count($schools),
                'closets'    => 0,
                'switches'   => 0,
                'portsTotal' => 0,
                'portsUsed'  => 0,
                'flagged'    => 0
            ];
            foreach ($schools as $s) {
                $totals['closets']    += $s['closetCount'];
                $totals['switches']   += $s['switchCount'];
                $totals['portsTotal'] += $s['portsTotal'];
                $totals['portsUsed']  += $s['portsUsed'];
                $totals['flagged']    += $s['flaggedCount'];
            }

            $this->setResponse(new CControllerResponseData([
                'main_block' => json_encode([
                    'ok'      => true,
                    'schools' => $schools,
                    'totals'  => $totals
                ])
            ]));
        }
        catch (\Throwable $e) {
            http_response_code(500);
            $this->setResponse(new CControllerResponseData([
                'main_block' => json_encode(['ok' => false, 'error' => $e->getMessage()])
            ]));
        }
    }
}
