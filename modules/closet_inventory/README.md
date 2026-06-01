# closet_inventory — Zabbix frontend module

Phase 1 scaffold of the Switch Closet Inventory module. Authored CRUD only;
Zabbix / XIQ / rConfig enrichment lands in Phase 2–4.

## Install

1. Copy this directory into the Zabbix frontend host at
   `<zabbix-frontend>/modules/closet_inventory/` so the layout looks like:

   ```
   <zabbix-frontend>/modules/closet_inventory/
   ├── manifest.json
   ├── Module.php
   ├── actions/
   ├── views/
   ├── lib/
   ├── assets/
   └── setup/
   ```

2. Ensure the Zabbix DB user can `CREATE TABLE` — schema bootstrap runs on
   first load.

3. Optional: create the photo storage directory and give the web user write
   access. Default path is `/var/lib/closet-inventory/photos`:

   ```bash
   sudo mkdir -p /var/lib/closet-inventory/photos
   sudo chown www-data:www-data /var/lib/closet-inventory/photos
   sudo chmod 750 /var/lib/closet-inventory/photos
   ```

## Enable

1. Sign in as a Zabbix admin.
2. Go to **Administration → General → Modules**.
3. Click **Scan directory**.
4. Find **Closet Inventory** in the list and toggle status to **Enabled**.
5. The schema bootstrap runs on the next request — tables prefixed
   `tcs_closet_*` are created and `tcs_closet_schema_version` is set to 1.
6. A new top-level menu **Closet Inventory → Switch closets** appears,
   linking to `zabbix.php?action=closet.list`.

## Seed (optional)

POST `zabbix.php?action=closet.seed` as an admin to load a small starter
set of schools and closets so the UI isn't empty on day one. The seeder is
idempotent: it bails out as soon as any closet rows exist.

Phase 1 ships a hardcoded starter set (3 schools, 4 closets) rather than
parsing `assets/data.js` server-side. `data.js` stays in `assets/` as a
reference for the larger deterministic seed implemented in the design.

## Phase scope

- **Phase 1 (this build):** authored CRUD for closets, switches, power, maintenance, flag, photo upload. No external enrichment yet.
- **Phase 2:** `SwitchClient` + `EnrichmentService` — live port / PoE / stack from Zabbix.
- **Phase 3:** XIQ enrichment + switch autofill.
- **Phase 4:** rConfig backup-age + PoE-cycle action (audited).

The data shape emitted by `closet.view.data` already includes the
placeholder live keys (`switches[].poeStatus`, `configBackupAgeDays`,
`_live.sources`) so phase upgrades fill values in without renaming.
