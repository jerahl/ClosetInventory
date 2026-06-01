# Switch Closet Inventory — Zabbix Frontend Module

A **Zabbix 6.x/7.0 frontend module** (`closet_inventory`) that inventories every
switch closet (MDF/IDF) across the district, recreates the **Switch Closet
Inventory** Claude Design mockup pixel-for-pixel, and enriches the inventory
with **live** data from three systems: **Zabbix** (in-process), **ExtremeCloud
IQ (XIQ)**, and **rConfig**.

Sibling module to `tcs_dashboard` in `jerahl/ZabbixCustomDashboard`; same
deployment posture, same auth, same asset pipeline.

Read **§0** first — it changes how everything else is built.

---

## 0. The one insight that shapes the whole app

The mockup persists everything to `localStorage`. In production that does **not**
work, because **no single system owns this data**. Split the model in two:

| Layer | Owner | Source of truth |
|---|---|---|
| **Inventory of record** — closets, building/room/floor, switch↔host mappings, UPS/PDU/circuit records, photos, maintenance log, service flags | **The module's own DB tables** (in the Zabbix DB) | Operators enter via this module |
| **Live operational state** — port up/down/utilization, PoE status & draw, stack-member health, active problems, config-backup freshness, AP/switch reachability | **Zabbix / XIQ / rConfig** | Pulled on demand, cached, never authored here |

So the architecture is: **authored inventory tables + a read-through enrichment
layer over three APIs.** A closet record stores stable facts and a set of
*external keys* (`zabbix_hostid`, `xiq_device_id`, `rconfig_device_id`); at
render time the data controllers fan out to the three clients, merge live state
onto the stored record, and the design's port-bars / gauges / flags reflect
reality.

Do not derive the inventory purely from Zabbix hosts — Zabbix has no concept of
"closet", "UPS battery health entered by a tech", "photo of the rack", or
"maintenance note". Those are first-class authored data.

---

## 1. Why a Zabbix module (vs. a standalone app)

- **`SwitchClient.php` works verbatim** — it calls `API::Item()->get([...])` in
  the Zabbix frontend's process. No JSON-RPC wrapper, no token plumbing.
- **Auth, sessions, CSRF, permissions** come free via `CController` +
  `CWebUser` + `USER_TYPE_ZABBIX_*` (the `ActionDataBase` pattern).
- **Same menu, same look-and-feel** as `tcs_dashboard` — operators don't have to
  learn a second login or navigate to a different host.
- **Assets** ship under the module's `assets/` dir, served by Zabbix's
  asset router — same posture the existing module already uses for its
  `assets/dist/*.css|js`.
- **DB** lives in the Zabbix DB. New tables are namespaced `tcs_closet_*` so
  upgrades don't collide; schema installed by the module's setup script.
- **Pure-HTTP clients** (`XIQClient`, `XIQFleetClient`, `RConfigClient`) port
  unchanged from `tcs_dashboard/lib/`.

The few costs: the app's URL space lives inside Zabbix
(`zabbix.php?action=closet.*`), and we inherit Zabbix's PHP version target.

---

## 2. Tech stack

- **PHP** matching the reference module (8.0+ with the `array_is_list` polyfill;
  bump to 8.1+ only if `tcs_dashboard` does).
- **Zabbix 6.0+** frontend module API (`manifest.json` `manifest_version: 2.0`).
- **MariaDB/MySQL/Postgres** — whatever the host Zabbix uses. DB access via
  Zabbix's `DB::` / `DBfetch` / `DBexecute` helpers (same connection as Zabbix).
- **APCu** for the read-through cache, with `/tmp` filesystem fallback — already
  implemented inside `XIQClient`/`XIQFleetClient`.
- **Frontend:** keep the design's vanilla **React 18 + Babel-standalone** for
  v1 (zero build step, matches `tcs_dashboard/assets/` posture). Tweaks panel
  (dark / accent / density) stays as-is — pure presentation.

---

## 3. Module layout

Mirrors `tcs_dashboard/` exactly:

```
closet_inventory/
├── manifest.json                # name, namespace, actions, version
├── Module.php                   # menu registration, init hook (run setup if needed)
├── setup/
│   └── schema.sql               # CREATE TABLE tcs_closet_* (§6); idempotent
├── actions/
│   ├── ActionBase.php           # render shell (copy from tcs_dashboard pattern)
│   ├── ActionDataBase.php       # COPY verbatim from reference/
│   ├── ActionClosetList.php     # zabbix.php?action=closet.list  (page shell)
│   ├── ActionClosetView.php     # zabbix.php?action=closet.view&uid=NN  (page shell)
│   ├── ActionClosetListData.php # closet.list.data  → JSON list (cached counters)
│   ├── ActionClosetViewData.php # closet.view.data  → JSON live-enriched closet
│   ├── ActionClosetSave.php     # closet.save       → POST create/update
│   ├── ActionSwitchSave.php     # closet.switch.save
│   ├── ActionPowerSave.php      # closet.power.save
│   ├── ActionMaintenanceSave.php
│   ├── ActionFlagSave.php
│   ├── ActionPhotoUpload.php    # closet.photo.upload (multipart)
│   └── ActionPoeCycle.php       # closet.poe.cycle   → RConfigClient.deploySnippet
├── views/
│   ├── closet.list.view.php     # emits <div id="closet-root"> + window.CLOSET_BOOT
│   └── closet.view.view.php
├── lib/
│   ├── SwitchClient.php         # COPY verbatim from reference/
│   ├── XIQClient.php            # COPY verbatim from reference/
│   ├── XIQFleetClient.php       # COPY verbatim from reference/
│   ├── RConfigClient.php        # COPY verbatim from reference/
│   ├── InventoryStore.php       # NEW — CRUD via DB::/DBfetch (§6 schema)
│   ├── EnrichmentService.php    # NEW — merges live state onto closet records
│   └── Cache.php                # APCu + /tmp fallback (lift from XIQClient internals)
└── assets/
    ├── styles.css               # COPY verbatim from design/project/app/
    ├── icons.jsx
    ├── components.jsx
    ├── ListView.jsx             # adapt: data from window.CLOSET_BOOT + fetch
    ├── DetailView.jsx
    ├── Modals.jsx               # adapt: POST to action URLs on save
    ├── tweaks-panel.jsx
    └── App.jsx                  # adapt: drop localStorage, use API
```

Place the module under your Zabbix frontend's `modules/closet_inventory/`,
enable it in **Administration → General → Modules**, and the schema bootstrap
runs on first load.

---

## 4. The three integrations

### 4.1 Zabbix (in-process via the Item API)

Use `SwitchClient` **as-is** from `reference/lib/SwitchClient.php`. It already
hits the keys we need from the Extreme EXOS template:

- `stacking.member[<n>]` → stack member presence/role
- `net.if.status[ifOperStatus.<member>.<port>]` → per-port link state
- `snmp.interfaces.poe.dstatus[<member>.<port>]` → PoE detection (valuemap
  1=Disabled, 2=Searching, 3=DeliveringPower, 4=Fault, 5=Test, 6=OtherFault)
- `net.if.mac[<member>.<port>]` → FDB/MAC-learning rows
- `host.get` for display name; `problem.get` for the flag feed

**School/site mapping:** reuse the existing convention from
`reference/actions/ActionSwitches.php` — schools are Zabbix host groups
prefixed `Site/*`. `collectFleet()` strips the prefix and slugs to an id;
our `schools.zbx_group` column stores the full `Site/<Name>` so closet records
map cleanly without re-deriving.

**Performance pattern to preserve:** `SwitchClient::snapshot()` does **one**
`item.get` per host pulling every relevant item, then parses locally. Keep this
— it's the difference between a snappy detail page and a slow one.

### 4.2 ExtremeCloud IQ

Copy `XIQClient.php` and `XIQFleetClient.php` verbatim. Honor the docblock
field corrections (G3–G6):

- G3 `/devices/{id}.mac_address` is the wireless **base** MAC; eth0 is in
  `interfaces`. Normalize with `macInsertColons()` for display.
- G4 `/clients/active` filter is `deviceIds` (camelCase plural).
- G5 always append `views=FULL` for rssi/snr/channel.
- G6 `/devices/{id}/interfaces/wifi` requires a ≥10-min trailing window.

**Auth:** `XIQClient::fromToken($token)` / `XIQFleetClient::fromToken($token)`
with a permanent API token. Token comes from a Zabbix user macro
(`{$XIQ.API_TOKEN}`) read at startup, *not* hardcoded.

**Rate limit:** 7,500 req/hr per VIQ, shared across all integrations. Surface
`getRateLimitRemaining()`; ship a banner under 500; catch
`XIQRateLimitException` (429) and skip further XIQ calls for that page load.

### 4.3 rConfig

Copy `RConfigClient.php` verbatim.

- **Device resolution** (`resolveDeviceId`): match a Zabbix host to its rConfig
  device id by SNMP IP → any-interface IP → technical hostname → visible name.
  Ambiguous matches throw; let an operator pin the id via the stored
  `switches.rconfig_device_id` column (mirrors the `{$RCONFIG.DEVICE_ID}` macro
  pattern).
- **PoE cycle** (`deploySnippet`): `POST /api/v1/snippets/<id>/deploy` with
  `{ devices:[id], dynamic_vars:{ interface_name:"1:7" } }`.
- **Auth quirk to preserve:** rConfig uses the custom `apitoken: <token>`
  header, **not** `Authorization: Bearer`. HTTPS-only; constructor rejects
  `http://`. Pull URL/token from `{$RCONFIG.URL}` / `{$RCONFIG.TOKEN}`.

---

## 5. Data flow

```
Browser (React)                Zabbix frontend                External systems
──────────────                 ───────────────                ────────────────
GET zabbix.php?action=closet.list
                        ─────► ActionClosetList → views/closet.list.view.php
                               + window.CLOSET_BOOT (id, name, counters)
GET zabbix.php?action=closet.list.data
                        ─────► ActionClosetListData
                               └─ InventoryStore::listAll() (DB)
GET zabbix.php?action=closet.view.data&uid=NN
                        ─────► ActionClosetViewData
                               └─ EnrichmentService::enrich(uid)
                                    ├─ SwitchClient ──────► Zabbix Item API (in-process)
                                    ├─ XIQFleetClient ────► XIQ /devices
                                    ├─ XIQClient ─────────► XIQ /devices/{id}/...
                                    └─ RConfigClient ─────► rConfig /devices, /snippets
POST closet.*.save      ─────► Action*Save → InventoryStore (DB only)
POST closet.poe.cycle   ─────► ActionPoeCycle → RConfigClient.deploySnippet (audited)
```

- **Reads** are read-through-cached. Detail page = 1 DB read + fan-out to the
  three clients, each APCu-cached at its own TTL. Merge happens in
  `EnrichmentService`, which emits the exact JSON shape the design expects
  (§7 contract).
- **Writes** touch only the authored DB. Sole exception: PoE-cycle (gated to
  `USER_TYPE_ZABBIX_ADMIN`, audited).
- **List-view counter sync:** persist `ports_total`/`ports_used` onto closet
  rows so the list view doesn't fan out per closet. A small refresh job
  (driven by a Zabbix `timeperiod` cron-style hook, or a manual "Refresh
  counters" action) walks closets and updates the cached numbers.

---

## 6. Database schema (authored layer)

Installed by `setup/schema.sql`, run idempotently from `Module.php::init()`.
Tables namespaced `tcs_closet_*` so upgrades stay quiet.

```sql
CREATE TABLE tcs_closet_schools (
  id           VARCHAR(16) PRIMARY KEY,                -- 'RHS'
  name         VARCHAR(128) NOT NULL,
  type         VARCHAR(16) NOT NULL,                   -- High|Middle|Elem|Admin
  zbx_group    VARCHAR(128),                            -- 'Site/Riverside High School'
  color_hue    SMALLINT
);

CREATE TABLE tcs_closet_closets (
  uid          INT AUTO_INCREMENT PRIMARY KEY,
  code         VARCHAR(64) UNIQUE NOT NULL,
  type         VARCHAR(8)  NOT NULL,                   -- MDF|IDF
  school_id    VARCHAR(16) NOT NULL,
  building     VARCHAR(64), floor INT, room VARCHAR(64),
  flagged      TINYINT(1) DEFAULT 0,
  flag_reason  VARCHAR(255), flag_tech VARCHAR(64), flag_date DATE,
  ports_total  INT DEFAULT 0,    -- cached counter (list view)
  ports_used   INT DEFAULT 0,    -- cached counter (list view)
  updated_at   DATETIME,
  CONSTRAINT fk_closet_school FOREIGN KEY (school_id) REFERENCES tcs_closet_schools(id)
);

CREATE TABLE tcs_closet_switches (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  closet_uid        INT NOT NULL,
  name              VARCHAR(64), vendor VARCHAR(32), model VARCHAR(64),
  ports             INT, poe TINYINT(1), uplinks INT, uplink_speed VARCHAR(16),
  serial            VARCHAR(64), stack_size INT DEFAULT 1,
  zabbix_hostid     VARCHAR(32),         -- maps to Zabbix host
  xiq_device_id     BIGINT,              -- maps to XIQ device
  rconfig_device_id INT,                 -- maps to rConfig device
  CONSTRAINT fk_switch_closet FOREIGN KEY (closet_uid) REFERENCES tcs_closet_closets(uid)
);

CREATE TABLE tcs_closet_power_units (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  closet_uid  INT NOT NULL,
  kind        VARCHAR(8) NOT NULL,        -- UPS|PDU
  model       VARCHAR(96),
  va          INT, load_pct INT, battery_pct INT, runtime_min INT,
  outlets     INT, load_amps DECIMAL(5,1)
);

CREATE TABLE tcs_closet_circuits (
  id INT AUTO_INCREMENT PRIMARY KEY,
  closet_uid INT NOT NULL,
  label VARCHAR(64)
);

CREATE TABLE tcs_closet_maintenance (
  id INT AUTO_INCREMENT PRIMARY KEY,
  closet_uid INT NOT NULL,
  date DATE, type VARCHAR(96), tone VARCHAR(16), tech VARCHAR(64), notes TEXT
);

CREATE TABLE tcs_closet_photos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  closet_uid INT NOT NULL,
  label VARCHAR(64),
  path  VARCHAR(255)
);

CREATE TABLE tcs_closet_audit (              -- PoE-cycle and other writes
  id INT AUTO_INCREMENT PRIMARY KEY,
  ts DATETIME NOT NULL,
  userid VARCHAR(32) NOT NULL,                -- CWebUser::$data['userid']
  action VARCHAR(64) NOT NULL,                -- 'poe.cycle'
  target VARCHAR(128),                         -- 'switch:88 port:1:7'
  result VARCHAR(255)
);
```

Use Zabbix's DB helpers (`DB::insert`, `DB::update`, `DBfetch(DBselect(...))`)
in `InventoryStore.php`; no PDO/raw connection needed.

Seeding: an importer that reads `design/project/app/data.js`'s deterministic
generator output as starter data so the UI is populated on day one, then have
operators correct it. Swap placeholder schools/models for the real district
list during seeding.

---

## 7. Frontend (design → module assets)

**Recreate the mockup pixel-for-pixel.** Per the design's README, the goal is
to match visual output and re-point the data layer. Concretely:

1. **`styles.css`, `icons.jsx`, `tweaks-panel.jsx`, `components.jsx`** — copy
   verbatim. The design system (Schibsted Grotesk + IBM Plex Mono, oklch
   accents, dark-mode block, density variants) is production-grade. The Tweaks
   panel persists client-side — fine, it's pure presentation.
2. **`App.jsx`** — replace `loadClosets()`/localStorage with:
   - `window.CLOSET_BOOT` (inlined by `views/closet.list.view.php`) on first
     paint,
   - `fetch('zabbix.php?action=closet.list.data')` on mount,
   - `fetch('zabbix.php?action=closet.view.data&uid=NN')` on detail open,
   - save handlers POST to `closet.*.save` action URLs, then refetch.
   Keep the sidebar, topbar crumbs, ⌘K search focus, and toast system.
3. **`ListView.jsx`** — keep the stat strip, MDF/IDF + school + flagged-only
   filters, sortable table, table↔card toggle, mobile cards. Feed it the API
   list payload instead of `window.SeedData`.
4. **`DetailView.jsx`** — keep switch rows (port-usage bar, mgmt IP, uplinks,
   serial, stack/PoE tags), multi-unit **Power panel**, photo grid + lightbox,
   maintenance timeline, flag banner. **Bind live fields** from enrichment:
   port `used` from Zabbix link-state, PoE tags from `poe.dstatus`, stack size
   from `stacking.member`, a "config backup age" chip from rConfig.
5. **`Modals.jsx`** — Add/Edit closet, Flag, Add switch, Log maintenance, Edit
   power. On save, POST to the API. When adding a switch, offer **autofill
   from Zabbix/XIQ** by hostid/device-id lookup so model/serial/ports come
   from the source of truth.

**Asset loading:** views emit `<link rel="stylesheet" href="modules/closet_inventory/assets/styles.css">`
plus the React + Babel-standalone CDN tags, then a `<script type="text/babel" data-presets="env,react" data-type="module" src="...">` tag per JSX file — same as `tcs_dashboard`'s asset wiring.

**Enrichment JSON contract** — `EnrichmentService` emits the exact closet
shape the React components already consume:

```jsonc
{
  "uid": 42, "code": "RHS-IDF-203", "type": "IDF",
  "schoolId": "RHS", "building": "Science Wing", "floor": 2, "room": "IDF 207",
  "switches": [{
    "name":"RHS-IDF-SW1","vendor":"Cisco","model":"Catalyst 9300-48P",
    "ports":48,"used":31,            // LIVE  ← Zabbix link-state
    "poe":true,"uplinks":2,"uplinkSpeed":"10G SFP+",
    "mgmtIp":"10.10.3.5","serial":"FOC2412ABCD","stack":2,   // LIVE
    "poeStatus":"DeliveringPower",   // LIVE   (extra field; render as tag)
    "configBackupAgeDays":1,         // LIVE   from rConfig (extra)
    "zabbixHostid":"10523","xiqDeviceId":1234567,"rconfigDeviceId":88
  }],
  "power": { "upsList":[...], "pduList":[...], "circuits":[...] },  // authored
  "maintenance":[...], "photos":[...],
  "portsTotal":48, "portsUsed":31,
  "flagged":true, "flagReason":"...", "flagDate":"2026-05-20", "flagTech":"...",
  "updated":"2026-05-28",
  "_live": { "xiqRateLimitRemaining": 6800, "sources":{"zabbix":"ok","xiq":"ok","rconfig":"stale"} }
}
```

Add only **extra** fields (`poeStatus`, `configBackupAgeDays`, `_live`); never
rename the existing ones, so the design's components render unchanged and new
tags light up incrementally.

---

## 8. Build order (phased milestones)

**Phase 1 — Module skeleton + authored CRUD (no live data yet).**
`manifest.json`, `Module.php`, `setup/schema.sql` idempotent install, the
`ActionBase`/`ActionDataBase` shell, `InventoryStore`, the seven write
actions, photo upload. Port the design frontend wired to these endpoints
(replacing localStorage). Seed from `data.js`. *Exit:* the full mockup works
against the real DB; all add/edit/flag/maintenance/power/photo flows persist;
module enables/disables cleanly from **Administration → General → Modules**.

**Phase 2 — Zabbix enrichment.**
Drop `SwitchClient.php` into `lib/` verbatim. Add `EnrichmentService`. Adopt
the `Site/*` host-group convention for schools (mirror
`reference/actions/ActionSwitches.php::collectFleet()`). Live port utilization,
PoE tags, stack health on the detail page; problems → Service-queue/flag
feed. APCu cache + counter-refresh action. *Exit:* port bars and switch tags
reflect Zabbix.

**Phase 3 — XIQ enrichment + switch autofill.**
Drop `XIQFleetClient.php` and `XIQClient.php` into `lib/` verbatim. Add-switch
autofill from XIQ device id; reachability cross-check; rate-limit banner.
*Exit:* Extreme gear self-populates; quota handling verified.

**Phase 4 — rConfig: backup-age + PoE cycle.**
Drop `RConfigClient.php` into `lib/` verbatim. Config-backup-age chip;
`resolveDeviceId` with stored `rconfig_device_id` pin; PoE-cycle action button
gated to `USER_TYPE_ZABBIX_ADMIN`, audited via `tcs_closet_audit`. *Exit:*
operators can PoE-cycle a port from the closet detail card.

**Phase 5 — Polish.** Export (CSV/PDF) for the list `Export` button (currently
a stub in the design), Service-queue and Schools nav pages (design stubs them),
optional Vite build for assets.

---

## 9. Config, secrets, caching, safety

- **Secrets via Zabbix user macros** at the global level (admins only): mirror
  the names the clients document — `{$XIQ.API_TOKEN}` (or `{$XIQ.USERNAME}` /
  `{$XIQ.PASSWORD}`), `{$RCONFIG.URL}`, `{$RCONFIG.TOKEN}`. A small helper in
  `lib/Config.php` resolves these via `API::UserMacro()->get()` once per
  request and caches in APCu. Never log token values; the clients already
  mark params `#[\SensitiveParameter]`.
- **Cache** via shared `lib/Cache.php` (APCu primary, `/tmp/closet_inv_cache/`
  0700 fallback) — lifted from `XIQClient` internals. TTLs: Zabbix ports/PoE
  60–120 s, XIQ devices 5 min / clients 60 s, rConfig device list 5 min.
- **TLS:** verify on by default everywhere; `XIQClient` and `RConfigClient`
  already enforce HTTPS.
- **Auth/permissions:** read endpoints gated to `USER_TYPE_ZABBIX_USER` via
  `ActionDataBase::checkPermissions()`. Write endpoints to
  `USER_TYPE_ZABBIX_USER` for inventory, `USER_TYPE_ZABBIX_ADMIN` for PoE
  cycle. Audit row written on every PoE cycle.
- **Failure isolation:** if one external system is down, enrichment must
  degrade gracefully — render authored data + `sources.<system>:"down"`
  rather than failing the page. Reference `ActionDataBase` already returns
  partial payloads on `Throwable`; mirror that posture.

---

## 10. Open decisions — need answers before Phase 1

1. **Zabbix version target:** what's the production Zabbix version? Affects
   `manifest_version` and a couple of API param shapes (e.g. `problem.get`
   between 6.0 / 6.4 / 7.0).
2. **School ↔ host-group mapping:** confirm `Site/*` prefix convention is
   district-wide, or supply the actual group naming.
3. **XIQ scope:** are switches actually managed in ExtremeCloud IQ, or is XIQ
   AP-only at TCS? If AP-only, XIQ enrichment narrows to wireless context in
   a closet and Phase 3 shrinks.
4. **rConfig snippet:** confirm the PoE-cycle snippet id and its
   `interface_name` placeholder format (`"1:7"` style) for `deploySnippet`.
5. **Photos:** stored on the Zabbix frontend host's local disk
   (`/var/lib/closet-inventory/photos/`), or an object store / network share
   mounted in? Local disk is simplest; document it as the v1 default.
6. **Schema install:** auto-run `setup/schema.sql` from `Module.php::init()`
   (convenient) or require an admin to run it manually (safer)? Recommend
   auto-run with a `tcs_closet_schema_version` row to track migrations.

---

## Reference appendix — files in `reference/` to read first

- `reference/actions/ActionDataBase.php` — base controller posture (401 on
  unauth, partial payload on error). Copy verbatim into `actions/`.
- `reference/actions/ActionSwitches.php` — `Site/*` host-group → school
  derivation and the skeleton/counters split worth mirroring for list vs.
  detail. Read for pattern, don't copy whole.
- `reference/lib/SwitchClient.php` — copy verbatim; the one-shot `snapshot()`
  `item.get` and key-regex/PoE-valuemap parsing.
- `reference/lib/RConfigClient.php` — copy verbatim; auth quirk + resolve
  logic.
- `reference/lib/XIQClient.php` / `XIQFleetClient.php` — copy verbatim; read
  the G3–G6 docblock corrections and the rate-limit/cache strategy.
