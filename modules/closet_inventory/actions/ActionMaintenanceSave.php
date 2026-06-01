<?php declare(strict_types=1);

namespace Modules\ClosetInventory\Actions;

use CController;
use CControllerResponseData;
use CWebUser;
use Modules\ClosetInventory\Lib\InventoryStore;

/**
 * POST zabbix.php?action=closet.maintenance.save
 *
 * Append a maintenance row to the closet's timeline.
 */
class ActionMaintenanceSave extends CController {

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
            'date'      => 'string',
            'type'      => 'string',
            'tone'      => 'string',
            'tech'      => 'string',
            'notes'     => 'string'
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
            $notes = (string) $this->getInput('notes', '');
            if (trim($notes) === '') {
                http_response_code(400);
                $this->setResponse(new CControllerResponseData([
                    'main_block' => json_encode(['ok' => false, 'error' => 'notes is required'])
                ]));
                return;
            }
            $payload = [
                'date'  => (string) $this->getInput('date',  date('Y-m-d')),
                'type'  => (string) $this->getInput('type',  ''),
                'tone'  => (string) $this->getInput('tone',  'default'),
                'tech'  => (string) $this->getInput('tech',  ''),
                'notes' => $notes
            ];
            $store = new InventoryStore();
            $id = $store->appendMaintenance($closetUid, $payload);
            $this->setResponse(new CControllerResponseData([
                'main_block' => json_encode(['ok' => true, 'id' => $id, 'uid' => $closetUid])
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
