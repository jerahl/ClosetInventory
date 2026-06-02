<?php declare(strict_types=1);

namespace Modules\ClosetInventory\Lib;

/**
 * Dead-simple read-through cache. APCu primary, /tmp filesystem fallback.
 *
 * Phase 1 does not lean on this heavily; it ships now so Phase 2 (Zabbix
 * enrichment) can plug in without restructuring.
 */
class Cache {

    private const FS_DIR = '/tmp/closet_inv_cache';

    private static function apcuOn(): bool {
        return extension_loaded('apcu')
            && function_exists('apcu_enabled')
            && apcu_enabled();
    }

    private static function fsPath(string $key): string {
        if (!is_dir(self::FS_DIR)) {
            @mkdir(self::FS_DIR, 0700, true);
        }
        return self::FS_DIR.'/'.sha1($key).'.cache';
    }

    public static function get(string $key): ?string {
        if (self::apcuOn()) {
            $hit = apcu_fetch($key, $ok);
            if ($ok) {
                return is_string($hit) ? $hit : null;
            }
            return null;
        }
        $path = self::fsPath($key);
        if (!is_readable($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        $row = @unserialize($raw, ['allowed_classes' => false]);
        if (!is_array($row) || !isset($row['exp'], $row['v'])) {
            return null;
        }
        if ((int) $row['exp'] < time()) {
            @unlink($path);
            return null;
        }
        return (string) $row['v'];
    }

    public static function set(string $key, string $value, int $ttl): void {
        if (self::apcuOn()) {
            apcu_store($key, $value, $ttl);
            return;
        }
        $path = self::fsPath($key);
        $payload = serialize(['exp' => time() + $ttl, 'v' => $value]);
        @file_put_contents($path, $payload, LOCK_EX);
        @chmod($path, 0600);
    }

    public static function delete(string $key): void {
        if (self::apcuOn()) {
            apcu_delete($key);
            return;
        }
        @unlink(self::fsPath($key));
    }
}
