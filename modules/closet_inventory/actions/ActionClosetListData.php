<?php declare(strict_types=1);

namespace Modules\ClosetInventory\Actions;

use CControllerResponseData;
use Modules\ClosetInventory\Lib\InventoryStore;

/**
 * GET zabbix.php?action=closet.list.data
 *
 * Returns the closet roster used by the list view. Per-closet port counters
 * are read off the cached columns on tcs_closet_closets so we don't fan
 * out per row.
 */
class ActionClosetListData extends ActionDataBase {

    protected function checkInput(): bool {
        return true;
    }

    protected function doAction(): void {
        try {
            $store   = new InventoryStore();
            $closets = $store->listClosets();
            $schools = $store->listSchools();
            $payload = [
                'closets'   => $closets,
                'schools'   => $schools,
                'rateLimit' => new \stdClass()
            ];
        }
        catch (\Throwable $e) {
            $payload = [
                'closets'   => [],
                'schools'   => [],
                'rateLimit' => new \stdClass(),
                'error'     => $e->getMessage()
            ];
        }

        $this->setResponse(new CControllerResponseData([
            'main_block' => json_encode($payload)
        ]));
    }
}
