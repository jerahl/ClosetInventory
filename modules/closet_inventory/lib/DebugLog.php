<?php declare(strict_types=1);

namespace Modules\ClosetInventory\Lib;

use CWebUser;

/**
 * Append-only debug log for diagnosing module load / permission issues.
 * Writes to the PHP error log (php-fpm's error_log destination) via
 * error_log(). Tail with:
 *   sudo tail -f /var/log/php*-fpm.log    # or wherever your distro puts it
 *
 * Always on — entries are prefixed [closet_inventory] so they're easy to grep.
 */
final class DebugLog {

    public static function log(string $tag, array $ctx = []): void {
        try {
            error_log(self::format($tag, $ctx));
        }
        catch (\Throwable $e) {
            // never let logging fail the request
        }
    }

    private static function format(string $tag, array $ctx): string {
        $pid = getmypid();

        $user = 'anon';
        try {
            if (class_exists('\\CWebUser') && CWebUser::isLoggedIn()) {
                $u = CWebUser::$data ?? [];
                $user = ($u['alias'] ?? $u['username'] ?? '?') . '/uid=' . ($u['userid'] ?? '?')
                      . '/type=' . ($u['type'] ?? '?') . '/role=' . ($u['roleid'] ?? '?');
            }
            else {
                $user = 'NOT_LOGGED_IN';
            }
        }
        catch (\Throwable $e) {
            $user = 'CWebUser_ERROR:' . $e->getMessage();
        }

        $url = $_SERVER['REQUEST_URI'] ?? '?';
        $method = $_SERVER['REQUEST_METHOD'] ?? '?';

        $ctxStr = empty($ctx) ? '' : ' ' . json_encode($ctx, JSON_UNESCAPED_SLASHES);

        return "[closet_inventory pid=$pid] $tag user=$user $method $url$ctxStr";
    }
}
