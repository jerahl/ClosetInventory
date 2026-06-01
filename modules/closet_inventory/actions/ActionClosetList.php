<?php declare(strict_types=1);

namespace Modules\ClosetInventory\Actions;

use CControllerResponseData;
use CControllerResponseFatal;

/**
 * GET zabbix.php?action=closet.list
 *
 * Page shell only — emits the React mount point and the boot payload. The
 * actual closet list is fetched asynchronously by App.jsx from
 * closet.list.data after first paint.
 */
class ActionClosetList extends ActionBase {

    protected function init(): void {
        // Page shell uses a static container, no validatable input.
    }

    protected function checkInput(): bool {
        return true;
    }

    protected function doAction(): void {
        $boot = $this->buildBoot([
            'initialView' => 'list',
            'initialUid'  => null
        ]);

        $data = [
            'title' => _('Closet Inventory'),
            'boot'  => $boot
        ];

        $response = new CControllerResponseData($data);
        $response->setTitle(_('Closet Inventory'));
        $this->setResponse($response);
    }
}
