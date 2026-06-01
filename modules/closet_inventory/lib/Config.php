<?php declare(strict_types=1);

namespace Modules\ClosetInventory\Lib;

use API;
use Throwable;

/**
 * Resolves Zabbix global user macros for external integrations (XIQ, rConfig).
 *
 * Values are cached in APCu (via {@see Cache}) for 5 minutes under the key
 * pattern `closet_inv:macro:<name>` so a typical page render doesn't hit the
 * Zabbix API once per controller. Values are NEVER written to logs.
 */
final class Config {

    /** Per-process micro-cache so repeated calls in one request share a hit. */
    private static array $mem = [];

    private const TTL = 300;

    /**
     * Resolve the XIQ permanent API token from {$XIQ.API_TOKEN}.
     * Returns null when the macro is unset or empty.
     */
    public static function xiqToken(): ?string {
        $val = self::macro('{$XIQ.API_TOKEN}');
        if ($val === null) return null;
        $val = trim($val);
        return $val === '' ? null : $val;
    }

    /**
     * Fallback credentials path. Returns ['username','password'] only when
     * BOTH {$XIQ.USERNAME} and {$XIQ.PASSWORD} are set and non-empty.
     *
     * @return array{username:string,password:string}|null
     */
    public static function xiqCredentials(): ?array {
        $u = self::macro('{$XIQ.USERNAME}');
        $p = self::macro('{$XIQ.PASSWORD}');
        if ($u === null || $p === null) return null;
        $u = trim($u);
        $p = trim($p);
        if ($u === '' || $p === '') return null;
        return ['username' => $u, 'password' => $p];
    }

    /**
     * Resolve the rConfig base URL from {$RCONFIG.URL}. Returns null when
     * the macro is unset or empty. Does not validate https:// here — the
     * RConfigClient constructor rejects non-https URLs.
     */
    public static function rconfigUrl(): ?string {
        $val = self::macro('{$RCONFIG.URL}');
        if ($val === null) return null;
        $val = trim($val);
        return $val === '' ? null : $val;
    }

    /**
     * Resolve the rConfig API token from {$RCONFIG.TOKEN}. Returns null
     * when the macro is unset or empty.
     */
    public static function rconfigToken(): ?string {
        $val = self::macro('{$RCONFIG.TOKEN}');
        if ($val === null) return null;
        $val = trim($val);
        return $val === '' ? null : $val;
    }

    /**
     * Resolve a single global user macro by exact name (including the curly
     * `{$NAME}` wrapper). Returns null when missing. Cached for self::TTL.
     */
    public static function macro(string $name): ?string {
        if (array_key_exists($name, self::$mem)) {
            return self::$mem[$name];
        }
        $cacheKey = 'closet_inv:macro:' . $name;
        $hit = Cache::get($cacheKey);
        if ($hit !== null) {
            // Stored as JSON envelope so we can distinguish null from "no entry".
            $env = json_decode($hit, true);
            if (is_array($env) && array_key_exists('v', $env)) {
                $v = $env['v'];
                self::$mem[$name] = ($v === null) ? null : (string) $v;
                return self::$mem[$name];
            }
        }

        $value = null;
        try {
            if (class_exists('\\API')) {
                $rows = API::UserMacro()->get([
                    'globalmacro' => true,
                    'filter'      => ['macro' => $name],
                    'output'      => ['value']
                ]);
                if (is_array($rows) && !empty($rows)) {
                    $first = $rows[0];
                    if (isset($first['value'])) {
                        $value = (string) $first['value'];
                    }
                }
            }
        }
        catch (Throwable $e) {
            // Treat lookup failure as "unset" — never let macro resolution
            // throw out of a request. The error is recorded in DebugLog so
            // operators can see it without revealing macro values.
            DebugLog::log('Config.macro.error', ['macro' => $name, 'error' => $e->getMessage()]);
            $value = null;
        }

        Cache::set($cacheKey, (string) json_encode(['v' => $value]), self::TTL);
        self::$mem[$name] = $value;
        return $value;
    }
}
