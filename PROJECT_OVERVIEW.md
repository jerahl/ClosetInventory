# Closet Inventory — Project Overview

**Closet Inventory** is a [Zabbix](https://www.zabbix.com/) 7.0 frontend module
(`closet_inventory`) that maintains a district-wide inventory of every **switch
closet** (MDF/IDF) for Tuscaloosa City Schools (TCS) and enriches that inventory
with **live operational data** pulled on demand from three network systems.

It is the production implementation of a Claude Design mockup, recreated
pixel-for-pixel and re-pointed from browser `localStorage` to real backing
systems.

## What it does

The module gives network operators a single place to:

- **Catalog switch closets** across the district — building, floor, room, type
  (MDF/IDF), and which school each belongs to.
- **Record stable, authored facts** that no monitoring system owns: switch
  models and serials, UPS/PDU/circuit power records, rack photos, maintenance
  logs, and service flags.
- **See live state** layered on top of the authored record at render time —
  per-port up/down and utilization, PoE status and draw, stack-member health,
  active problems, config-backup freshness, and device reachability.
- **Take action** — flag a closet for service, log maintenance, upload photos,
  and (when configured) PoE-cycle a port directly from the closet detail card.

## The core architectural idea

No single system owns this data, so the model is split in two:

| Layer | Source of truth | Owner |
|---|---|---|
| **Inventory of record** — closets, switch↔host mappings, power records, photos, maintenance, flags | The module's own `tcs_closet_*` DB tables (in the Zabbix DB) | Operators, via this module |
| **Live operational state** — port status, PoE, stack health, problems, backup age | Zabbix / XIQ / rConfig | Pulled on demand, cached, never authored here |

Each closet stores stable facts plus a set of *external keys*
(`zabbix_hostid`, `xiq_device_id`, `rconfig_device_id`). At render time a
read-through **enrichment layer** fans out to the three clients, merges live
state onto the stored record, and the UI's port bars, gauges, and flags reflect
reality.

## The three integrations

1. **Zabbix** (in-process via the Item API) — per-port link state, PoE
   detection, stack-member presence, MAC learning, and the problem feed. Schools
   map to Zabbix host groups via the `Site/*` prefix convention.
2. **ExtremeCloud IQ (XIQ)** — switch/AP device data for autofill (model,
   serial, ports), reachability cross-checks, and wireless context. Rate-limit
   aware (7,500 req/hr per VIQ).
3. **rConfig** — config-backup-age chip and the audited PoE-cycle action.

If any external system is down, enrichment degrades gracefully: authored data
still renders and the source is marked `down`/`stale` rather than failing the
page.

## Tech stack

- **Backend:** PHP 8.0+, Zabbix 7.0 LTS frontend module API
  (`manifest_version: 2.0`); DB access via Zabbix's `DB::`/`DBfetch` helpers.
- **Frontend:** React 18 + Babel-standalone (no build step), matching the
  sibling `tcs_dashboard` asset posture.
- **Caching:** APCu with a `/tmp` filesystem fallback.
- **Secrets:** Zabbix global user macros (`{$XIQ.API_TOKEN}`, `{$RCONFIG.URL}`,
  `{$RCONFIG.TOKEN}`) — never hardcoded.

## Repository layout

```
ClosetInventory/
├── modules/closet_inventory/   # the deployable Zabbix module
│   ├── manifest.json           # actions, namespace, version
│   ├── Module.php              # menu registration + idempotent schema bootstrap
│   ├── actions/                # CController actions (page shells + JSON endpoints)
│   ├── views/                  # closet.list / closet.view page shells
│   ├── lib/                    # InventoryStore, EnrichmentService, API clients, Cache
│   ├── assets/                 # React UI (App, ListView, DetailView, Modals, styles)
│   └── setup/                  # schema.sql + maintenance SQL
├── design/                     # original Claude Design handoff bundle (mockup + chat)
├── docs/IMPLEMENTATION_PLAN.md # the authoritative build plan (read this for detail)
├── reference/                  # verbatim API clients/actions ported from tcs_dashboard
├── LICENSE
└── README.md
```

## Build phases

- **Phase 1 (current scaffold):** module skeleton + authored CRUD — closets,
  switches, power, maintenance, flags, photo upload. No live enrichment yet.
- **Phase 2:** Zabbix enrichment — live ports, PoE tags, stack health.
- **Phase 3:** XIQ enrichment + switch autofill.
- **Phase 4:** rConfig backup-age chip + audited PoE-cycle.
- **Phase 5:** polish — CSV/PDF export, Service-queue and Schools nav pages.

## Installing & enabling

1. Copy `modules/closet_inventory/` into the Zabbix frontend host at
   `<zabbix-frontend>/modules/closet_inventory/`.
2. Ensure the Zabbix DB user can `CREATE TABLE` (schema bootstraps on first load).
3. Enable it in **Administration → General → Modules** (Scan directory →
   toggle **Closet Inventory** to Enabled).
4. A new top-level **Closet Inventory → Switch closets** menu appears, linking to
   `zabbix.php?action=closet.list`.

See `modules/closet_inventory/README.md` for install details and
`docs/IMPLEMENTATION_PLAN.md` for the full architecture and data contract.
