<?php declare(strict_types=1);

namespace Modules\ClosetInventory\Actions;

use CController;
use CControllerResponseData;
use CWebUser;
use Modules\ClosetInventory\Lib\InventoryStore;

/**
 * POST zabbix.php?action=closet.photo.upload  (multipart/form-data)
 *
 * Stores the uploaded image under /var/lib/closet-inventory/photos/<uid>/
 * and inserts a tcs_closet_photos row. Filenames are hashed to avoid
 * collisions and to neutralize whatever the client sent.
 */
class ActionPhotoUpload extends CController {

    private const PHOTO_ROOT     = '/var/lib/closet-inventory/photos';
    private const MAX_BYTES      = 10 * 1024 * 1024;
    private const ALLOWED_EXT    = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

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
            'label'     => 'string'
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
            $label = (string) $this->getInput('label', '');

            if (!isset($_FILES['photo']) || !is_array($_FILES['photo'])) {
                http_response_code(400);
                $this->setResponse(new CControllerResponseData([
                    'main_block' => json_encode(['ok' => false, 'error' => 'no photo'])
                ]));
                return;
            }

            $f = $_FILES['photo'];
            if ((int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                http_response_code(400);
                $this->setResponse(new CControllerResponseData([
                    'main_block' => json_encode(['ok' => false, 'error' => 'upload error '.(int) $f['error']])
                ]));
                return;
            }
            if ((int) ($f['size'] ?? 0) > self::MAX_BYTES) {
                http_response_code(400);
                $this->setResponse(new CControllerResponseData([
                    'main_block' => json_encode(['ok' => false, 'error' => 'file too large'])
                ]));
                return;
            }

            $origName = (string) ($f['name'] ?? 'photo');
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            if (!in_array($ext, self::ALLOWED_EXT, true)) {
                http_response_code(400);
                $this->setResponse(new CControllerResponseData([
                    'main_block' => json_encode(['ok' => false, 'error' => 'unsupported file type'])
                ]));
                return;
            }

            $dir = self::PHOTO_ROOT.'/'.$closetUid;
            if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
                http_response_code(500);
                $this->setResponse(new CControllerResponseData([
                    'main_block' => json_encode(['ok' => false, 'error' => 'cannot create storage dir'])
                ]));
                return;
            }

            $fname = bin2hex(random_bytes(8)).'.'.$ext;
            $dest  = $dir.'/'.$fname;

            if (!@move_uploaded_file((string) $f['tmp_name'], $dest)) {
                http_response_code(500);
                $this->setResponse(new CControllerResponseData([
                    'main_block' => json_encode(['ok' => false, 'error' => 'move_uploaded_file failed'])
                ]));
                return;
            }
            @chmod($dest, 0640);

            $store = new InventoryStore();
            $id = $store->recordPhoto($closetUid, $label, $dest);

            $this->setResponse(new CControllerResponseData([
                'main_block' => json_encode([
                    'ok'   => true,
                    'id'   => $id,
                    'uid'  => $closetUid,
                    'path' => $dest
                ])
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
