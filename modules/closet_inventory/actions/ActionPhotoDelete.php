<?php declare(strict_types=1);

namespace Modules\ClosetInventory\Actions;

use CController;
use CControllerResponseData;
use CWebUser;

/**
 * POST zabbix.php?action=closet.photo.delete  body=id
 *
 * Removes the on-disk file and the tcs_closet_photos row. Path is validated
 * against PHOTO_ROOT so a poisoned row can't be used to unlink arbitrary
 * files.
 */
class ActionPhotoDelete extends CController {

    private const PHOTO_ROOT = '/var/lib/closet-inventory/photos';

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
        return $this->validateInput([
            'id' => 'required|int32'
        ]);
    }

    protected function doAction(): void {
        try {
            $id = (int) $this->getInput('id');
            $row = \DBfetch(\DBselect('SELECT path FROM tcs_closet_photos WHERE id='.$id));
            if ($row === false || !isset($row['path'])) {
                http_response_code(404);
                $this->setResponse(new CControllerResponseData([
                    'main_block' => json_encode(['ok' => false, 'error' => 'not found'])
                ]));
                return;
            }

            $path = (string) $row['path'];
            $real = realpath($path);
            $rootReal = realpath(self::PHOTO_ROOT);
            if ($real !== false && $rootReal !== false && strpos($real, $rootReal . '/') === 0 && is_file($real)) {
                @unlink($real);
            }
            \DBexecute('DELETE FROM tcs_closet_photos WHERE id='.$id);

            $this->setResponse(new CControllerResponseData([
                'main_block' => json_encode(['ok' => true])
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
