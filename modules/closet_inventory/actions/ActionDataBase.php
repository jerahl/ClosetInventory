<?php declare(strict_types=1);

namespace Modules\ClosetInventory\Actions;

use CController;
use CControllerResponseData;
use CWebUser;
use Modules\ClosetInventory\Lib\DebugLog;

/**
 * Shared base for closet.*.data JSON controllers.
 *
 * Unauthenticated requests get a 401 JSON body so the frontend poller can
 * detect the session expiry cleanly instead of parsing the HTML login page
 * Zabbix would otherwise return.
 */
abstract class ActionDataBase extends CController {

    protected function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkPermissions(): bool {
        $loggedIn = CWebUser::isLoggedIn();
        if (!$loggedIn) {
            DebugLog::log('ActionDataBase.checkPermissions.deny', [
                'class'  => static::class,
                'reason' => 'not_logged_in',
            ]);
            http_response_code(401);
            $this->setResponse(new CControllerResponseData([
                'main_block' => json_encode(['error' => 'unauthenticated'])
            ]));
            return false;
        }

        $type = $this->getUserType();
        $allowed = $type >= USER_TYPE_ZABBIX_USER;
        DebugLog::log('ActionDataBase.checkPermissions', [
            'class'    => static::class,
            'userType' => $type,
            'allowed'  => $allowed,
        ]);
        return $allowed;
    }
}
