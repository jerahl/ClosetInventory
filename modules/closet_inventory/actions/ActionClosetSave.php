<?php declare(strict_types=1);

namespace Modules\ClosetInventory\Actions;

use CController;
use CControllerResponseData;
use CWebUser;
use Modules\ClosetInventory\Lib\InventoryStore;

/**
 * POST zabbix.php?action=closet.save
 *
 * Create or update a closet record. CSRF validation stays ON — the React
 * client posts the token from window.CLOSET_BOOT as _csrf_token.
 */
class ActionClosetSave extends CController {

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
            'uid'      => 'int32',
            'code'     => 'string',
            'type'     => 'in MDF,IDF',
            'schoolId' => 'string',
            'building' => 'string',
            'floor'    => 'int32',
            'room'     => 'string'
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
            $payload = [
                'uid'      => $this->hasInput('uid')      ? (int)    $this->getInput('uid')      : 0,
                'code'     => $this->hasInput('code')     ? (string) $this->getInput('code')     : '',
                'type'     => $this->hasInput('type')     ? (string) $this->getInput('type')     : 'IDF',
                'schoolId' => $this->hasInput('schoolId') ? (string) $this->getInput('schoolId') : '',
                'building' => $this->hasInput('building') ? (string) $this->getInput('building') : '',
                'floor'    => $this->hasInput('floor')    ? (int)    $this->getInput('floor')    : 1,
                'room'     => $this->hasInput('room')     ? (string) $this->getInput('room')     : ''
            ];
            if ($payload['schoolId'] === '' || $payload['room'] === '') {
                http_response_code(400);
                $this->setResponse(new CControllerResponseData([
                    'main_block' => json_encode(['ok' => false, 'error' => 'schoolId and room are required'])
                ]));
                return;
            }
            $store = new InventoryStore();
            $uid = $store->saveCloset($payload);
            $this->setResponse(new CControllerResponseData([
                'main_block' => json_encode(['ok' => true, 'uid' => $uid])
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
