<?php declare(strict_types=1);

namespace Modules\ClosetInventory\Actions;

use CControllerResponseData;
use CControllerResponseFatal;

/**
 * GET zabbix.php?action=closet.view&uid=NN
 *
 * Page shell for a focused closet. The React app opens directly onto the
 * detail view for $uid by reading window.CLOSET_BOOT.initialUid.
 */
class ActionClosetView extends ActionBase {

    protected function checkInput(): bool {
        $fields = [
            'uid' => 'int32'
        ];
        $ret = $this->validateInput($fields);
        if (!$ret) {
            $this->setResponse(new CControllerResponseFatal());
        }
        return $ret;
    }

    protected function doAction(): void {
        $uid = (int) $this->getInput('uid', 0);

        $boot = $this->buildBoot([
            'initialView' => 'detail',
            'initialUid'  => $uid > 0 ? $uid : null
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
