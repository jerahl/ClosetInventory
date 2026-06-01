<?php declare(strict_types=1);

namespace Modules\ClosetInventory\Actions;

use CController;
use CControllerResponseData;
use CWebUser;
use CSessionHelper;

/**
 * Base controller for page-render (HTML) actions in the Closet Inventory
 * module. CSRF validation stays on so form posts targeting these endpoints
 * are protected by Zabbix's normal request guarding.
 */
abstract class ActionBase extends CController {

    protected function checkPermissions(): bool {
        return CWebUser::isLoggedIn()
            && $this->getUserType() >= USER_TYPE_ZABBIX_USER;
    }

    /**
     * Boot payload inlined into the page as window.CLOSET_BOOT.
     * The frontend reads csrf_token off this object and submits it as
     * _csrf_token on every POST to closet.*.save endpoints.
     *
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    protected function buildBoot(array $extra = []): array {
        // TODO: confirm Zabbix CSRF helper for the target version. Recent
        // Zabbix exposes CCsrfTokenHelper::get('closet') / ::check(); older
        // versions surface the same token via the user session. Fallback to
        // the sessionid string keeps the contract stable until the helper
        // class is wired in.
        $token = '';
        if (class_exists('\\CCsrfTokenHelper')) {
            try { $token = (string) \CCsrfTokenHelper::get('closet'); }
            catch (\Throwable $e) { $token = ''; }
        }
        if ($token === '' && class_exists('\\CSessionHelper')) {
            try { $token = (string) CSessionHelper::get('sessionid'); }
            catch (\Throwable $e) { $token = ''; }
        }

        $boot = [
            'csrf_token' => $token,
            'initialUid' => null,
            'userName'   => CWebUser::$data['alias'] ?? CWebUser::$data['username'] ?? '',
            'isAdmin'    => $this->getUserType() >= USER_TYPE_ZABBIX_ADMIN
        ];

        return array_replace($boot, $extra);
    }
}
