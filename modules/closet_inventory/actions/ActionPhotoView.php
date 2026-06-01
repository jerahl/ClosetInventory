<?php declare(strict_types=1);

namespace Modules\ClosetInventory\Actions;

use CController;
use CControllerResponseData;
use CWebUser;

/**
 * GET zabbix.php?action=closet.photo.view&id=NN
 *
 * Streams the closet photo with `id = $id` back to the browser. The on-disk
 * path is read from tcs_closet_photos.path; only files under the configured
 * PHOTO_ROOT are served so a poisoned row can't be used to read arbitrary
 * files. Content-Type is inferred from the extension.
 */
class ActionPhotoView extends CController {

    private const PHOTO_ROOTS = [
        '/var/lib/closet-inventory/photos',
        '/tmp/closet_inventory_photos'
    ];

    private const MIME = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'webp' => 'image/webp',
        'gif'  => 'image/gif'
    ];

    protected function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkPermissions(): bool {
        return CWebUser::isLoggedIn();
    }

    protected function checkInput(): bool {
        return $this->validateInput([
            'id' => 'required|int32'
        ]);
    }

    protected function doAction(): void {
        $id = (int) $this->getInput('id');

        $row = \DBfetch(\DBselect('SELECT path FROM tcs_closet_photos WHERE id='.$id));
        $path = ($row !== false && isset($row['path'])) ? (string) $row['path'] : '';

        if ($path === '') {
            $this->fail(404, 'not found');
            return;
        }

        // Path must live under one of the configured roots — resolve to a
        // canonical path and reject anything that escapes them all.
        $real = realpath($path);
        $allowed = false;
        if ($real !== false) {
            foreach (self::PHOTO_ROOTS as $root) {
                $rootReal = realpath($root);
                if ($rootReal !== false && strpos($real, $rootReal . '/') === 0) {
                    $allowed = true;
                    break;
                }
            }
        }
        if (!$allowed) {
            $this->fail(403, 'invalid path');
            return;
        }
        if (!is_file($real) || !is_readable($real)) {
            $this->fail(404, 'file missing');
            return;
        }

        $ext  = strtolower(pathinfo($real, PATHINFO_EXTENSION));
        $mime = self::MIME[$ext] ?? 'application/octet-stream';
        $size = (int) filesize($real);

        // Stream the file directly. Bypass Zabbix's response wrapper because
        // we're sending binary, not HTML/JSON.
        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: '.$mime);
        header('Content-Length: '.$size);
        header('Cache-Control: private, max-age=86400');
        header('Content-Disposition: inline; filename="closet-'.$id.'.'.$ext.'"');
        readfile($real);
        exit;
    }

    private function fail(int $code, string $msg): void {
        http_response_code($code);
        $this->setResponse(new CControllerResponseData([
            'main_block' => json_encode(['ok' => false, 'error' => $msg])
        ]));
    }
}
