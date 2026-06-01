<?php declare(strict_types=1);

namespace Modules\ClosetInventory\Actions;

use CController;
use CControllerResponseData;
use CWebUser;
use Modules\ClosetInventory\Lib\InventoryStore;

/**
 * POST zabbix.php?action=closet.power.save
 *
 * Replaces the closet's power block (UPS list, PDU list, circuits) in one
 * shot. The body carries a single JSON-encoded `power` field — the editor
 * is naturally object-shaped and round-tripping through JSON is cheaper
 * than enumerating every nested key as a form field.
 */
class ActionPowerSave extends CController {

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
            'power'     => 'required|string'
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
            $raw = (string) $this->getInput('power');
            $power = json_decode($raw, true);
            if (!is_array($power)) {
                http_response_code(400);
                $this->setResponse(new CControllerResponseData([
                    'main_block' => json_encode(['ok' => false, 'error' => 'power must be JSON'])
                ]));
                return;
            }

            $store = new InventoryStore();
            $store->savePower($closetUid, $power);
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
