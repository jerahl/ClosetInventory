<?php declare(strict_types=1);

namespace Modules\ClosetInventory\Actions;

use CController;
use CControllerResponseData;
use CWebUser;
use Modules\ClosetInventory\Lib\InventoryStore;

/**
 * POST zabbix.php?action=closet.switch.save
 *
 * Insert or update one switch row beneath a closet. The closet's
 * ports_total / ports_used cache is recomputed automatically.
 */
class ActionSwitchSave extends CController {

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
            'closetUid'   => 'required|int32',
            'id'          => 'int32',
            'name'        => 'string',
            'vendor'      => 'string',
            'model'       => 'string',
            'ports'       => 'int32',
            'used'        => 'int32',
            'poe'         => 'int32',
            'uplinks'     => 'int32',
            'uplinkSpeed' => 'string',
            'mgmtIp'      => 'string',
            'serial'      => 'string',
            'stack'       => 'int32',
            'zabbixHostid'=> 'string',
            'xiqDeviceId' => 'int32'
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
            $id = $this->hasInput('id') ? (int) $this->getInput('id') : null;
            $payload = [
                'name'        => (string) $this->getInput('name', ''),
                'vendor'      => (string) $this->getInput('vendor', ''),
                'model'       => (string) $this->getInput('model', ''),
                'ports'       => (int)    $this->getInput('ports', 0),
                'used'        => (int)    $this->getInput('used', 0),
                'poe'         => (int)    $this->getInput('poe', 0) === 1,
                'uplinks'     => (int)    $this->getInput('uplinks', 0),
                'uplinkSpeed' => (string) $this->getInput('uplinkSpeed', ''),
                'mgmtIp'      => (string) $this->getInput('mgmtIp', ''),
                'serial'      => (string) $this->getInput('serial', ''),
                'stack'       => (int)    $this->getInput('stack', 1)
            ];

            // External keys: pass null when missing/empty/zero so the DB stores
            // NULL instead of an empty-string or 0 sentinel.
            if ($this->hasInput('zabbixHostid')) {
                $z = trim((string) $this->getInput('zabbixHostid', ''));
                $payload['zabbixHostid'] = $z !== '' ? $z : null;
            }
            if ($this->hasInput('xiqDeviceId')) {
                $x = (int) $this->getInput('xiqDeviceId', 0);
                $payload['xiqDeviceId'] = $x > 0 ? $x : null;
            }

            $store = new InventoryStore();
            $newId = $store->saveSwitch($closetUid, $payload, ($id !== null && $id > 0) ? $id : null);
            $this->setResponse(new CControllerResponseData([
                'main_block' => json_encode(['ok' => true, 'id' => $newId, 'uid' => $closetUid])
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
