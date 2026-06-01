<?php declare(strict_types=1);

namespace Modules\ClosetInventory\Actions;

use CController;
use CControllerResponseData;
use CWebUser;
use Modules\ClosetInventory\Lib\InventoryStore;

/**
 * POST zabbix.php?action=closet.delete
 *
 * Permanently delete a closet and all its dependent rows. Single confirm
 * on the UI side is the only guard — the closet record is unrecoverable
 * after this returns. Writes a tcs_closet_audit row for traceability.
 */
class ActionClosetDelete extends CController {

    protected function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkPermissions(): bool {
        if (!CWebUser::isLoggedIn()) {
            http_response_code(401);
            $this->setResponse(new CControllerResponseData([
                'main_block' => json_encode(['ok' => false, 'error' => 'unauthenticated'])
            ]));
            return false;
        }
        return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
    }

    protected function checkInput(): bool {
        $ok = $this->validateInput(['uid' => 'int32']);
        if (!$ok) {
            http_response_code(400);
            $this->setResponse(new CControllerResponseData([
                'main_block' => json_encode(['ok' => false, 'error' => 'bad_input'])
            ]));
        }
        return $ok;
    }

    protected function doAction(): void {
        try {
            $uid = $this->hasInput('uid') ? (int) $this->getInput('uid') : 0;
            if ($uid <= 0) {
                http_response_code(400);
                $this->setResponse(new CControllerResponseData([
                    'main_block' => json_encode(['ok' => false, 'error' => 'uid is required'])
                ]));
                return;
            }
            $store = new InventoryStore();
            $res = $store->removeCloset($uid);
            if (!$res['deleted']) {
                http_response_code(404);
                $this->setResponse(new CControllerResponseData([
                    'main_block' => json_encode(['ok' => false, 'error' => 'closet_not_found'])
                ]));
                return;
            }
            $store->audit('closet.delete', 'closet:'.$uid, 'switches='.(int) $res['switches']);
            $this->setResponse(new CControllerResponseData([
                'main_block' => json_encode([
                    'ok'       => true,
                    'deleted'  => true,
                    'switches' => (int) $res['switches']
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
