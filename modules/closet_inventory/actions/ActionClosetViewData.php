<?php declare(strict_types=1);

namespace Modules\ClosetInventory\Actions;

use CControllerResponseData;
use Modules\ClosetInventory\Lib\InventoryStore;

/**
 * GET zabbix.php?action=closet.view.data&uid=NN
 *
 * Full closet record for the React DetailView. Phase 1 emits only authored
 * data; live keys (poeStatus, configBackupAgeDays) are present but null and
 * the _live.sources block reports "unconfigured" for every external system.
 * Phases 2–4 fill those in.
 */
class ActionClosetViewData extends ActionDataBase {

    protected function checkInput(): bool {
        $fields = ['uid' => 'int32'];
        return $this->validateInput($fields);
    }

    protected function doAction(): void {
        $uid = (int) $this->getInput('uid', 0);

        if ($uid <= 0) {
            http_response_code(400);
            $this->setResponse(new CControllerResponseData([
                'main_block' => json_encode(['error' => 'bad_uid'])
            ]));
            return;
        }

        try {
            $store  = new InventoryStore();
            $closet = $store->getCloset($uid);
            if ($closet === null) {
                http_response_code(404);
                $this->setResponse(new CControllerResponseData([
                    'main_block' => json_encode(['error' => 'not_found'])
                ]));
                return;
            }
            $payload = ['closet' => $closet];
        }
        catch (\Throwable $e) {
            $payload = ['error' => $e->getMessage()];
        }

        $this->setResponse(new CControllerResponseData([
            'main_block' => json_encode($payload)
        ]));
    }
}
