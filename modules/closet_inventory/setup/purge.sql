-- Closet Inventory — purge all authored + imported data.
-- Schema and the schema_version row are preserved; only data tables are emptied.
-- After running this, click "Populate from Zabbix" (or "Seed starter data") to
-- repopulate.
--
-- Usage:
--   mysql -u zabbix -p zabbix < modules/closet_inventory/setup/purge.sql
-- (substitute your DB user / name as appropriate).

SET FOREIGN_KEY_CHECKS = 0;

TRUNCATE TABLE tcs_closet_audit;
TRUNCATE TABLE tcs_closet_photos;
TRUNCATE TABLE tcs_closet_maintenance;
TRUNCATE TABLE tcs_closet_circuits;
TRUNCATE TABLE tcs_closet_power_units;
TRUNCATE TABLE tcs_closet_switches;
TRUNCATE TABLE tcs_closet_closets;
TRUNCATE TABLE tcs_closet_schools;

SET FOREIGN_KEY_CHECKS = 1;
