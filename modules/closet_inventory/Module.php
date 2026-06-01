<?php declare(strict_types=1);

namespace Modules\ClosetInventory;

use APP;
use Zabbix\Core\CModule;
use CMenuItem;
use Modules\ClosetInventory\Lib\DebugLog;

/**
 * Closet Inventory module bootstrap.
 *
 * - Registers a top-level main-menu entry "Closet Inventory" pointing at
 *   zabbix.php?action=closet.list.
 * - Runs setup/schema.sql idempotently on init, gated by a
 *   tcs_closet_schema_version row so future migrations stay quiet.
 */
class Module extends CModule {

    private const SCHEMA_TARGET = 1;

    public function init(): void {
        $actions = [];
        try {
            $m = $this->getManifest();
            $actions = is_array($m) ? array_keys($m['actions'] ?? []) : [];
        }
        catch (\Throwable $e) {
            $actions = ['ERR:'.$e->getMessage()];
        }

        DebugLog::log('Module.init.enter', [
            'phpVersion'        => PHP_VERSION,
            'manifest'          => __DIR__ . '/manifest.json',
            'registeredActions' => $actions,
            'baseClass'         => parent::class,
        ]);

        try {
            $this->registerMenu();
            DebugLog::log('Module.init.menuRegistered');
        }
        catch (\Throwable $e) {
            DebugLog::log('Module.init.menuFailed', ['error' => $e->getMessage()]);
        }

        try {
            $this->installSchema();
            DebugLog::log('Module.init.schemaOK');
        }
        catch (\Throwable $e) {
            DebugLog::log('Module.init.schemaFailed', ['error' => $e->getMessage()]);
            // Never let a schema hiccup fatal-out the menu. Log via Zabbix's
            // error() helper if available.
            if (function_exists('error')) {
                error('closet_inventory: schema install failed: '.$e->getMessage());
            }
        }
    }

    private function registerMenu(): void {
        $main_menu = APP::Component()->get('menu.main');
        if ($main_menu === null) {
            DebugLog::log('Module.registerMenu.noMenu');
            return;
        }

        $monitoring = $main_menu->find(_('Monitoring'));
        if ($monitoring === null) {
            DebugLog::log('Module.registerMenu.noMonitoring');
            return;
        }

        $submenu = $monitoring->getSubmenu();
        $submenu->add((new CMenuItem(_('Closet Inventory')))->setAction('closet.list'));
        DebugLog::log('Module.registerMenu.added', ['under' => 'Monitoring']);
    }

    /**
     * Idempotent schema install. Reads tcs_closet_schema_version (creates it
     * if missing) and applies any pending versions up to SCHEMA_TARGET.
     */
    private function installSchema(): void {
        // Create the version table on its own so subsequent migrations can
        // gate on it.
        \DBexecute(
            'CREATE TABLE IF NOT EXISTS tcs_closet_schema_version ('.
            ' version INT NOT NULL PRIMARY KEY'.
            ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $current = 0;
        $row = \DBfetch(\DBselect('SELECT version FROM tcs_closet_schema_version ORDER BY version DESC'));
        if ($row !== false && isset($row['version'])) {
            $current = (int) $row['version'];
        }

        // Self-heal: if the version row says we're current but a required
        // table is actually missing (e.g. an earlier install partially failed),
        // force the install to re-run. CREATE TABLE IF NOT EXISTS is idempotent
        // so this is safe even when most tables already exist.
        if ($current >= self::SCHEMA_TARGET) {
            $probe = \DBfetch(\DBselect("SHOW TABLES LIKE 'tcs_closet_schools'"));
            if ($probe === false || $probe === null) {
                \DBexecute('DELETE FROM tcs_closet_schema_version');
                $current = 0;
                DebugLog::log('Module.installSchema.selfHeal', ['reason' => 'tcs_closet_schools missing']);
            }
            else {
                return;
            }
        }

        $sqlFile = __DIR__.'/setup/schema.sql';
        if (!is_readable($sqlFile)) {
            return;
        }

        $sql = file_get_contents($sqlFile);
        if ($sql === false || $sql === '') {
            return;
        }

        // Strip /* */ comments and -- line comments BEFORE splitting, so a
        // file-leading comment doesn't cause its statement chunk to be
        // dropped when filtered by "starts with --".
        $sql = preg_replace('!/\*.*?\*/!s', '', $sql) ?? '';
        $lines = preg_split('/\r?\n/', $sql) ?: [];
        $lines = array_map(fn($l) => preg_replace('/--.*$/', '', $l) ?? '', $lines);
        $sql = implode("\n", $lines);
        $statements = array_filter(array_map('trim', explode(";\n", $sql)), fn($s) => $s !== '');

        foreach ($statements as $stmt) {
            // Trim any trailing semicolon left from the last statement.
            $stmt = rtrim($stmt, ";\n\r\t ");
            if ($stmt === '') {
                continue;
            }
            \DBexecute($stmt);
        }
    }
}
