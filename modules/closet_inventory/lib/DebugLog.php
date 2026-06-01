<?php declare(strict_types=1);

namespace Modules\ClosetInventory\Lib;

use CWebUser;

/**
 * Append-only debug log for diagnosing module load / permission issues.
 * Writes to /tmp/closet_inventory_debug.log so an operator can tail it while
 * exercising the UI. Safe in production — guarded by a sentinel file so it
 * stays inert unless explicitly enabled.
 *
 * Enable:  touch /tmp/closet_inventory_debug.on
 * Disable: rm /tmp/closet_inventory_debug.on
 *
 * The log file is created 0600 on first write.
 */
final class DebugLog {

    private const SENTINEL = '/tmp/closet_inventory_debug.on';
    private const LOG_FILE = '/tmp/closet_inventory_debug.log';

    public static function on(): bool {
        return is_file(self::SENTINEL);
    }

    public static function log(string $tag, array $ctx = []): void {
        if (!self::on()) {
            return;
        }

        try {
            $line = self::format($tag, $ctx);
            $fh = @fopen(self::LOG_FILE, 'ab');
            if ($fh === false) return;
            @flock($fh, LOCK_EX);
            @fwrite($fh, $line);
            @flock($fh, LOCK_UN);
            @fclose($fh);
            @chmod(self::LOG_FILE, 0600);
        }
        catch (\Throwable $e) {
            // never let logging fail the request
        }
    }

    private static function format(string $tag, array $ctx): string {
        $ts = date('Y-m-d H:i:s');
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

        return "[$ts pid=$pid] $tag user=$user $method $url$ctxStr\n";
    }
}
