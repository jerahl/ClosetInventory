<?php declare(strict_types=1);

namespace Modules\ClosetInventory;

use APP;
use Core\CModule;
use CMenu;
use CMenuItem;
use DB;
use DBException;

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
        /** @var CMenu $menu */
        $menu = APP::Component()->get('menu.main');
        if ($menu === null) {
            return;
        }

        $submenu = (new CMenuItem(_('Switch closets')))
            ->setAction('closet.list');

        $top = (new CMenuItem(_('Closet Inventory')))
            ->setSubMenu(new CMenu([$submenu]));

        $menu->add($top);
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

        if ($current >= self::SCHEMA_TARGET) {
            return;
        }

        $sqlFile = __DIR__.'/setup/schema.sql';
        if (!is_readable($sqlFile)) {
            return;
        }

        $sql = file_get_contents($sqlFile);
        if ($sql === false || $sql === '') {
            return;
        }

        // Strip /* */ comments and split on semicolons at end of line.
        $sql = preg_replace('!/\*.*?\*/!s', '', $sql) ?? '';
        $statements = array_filter(array_map('trim', explode(";\n", $sql)), fn($s) => $s !== '' && !str_starts_with($s, '--'));

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
