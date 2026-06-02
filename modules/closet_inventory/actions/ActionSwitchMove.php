<?php declare(strict_types=1);

namespace Modules\ClosetInventory\Actions;

use CController;
use CControllerResponseData;
use CWebUser;
use Modules\ClosetInventory\Lib\InventoryStore;

/**
 * POST zabbix.php?action=closet.switch.move
 *
 * Move a single switch row from its current closet to another one. Touches
 * only closet_uid; all other switch fields are left intact. Recomputes
 * port counters on both endpoints and writes an audit trail row.
 */
class ActionSwitchMove extends CController {

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
        $ok = $this->validateInput([
            'switchId'        => 'int32',
            'targetClosetUid' => 'int32'
        ]);
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
            $switchId = $this->hasInput('switchId')        ? (int) $this->getInput('switchId')        : 0;
            $target   = $this->hasInput('targetClosetUid') ? (int) $this->getInput('targetClosetUid') : 0;
            if ($switchId <= 0 || $target <= 0) {
                http_response_code(400);
                $this->setResponse(new CControllerResponseData([
                    'main_block' => json_encode(['ok' => false, 'error' => 'switchId and targetClosetUid are required'])
                ]));
                return;
            }
            $store = new InventoryStore();
            $res = $store->moveSwitch($switchId, $target);
            if (!$res['ok']) {
                http_response_code(400);
                $this->setResponse(new CControllerResponseData([
                    'main_block' => json_encode(['ok' => false, 'error' => $res['error'] ?? 'move_failed'])
                ]));
                return;
            }
            $src = (int) $res['sourceClosetUid'];
            $store->audit('closet.switch.move', 'switch:'.$switchId, $src.' -> '.$target);
            $this->setResponse(new CControllerResponseData([
                'main_block' => json_encode([
                    'ok'              => true,
                    'sourceClosetUid' => $src
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
