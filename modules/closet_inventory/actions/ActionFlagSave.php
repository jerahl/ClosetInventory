<?php declare(strict_types=1);

namespace Modules\ClosetInventory\Actions;

use CController;
use CControllerResponseData;
use CWebUser;
use Modules\ClosetInventory\Lib\InventoryStore;

/**
 * POST zabbix.php?action=closet.flag.save
 *
 * Sets or clears the service flag on a closet. Both transitions are audited
 * via InventoryStore::audit() so the operator log has a trail.
 */
class ActionFlagSave extends CController {

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
        $fields = [
            'closetUid' => 'required|int32',
            'flagged'   => 'int32',
            'reason'    => 'string',
            'tech'      => 'string',
            'date'      => 'string'
        ];
        $ok = $this->validateInput($fields);
        if (!$ok) {
            $this->setResponse(new CControllerResponseData([
                'main_block' => json_encode(['ok' => false, 'error' => 'bad_input'])
            ]));
        }
        return $ok;
    }

    protected function doAction(): void {
        try {
            $closetUid = (int) $this->getInput('closetUid');
            $flagged   = (int) $this->getInput('flagged', 0) === 1;
            $payload = [
                'flagged' => $flagged,
                'reason'  => (string) $this->getInput('reason', ''),
                'tech'    => (string) $this->getInput('tech',   ''),
                'date'    => (string) $this->getInput('date',   date('Y-m-d'))
            ];
            if ($flagged && trim($payload['reason']) === '') {
                http_response_code(400);
                $this->setResponse(new CControllerResponseData([
                    'main_block' => json_encode(['ok' => false, 'error' => 'reason is required to flag'])
                ]));
                return;
            }

            $store = new InventoryStore();
            $store->setFlag($closetUid, $payload);
            $this->setResponse(new CControllerResponseData([
                'main_block' => json_encode(['ok' => true, 'uid' => $closetUid])
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
