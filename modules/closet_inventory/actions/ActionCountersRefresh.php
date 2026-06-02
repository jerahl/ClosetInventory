<?php declare(strict_types=1);

namespace Modules\ClosetInventory\Actions;

use CController;
use CControllerResponseData;
use CWebUser;
use Modules\ClosetInventory\Lib\EnrichmentService;
use Modules\ClosetInventory\Lib\InventoryStore;

/**
 * POST zabbix.php?action=closet.counters.refresh
 *
 * Walks every closet, runs the Zabbix enrichment to recompute live
 * ports_total / ports_used, and writes the result back to the closet row so
 * the list view (which reads cached counters) reflects reality.
 *
 * Admin-only. CSRF validated through the framework default (we do not call
 * disableCsrfValidation()); the React client posts with _csrf_token from
 * window.CLOSET_BOOT.
 */
class ActionCountersRefresh extends CController {

    protected function checkPermissions(): bool {
        if (!CWebUser::isLoggedIn()) {
            http_response_code(401);
            $this->setResponse(new CControllerResponseData([
                'main_block' => json_encode(['ok' => false, 'error' => 'unauthenticated'])
            ]));
            return false;
        }
        return $this->getUserType() >= USER_TYPE_ZABBIX_ADMIN;
    }

    protected function checkInput(): bool {
        // No body fields beyond _csrf_token.
        return $this->validateInput([]);
    }

    protected function doAction(): void {
        $store     = new InventoryStore();
        $enricher  = new EnrichmentService();
        $updated   = 0;
        $errors    = [];

        try {
            $closets = $store->listClosets();
        }
        catch (\Throwable $e) {
            http_response_code(500);
            $this->setResponse(new CControllerResponseData([
                'main_block' => json_encode(['ok' => false, 'error' => $e->getMessage()])
            ]));
            return;
        }

        foreach ($closets as $row) {
            $uid = (int) ($row['uid'] ?? 0);
            if ($uid <= 0) continue;

            try {
                $full = $store->getCloset($uid);
                if ($full === null) continue;

                $enriched = $enricher->enrich($full);
                $store->updateCounters(
                    $uid,
                    (int) ($enriched['portsTotal'] ?? 0),
                    (int) ($enriched['portsUsed']  ?? 0)
                );
                $updated++;
            }
            catch (\Throwable $e) {
                $errors[] = 'uid='.$uid.': '.$e->getMessage();
            }
        }

        $this->setResponse(new CControllerResponseData([
            'main_block' => json_encode([
                'ok'      => true,
                'updated' => $updated,
                'errors'  => $errors
            ])
        ]));
    }
}
