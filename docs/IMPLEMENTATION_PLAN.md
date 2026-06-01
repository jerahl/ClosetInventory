# Switch Closet Inventory — Implementation Plan for Claude Code

A PHP web application that inventories every switch closet (MDF/IDF) across the
district, recreates the **Switch Closet Inventory** Claude Design mockup
pixel-for-pixel, and enriches the inventory with **live** data from three
systems: **Zabbix**, **ExtremeCloud IQ (XIQ)**, and **rConfig**.

This plan is written for Claude Code. It assumes the design bundle and the
`jerahl/ZabbixCustomDashboard` repo are both available locally (clone the repo
into a sibling directory for reference). Read **§0** first — it changes how
everything else is built.

---

## 0. The one insight that shapes the whole app

The mockup persists everything to `localStorage`. In production that does **not**
work, because **no single system owns this data**. Split the model in two:

| Layer | Owner | Source of truth |
|---|---|---|
| **Inventory of record** — closets, building/room/floor, switch↔host mappings, UPS/PDU/circuit records, photos, maintenance log, service flags | **This app's own database** | The app itself (operators enter it) |
| **Live operational state** — port up/down/utilization, PoE status & draw, stack-member health, active problems, config-backup freshness, AP/switch reachability | **Zabbix / XIQ / rConfig** | Pulled on demand, cached, never authored here |

So the architecture is: **an authored inventory database + a read-through
enrichment layer over three APIs.** A closet record stores stable facts and a
set of *external keys* (`zabbix_hostid`, `xiq_device_id`, `rconfig_device_id`);
at render time the app fans out to the three clients, merges live state onto the
stored record, and the design's port-bars / gauges / flags reflect reality.

Do not try to derive the inventory purely from Zabbix hosts — Zabbix has no
concept of "closet", "UPS battery health entered by a tech", "photo of the
rack", or "maintenance note". Those are first-class authored data.

---

## 1. Architecture decision (resolve with Stephen first)

The reference repo is a **Zabbix frontend module** (`tcs_dashboard`). Two viable
targets for this new app:

- **Option A — Standalone PHP app** (recommended given the "web php application"
  framing). Own routing, own auth, own DB. Talks to Zabbix over the **JSON-RPC
  API** (`item.get`, `host.get`, `hostgroup.get`, `history.get`, `problem.get`).
  Truly standalone; deployable anywhere; not coupled to a Zabbix upgrade.
  **Cost:** `SwitchClient.php` must be re-pointed from the in-process `API::`
  facade to JSON-RPC (see §4.1).
- **Option B — New Zabbix frontend module** (`closet_inventory`), sibling to
  `tcs_dashboard`. Reuses Zabbix session/auth and the native `API::Item()->get()`
  calls in `SwitchClient.php` **unchanged**. Fastest path to live switch data,
  consistent with the existing ecosystem. **Cost:** needs a DB for the authored
  layer anyway (Zabbix modules can use the Zabbix DB connection or a separate
  one), and the app lives inside Zabbix's menu/permission model.

**Recommendation:** Build **Option A**. The plan below assumes A but calls out
the few places where B would differ. The `RConfigClient`, `XIQClient`, and
`XIQFleetClient` classes are pure-HTTP and **port to either option unchanged.**

---

## 2. Tech stack

- **PHP 8.1+** (the reference module pins 8.0 and polyfills `array_is_list`; a
  standalone app can require 8.1 and drop the polyfill).
- **Slim 4** (or Laravel if Stephen prefers a batteries-included stack) for
  routing + PSR-7. Slim keeps it close to the lightweight controller style of
  the reference repo.
- **MariaDB/MySQL** for the authored inventory (Postgres equally fine).
- **APCu** for the API read-through cache, with a `/tmp` filesystem fallback —
  exactly the strategy `XIQClient`/`XIQFleetClient` already implement.
- **Frontend:** keep the design's vanilla **React 18 + Babel-standalone** as-is
  for v1 (zero build step, matches the mockup and the `tcs_dashboard` assets),
  OR migrate to a Vite build. Recommend shipping v1 with the no-build setup to
  get pixel-parity fast, then optionally Vite-ify. The design's CSS is already
  production-grade — reuse `styles.css` verbatim.
- **Composer** for autoloading (`Closet\Inventory\` PSR-4 namespace) and
  `guzzlehttp/guzzle` (or keep raw cURL to mirror the reference clients exactly).

---

## 3. Repository layout

```
switch-closet-inventory/
├── composer.json
├── public/
│   ├── index.php                 # front controller / Slim bootstrap
│   └── app/                       # ← the design's frontend, ported
│       ├── styles.css            # COPY verbatim from the design bundle
│       ├── icons.jsx             # COPY verbatim
│       ├── components.jsx        # adapt: drop SeedData coupling
│       ├── ListView.jsx          # adapt: data from API not window.SeedData
│       ├── DetailView.jsx        # adapt
│       ├── Modals.jsx            # adapt: POST to API on save
│       ├── tweaks-panel.jsx      # COPY verbatim (dark/accent/density)
│       └── App.jsx               # adapt: fetch + persist via API, not localStorage
├── src/
│   ├── Lib/
│   │   ├── ZabbixClient.php       # NEW — JSON-RPC wrapper (Option A)
│   │   ├── SwitchClient.php       # PORT from repo (API:: → ZabbixClient)
│   │   ├── XIQClient.php          # COPY from repo (per-device telemetry)
│   │   ├── XIQFleetClient.php     # COPY from repo (fleet device list)
│   │   ├── RConfigClient.php      # COPY from repo (device resolve + snippet deploy)
│   │   └── Cache.php              # APCu + /tmp fallback (lift from XIQClient internals)
│   ├── Service/
│   │   ├── ClosetRepository.php   # CRUD over the authored DB
│   │   ├── EnrichmentService.php  # merges live state onto closet records
│   │   └── SyncService.php        # optional background reconcile (cron)
│   ├── Http/
│   │   ├── ClosetController.php    # list/detail/create/update
│   │   ├── ClosetDataController.php# JSON: live-enriched closet payloads
│   │   ├── SwitchController.php    # add switch, port detail, PoE-cycle action
│   │   ├── PowerController.php     # UPS/PDU/circuit edit
│   │   ├── MaintenanceController.php
│   │   ├── FlagController.php
│   │   └── PhotoController.php      # upload/serve closet photos
│   └── Support/Config.php          # env + secrets loader
├── migrations/                     # SQL schema (§6)
├── config/
│   └── settings.php                # API endpoints, macro→credential map
└── .env                            # secrets (NEVER commit)
```

Option B differs only in shape: `Module.php` + `manifest.json` + `actions/` +
`views/` + `assets/` mirroring `tcs_dashboard`, with the DB layer behind a
`Lib/InventoryStore.php`. The `src/Lib` client classes are identical.

---

## 4. The three API integrations

All three follow the reference repo's conventions: HTTPS-only, TLS verify on by
default, short timeouts, APCu+filesystem cache, and **credentials from config/
secrets — never hardcoded.** Lift the docblocks; they encode hard-won field
corrections (the XIQ "G3–G6" notes especially).

### 4.1 Zabbix — switch fleet, ports, PoE, stacking, problems

**What it provides:** the live half of every switch row and the service-flag
signals. Source the operationally-interesting values from items already
populated by the *Extreme EXOS by SNMP w/ PoE* template, per `SwitchClient.php`:

- `stacking.member[<n>]` → stack member presence/role
- `net.if.status[ifOperStatus.<member>.<port>]` → per-port link state
- `snmp.interfaces.poe.dstatus[<member>.<port>]` → PoE detection (valuemap
  1=Disabled, 2=Searching, 3=DeliveringPower, 4=Fault, 5=Test, 6=OtherFault)
- `net.if.mac[<member>.<port>]` → FDB/MAC-learning rows
- `host.get` for display name; `problem.get`/`trigger.get` for the flag feed

**Site/school grouping** (critical, reuse the existing convention): schools are
Zabbix **host groups** prefixed `Site/*`. `ActionSwitches::collectFleet()`
strips the `Site/` prefix to get the school name and slugs it to an id — adopt
the same convention so closet records map cleanly to school tags in the design.

**Performance pattern to copy:** `SwitchClient::snapshot()` does **one**
`item.get` pulling every item on a host, then parses locally — instead of 6
round-trips per switch. Preserve this; it's the difference between a snappy
detail page and a slow one.

**Option A change:** the repo's `SwitchClient` calls `API::Item()->get([...])`
in-process. For a standalone app, write a thin `ZabbixClient` that speaks
JSON-RPC and expose the same method names so the parse logic is a copy/paste:

```php
// src/Lib/ZabbixClient.php  (sketch)
final class ZabbixClient {
    public function __construct(
        private string $url,            // https://zabbix.tcs.../api_jsonrpc.php
        #[\SensitiveParameter] private string $token,  // {$ZBX_API_TOKEN}
        private bool $verifySsl = true
    ) {}
    /** @return array<int,array<string,mixed>> */
    public function call(string $method, array $params): array {
        // POST {"jsonrpc":"2.0","method":$method,"params":$params,"id":1}
        // Header: Authorization: Bearer <token>   (Zabbix 6.4+/7.0)
        //   (older 6.0: "auth" field in body instead)
        // Return $resp['result']; throw on $resp['error'].
    }
    public function itemGet(array $p): array     { return $this->call('item.get', $p); }
    public function hostGet(array $p): array     { return $this->call('host.get', $p); }
    public function hostgroupGet(array $p): array { return $this->call('hostgroup.get', $p); }
    public function historyGet(array $p): array  { return $this->call('history.get', $p); }
    public function problemGet(array $p): array  { return $this->call('problem.get', $p); }
}
```

Then in the ported `SwitchClient`, replace `API::Item()->get($p)` with
`$this->zbx->itemGet($p)`. Everything downstream (the key-regex parsing, PoE
valuemap, stack-role labels, top-PoE-consumers rollup) is unchanged.

### 4.2 ExtremeCloud IQ — Extreme switch inventory & wireless context

**What it provides:** for Extreme-managed gear, XIQ is a second inventory
source (model, serial, software version, connected/last-seen, network policy)
and the per-device telemetry behind the AP/wireless context shown in a closet.
Use it to (a) auto-populate switch identity fields when adding a switch, and
(b) cross-check Zabbix's view of reachability.

**Two clients, two scopes** (copy both verbatim):

- `XIQFleetClient` — cloud-wide list endpoints (`/devices`, `/clients/active`),
  curl_multi paging, per-endpoint APCu TTLs (devices 5 min, clients 60 s).
  `getDevices()` is the fleet inventory feed.
- `XIQClient` — per-device queries (`/devices/{id}`, network policy, SSIDs,
  alarms, `rebootDevice()`), JWT-or-token auth.

**Auth (recommended):** `XIQClient::fromToken($token)` / `XIQFleetClient::fromToken($token)`
with a **permanent** API token from `{$XIQ_API_TOKEN}`. The username/password
JWT flow (`fromCredentials`) is the fallback only.

**Honor the baked-in field corrections** (don't "fix" them):

- G3 `mac_address` on `/devices/{id}` is the wireless **base** MAC, not eth0 —
  normalize with `macInsertColons()` for display; match PF/Zabbix on eth0 MAC.
- G4 `/clients/active` filter is `deviceIds` (camelCase plural).
- G5 always append `views=FULL` to `/clients/active` for rssi/snr/channel.
- G6 `/devices/{id}/interfaces/wifi` needs a ≥10-min `startTime`/`endTime`
  window; use the 15-min trailing window constant.

**Rate limit:** 7,500 req/hr per VIQ, **shared across all integrations**.
Surface `getRateLimitRemaining()`; show a warning banner under 500; catch
`XIQRateLimitException` (429) and render a "quota exceeded" state that skips
further XIQ calls for that page load. The 30 s-cadence + APCu sizing in the
docblocks keeps a 10-worker deployment within budget.

### 4.3 rConfig — config-backup freshness & the PoE-cycle action

**What it provides:** (a) a per-switch "config backup is N days old" badge for
the maintenance/health view, and (b) the **PoE-cycle** action button on the
port/switch detail card. Copy `RConfigClient.php` verbatim.

- **Device resolution** (`resolveDeviceId`): match a Zabbix host to its rConfig
  device id by SNMP IP → any-interface IP → technical hostname → visible name.
  Ambiguous matches throw; let an operator pin the id via a stored
  `rconfig_device_id` on the closet's switch record (the app's equivalent of the
  `{$RCONFIG.DEVICE_ID}` macro).
- **PoE cycle** (`deploySnippet`): `POST /api/v1/snippets/<id>/deploy` with
  `{ devices:[id], dynamic_vars:{ interface_name:"1:7" } }`.
- **Auth quirk to preserve:** rConfig uses a custom `apitoken: <token>` header,
  **not** `Authorization: Bearer`. HTTPS-only; constructor rejects `http://`.

---

## 5. Data flow

```
Browser (React)                PHP app                     External systems
──────────────                 ───────                     ────────────────
GET /api/closets        ─────► ClosetController
                               └─ ClosetRepository (DB)  ── authored facts
GET /api/closets/{id}   ─────► ClosetDataController
                               └─ EnrichmentService
                                    ├─ SwitchClient ──────► Zabbix (item/history/problem)
                                    ├─ XIQFleetClient ────► XIQ /devices
                                    ├─ XIQClient ─────────► XIQ /devices/{id}/...
                                    └─ RConfigClient ─────► rConfig /devices, /snippets
POST /api/.../save      ─────► *Controller → DB (authored only)
POST /api/.../poe-cycle ─────► SwitchController → RConfigClient.deploySnippet
```

- **Reads** are read-through-cached. Detail page = 1 DB read + a fan-out to the
  three clients, each APCu-cached at its own TTL. Merge happens in
  `EnrichmentService`, which outputs the exact JSON shape the design expects
  (see §6 model + §7 contract).
- **Writes** touch only the authored DB. The single exception is the PoE-cycle
  action, which is a write to rConfig (and is gated/audited).
- **Optional `SyncService` (cron):** every N minutes, refresh derived counters
  (`ports_total`, `ports_used`) onto closet rows so the **list view** is fast
  without fanning out per-closet. The list shows cached counters; the detail
  view does the live fan-out.

---

## 6. Database schema (authored layer)

Maps directly from the design's data model in `app/data.js`. Each closet keeps
external keys so enrichment can find live state.

```sql
-- schools = the design's "schools" (Site/* host groups mirror these)
CREATE TABLE schools (
  id           VARCHAR(16) PRIMARY KEY,          -- 'RHS'
  name         VARCHAR(128) NOT NULL,
  type         ENUM('High','Middle','Elem','Admin') NOT NULL,
  zbx_group    VARCHAR(128),                      -- 'Site/Riverside High School'
  color_hue    SMALLINT                           -- oklch hue for the school tag
);

CREATE TABLE closets (
  uid          INT AUTO_INCREMENT PRIMARY KEY,
  code         VARCHAR(64) UNIQUE NOT NULL,        -- 'RHS-IDF-203'
  type         ENUM('MDF','IDF') NOT NULL,
  school_id    VARCHAR(16) NOT NULL REFERENCES schools(id),
  building     VARCHAR(64), floor INT, room VARCHAR(64),
  flagged      TINYINT(1) DEFAULT 0,
  flag_reason  VARCHAR(255), flag_tech VARCHAR(64), flag_date DATE,
  updated_at   DATETIME
);

CREATE TABLE switches (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  closet_uid   INT NOT NULL REFERENCES closets(uid),
  name         VARCHAR(64), vendor VARCHAR(32), model VARCHAR(64),
  ports        INT, poe TINYINT(1), uplinks INT, uplink_speed VARCHAR(16),
  serial       VARCHAR(64), stack_size INT DEFAULT 1,
  -- external keys for enrichment (nullable until mapped):
  zabbix_hostid     VARCHAR(32),
  xiq_device_id     BIGINT,
  rconfig_device_id INT
  -- live fields (used/poe_status/etc.) are NOT stored; merged at read time
);

CREATE TABLE power_units (                          -- multi-UPS / multi-PDU
  id          INT AUTO_INCREMENT PRIMARY KEY,
  closet_uid  INT NOT NULL REFERENCES closets(uid),
  kind        ENUM('UPS','PDU') NOT NULL,
  model       VARCHAR(96),
  va          INT,            -- UPS
  load_pct    INT,            -- UPS (authored or polled-then-cached)
  battery_pct INT,            -- UPS
  runtime_min INT,            -- UPS
  outlets     INT,            -- PDU
  load_amps   DECIMAL(5,1)    -- PDU
);

CREATE TABLE circuits (
  id INT AUTO_INCREMENT PRIMARY KEY,
  closet_uid INT NOT NULL REFERENCES closets(uid),
  label VARCHAR(64)            -- 'A — 20A / 120V'
);

CREATE TABLE maintenance (
  id INT AUTO_INCREMENT PRIMARY KEY,
  closet_uid INT NOT NULL REFERENCES closets(uid),
  date DATE, type VARCHAR(96), tone VARCHAR(16), tech VARCHAR(64), notes TEXT
);

CREATE TABLE photos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  closet_uid INT NOT NULL REFERENCES closets(uid),
  label VARCHAR(64),           -- 'Rack — front'
  path  VARCHAR(255)            -- stored upload (replaces striped placeholders)
);
```

Seeding: write a one-shot importer that reads `app/data.js`'s deterministic
generator output as **realistic starter data** so the UI is populated on day one,
then have operators correct it. (Stephen flagged in the design chat that schools/
models are placeholders — swap in the real district list during seeding.)

---

## 7. Frontend port (design → app)

**Recreate the mockup pixel-for-pixel.** The design is HTML/CSS/JS; per its
README, match the visual output and re-point the data layer. Concretely:

1. **`styles.css`, `icons.jsx`, `tweaks-panel.jsx`** — copy verbatim. The whole
   design system (Schibsted Grotesk + IBM Plex Mono, oklch `--primary`/`--teal`/
   `--amber`/`--rose`/`--violet`, shadows, radii, the `[data-theme="dark"]`
   block, density variants) is already production quality. **Keep the Tweaks
   panel**: dark mode, accent (Blue/Indigo/Teal/Violet, JS-driven oklch hue
   swap in `App.jsx`), and density (compact/regular/comfy). These persist
   client-side — fine, they're pure presentation.
2. **`App.jsx`** — replace the `loadClosets()`/`localStorage` core with:
   - `GET /api/closets` (list, cached counters) on mount,
   - `GET /api/closets/{uid}` (live-enriched) on detail open,
   - save handlers `POST`/`PATCH` to the controllers, then refetch.
   Keep the sidebar (Switch closets / Switches / Power / Service queue /
   Maintenance / Schools), topbar crumbs, ⌘K search focus, and toast system.
3. **`ListView.jsx`** — keep the stat strip (closets, managed switches, port
   utilization, flagged), MDF/IDF + school + flagged-only filters, sortable
   table, table↔card toggle, mobile cards. Feed it the API list payload instead
   of `window.SeedData`.
4. **`DetailView.jsx`** — keep switch rows (port-usage bar, mgmt IP, uplinks,
   serial, stack/PoE tags), the multi-unit **Power panel** (per-UPS load gauge,
   battery, runtime, est. draw; PDU rows; circuit chips), photo grid + lightbox,
   maintenance timeline, and the flag banner. **Bind live fields** from
   enrichment: port `used` from Zabbix link-state, PoE tags from `poe.dstatus`,
   stack size from `stacking.member`, a "config backup age" chip from rConfig,
   and a problems→flag feed.
5. **`Modals.jsx`** — Add/Edit closet, Flag, Add switch, Log maintenance, Edit
   power (multi-UPS/PDU/circuit repeater). On save, POST to the API. When adding
   a switch, offer **autofill from XIQ/Zabbix** by hostid/device-id lookup so
   model/serial/ports come from the source of truth.

**Enrichment JSON contract** — `EnrichmentService` must emit the exact closet
shape the React components already consume (so the port is mechanical):

```jsonc
{
  "uid": 42, "code": "RHS-IDF-203", "type": "IDF",
  "schoolId": "RHS", "building": "Science Wing", "floor": 2, "room": "IDF 207",
  "switches": [{
    "name":"RHS-IDF-SW1","vendor":"Cisco","model":"Catalyst 9300-48P",
    "ports":48,"used":31,            // used ← Zabbix link-state (LIVE)
    "poe":true,"uplinks":2,"uplinkSpeed":"10G SFP+",
    "mgmtIp":"10.10.3.5","serial":"FOC2412ABCD","stack":2,   // stack ← Zabbix (LIVE)
    "poeStatus":"DeliveringPower",   // LIVE  (extra field; render as tag)
    "configBackupAgeDays":1,         // LIVE from rConfig (extra field)
    "zabbixHostid":"10523","xiqDeviceId":1234567,"rconfigDeviceId":88
  }],
  "power": { "upsList":[...], "pduList":[...], "circuits":[...] },  // authored
  "maintenance":[...], "photos":[...],
  "portsTotal":48, "portsUsed":31,   // recomputed from live `used`
  "flagged":true, "flagReason":"...", "flagDate":"2026-05-20", "flagTech":"...",
  "updated":"2026-05-28",
  "_live": { "xiqRateLimitRemaining": 6800, "sources":{"zabbix":"ok","xiq":"ok","rconfig":"stale"} }
}
```

Add only **extra** fields (`poeStatus`, `configBackupAgeDays`, `_live`); never
rename the existing ones, so the design's components keep rendering unchanged
and you light up new tags incrementally.

---

## 8. Build order (phased milestones)

**Phase 1 — Skeleton + authored CRUD (no live data yet).**
Slim bootstrap, DB migrations, `ClosetRepository`, the five write controllers,
photo upload. Port the design frontend wired to these endpoints (replacing
localStorage). Seed from `data.js`. *Exit:* the full mockup works against a real
DB, all add/edit/flag/maintenance/power/photo flows persist.

**Phase 2 — Zabbix enrichment.**
`ZabbixClient` (JSON-RPC) + ported `SwitchClient` + `EnrichmentService`. Adopt
the `Site/*` host-group convention for schools. Live port utilization, PoE tags,
stack health on the detail page; problems→Service-queue/flag feed. Cache +
list-view counter sync (`SyncService` cron). *Exit:* port bars and switch tags
reflect Zabbix.

**Phase 3 — XIQ enrichment + switch autofill.**
Drop in `XIQFleetClient`/`XIQClient`. Add-switch autofill from XIQ device id;
reachability cross-check; rate-limit banner. *Exit:* Extreme gear self-populates;
quota handling verified.

**Phase 4 — rConfig: backup-age + PoE cycle.**
Drop in `RConfigClient`. Config-backup-age chip; `resolveDeviceId` with stored
`rconfig_device_id` pin; PoE-cycle action button (audited, permission-gated).
*Exit:* operators can PoE-cycle a port from the closet detail card.

**Phase 5 — Polish.** Export (CSV/PDF) for the list `Export` button (currently a
stub in the design), Service-queue and Schools nav pages (design stubs them),
auth/roles, optional Vite migration.

---

## 9. Config, secrets, caching, safety

- **Secrets** in `.env` / a secrets store, loaded via `Support\Config`. Mirror
  the macro names the clients document: `ZBX_API_URL`/`ZBX_API_TOKEN`,
  `XIQ_API_TOKEN` (or `XIQ_USERNAME`/`XIQ_PASSWORD`), `RCONFIG_URL`/
  `RCONFIG_TOKEN`. Never log tokens; the clients already mark params
  `#[\SensitiveParameter]`.
- **Cache** via a shared `Lib/Cache.php` (APCu primary, `/tmp/closet_inv_cache/`
  0700 fallback) — lifted from the XIQ client's internal cache. TTLs: Zabbix
  ports/PoE 60–120 s, XIQ devices 5 min / clients 60 s, rConfig device list
  5 min.
- **TLS:** verify on by default everywhere; expose a per-system "insecure" flag
  only for lab use. rConfig/XIQ clients already enforce HTTPS.
- **Auth/permissions:** the PoE-cycle write must be role-gated and audited
  (who/when/which port). Read endpoints behind app login. (Option B inherits
  Zabbix's `USER_TYPE_ZABBIX_USER` gate via the `ActionDataBase` pattern.)
- **Failure isolation:** if one of the three systems is down, enrichment must
  degrade gracefully — render authored data + a `sources.<system>:"down"` marker
  rather than failing the page. The reference data controllers already log and
  return partial payloads on `Throwable`; copy that posture.

---

## 10. Open decisions — need Stephen's input before Phase 1

1. **Architecture:** Option A (standalone PHP, recommended) or Option B (Zabbix
   module sibling to `tcs_dashboard`)? This is the only choice that changes the
   skeleton.
2. **Zabbix auth/version:** target 6.0 (`auth` field), or 6.4/7.0 (Bearer token)?
   Affects `ZabbixClient::call()`.
3. **School ↔ host-group mapping:** confirm the `Site/*` prefix convention is in
   use district-wide, or supply the actual group naming.
4. **XIQ scope:** are switches actually managed in ExtremeCloud IQ, or is XIQ
   AP-only at TCS? If AP-only, XIQ enrichment narrows to wireless context in a
   closet and Phase 3 shrinks.
5. **rConfig snippet:** confirm the PoE-cycle snippet id and its `interface_name`
   placeholder format (`"1:7"` style) for `deploySnippet`.
6. **Photos:** local disk, object storage (S3-compatible), or a network share?
7. **Frontend build:** ship v1 with the design's no-build React+Babel (fast,
   pixel-exact) or invest in Vite up front?
8. **Auth provider:** app-local accounts, or SSO/LDAP to match Zabbix?

---

## Reference appendix — files worth reading in `jerahl/ZabbixCustomDashboard`

- `tcs_dashboard/lib/RConfigClient.php` — copy verbatim; auth quirk + resolve logic.
- `tcs_dashboard/lib/XIQClient.php` / `XIQFleetClient.php` — copy verbatim; read the
  G3–G6 docblock corrections and the rate-limit/cache strategy.
- `tcs_dashboard/lib/SwitchClient.php` — port `API::` → `ZabbixClient`; keep the
  one-shot `snapshot()` item.get and the key-regex/PoE-valuemap parsing.
- `tcs_dashboard/actions/ActionSwitches.php` — the `Site/*` host-group → school
  derivation and the skeleton/counters split worth mirroring for list vs detail.
- `tcs_dashboard/actions/ActionDataBase.php` — JSON controller posture (401 on
  unauth, partial payload on error).
- `tcs_dashboard/manifest.json` / `Module.php` — only relevant if you choose
  Option B.
