<?php declare(strict_types=1);

namespace Modules\ClosetInventory;

use APP;
use Zabbix\Core\CModule;
use CMenuItem;

/**
 * Closet Inventory module bootstrap.
 *
 * - Registers a top-level main-menu entry "Closet Inventory" pointing at
 *   zabbix.php?action=closet.list.
 * - Runs setup/schema.sql idempotently on init, gated by a
 *   tcs_closet_schema_version row so future migrations stay quiet.
 */
class Module extends CModule {

    private const SCHEMA_TARGET = 2;

    public function init(): void {
        $this->registerMenu();

        try {
            $this->installSchema();
        }
        catch (\Throwable $e) {
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
            return;
        }

        $monitoring = $main_menu->find(_('Monitoring'));
        if ($monitoring === null) {
            return;
        }

        $submenu = $monitoring->getSubmenu();
        $submenu->add((new CMenuItem(_('Closet Inventory')))->setAction('closet.list'));
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

        // ---- v2 migration: device_type column on tcs_closet_switches. ----
        // The table now holds switches, servers, and "other" devices, all
        // discriminated by the new device_type column. Migration is guarded
        // by information_schema so re-runs are no-ops.
        if ($current < 2) {
            $this->migrateToV2();
            \DBexecute('INSERT IGNORE INTO tcs_closet_schema_version (version) VALUES (2)');
        }
    }

    /**
     * v2: add device_type to tcs_closet_switches plus a (closet_uid, device_type)
     * index. Guarded by information_schema lookups so a partial / repeat run
     * is a no-op.
     */
    private function migrateToV2(): void {
        $colExists = \DBfetch(\DBselect(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS"
            ." WHERE TABLE_SCHEMA = DATABASE()"
            ." AND TABLE_NAME = 'tcs_closet_switches'"
            ." AND COLUMN_NAME = 'device_type'"
        ));
        if ($colExists === false || $colExists === null) {
            \DBexecute(
                "ALTER TABLE tcs_closet_switches"
                ." ADD COLUMN device_type VARCHAR(8) NOT NULL DEFAULT 'switch'"
            );
        }

        $idxExists = \DBfetch(\DBselect(
            "SELECT INDEX_NAME FROM information_schema.STATISTICS"
            ." WHERE TABLE_SCHEMA = DATABASE()"
            ." AND TABLE_NAME = 'tcs_closet_switches'"
            ." AND INDEX_NAME = 'idx_switch_closet_type'"
        ));
        if ($idxExists === false || $idxExists === null) {
            \DBexecute(
                'CREATE INDEX idx_switch_closet_type'
                .' ON tcs_closet_switches (closet_uid, device_type)'
            );
        }
    }
}
