# Delivery Zone Console — Project Overview

A local prototype for an SME delivery/logistics operations tool: a live map
showing delivery zones, driver positions, and delivery stops, backed by a
real spatial database.

## What problem this solves

Small delivery businesses in the Philippines (water refill stations, LPG
dealers, small courier services) currently track deliveries manually via
messaging apps (Viber/Messenger) with no map, no live driver status, and no
structured record of zones or delivery history. This tool gives the owner:

- A map of delivery zones (polygons), each with a schedule/name/color
- Live driver positions and status (on time / delayed)
- Delivery stop locations
- A way to ask "which zone does this coordinate fall into?" (real spatial
  query, not manual lookup)

## Technology stack

| Layer | Technology | Why |
|---|---|---|
| Map rendering | **OpenLayers 4.6.5** (`ol.js`, loaded from cdnjs) | Open-source, no API key required, full control over layers/styling |
| Base map tiles | `tile.openstreetmap.de` (XYZ raster tiles) | Free OpenStreetMap mirror that allows local (`file://`/`localhost`) access — the main osm.org tile server blocks non-browser-referrer requests |
| Frontend pages | Plain HTML + CSS + JavaScript | No build step needed for a prototype; will migrate to Vue.js later to match the main Hyphen OI Pro stack |
| Backend API | **PHP 8.2** (XAMPP), plain scripts (no framework) | Already available on this machine (XAMPP); simple enough for a handful of endpoints |
| Database | **PostgreSQL 16** | Relational database; stores companies, zones, drivers, stops |
| Spatial extension | **PostGIS 3.6.2** | Adds geometry types (`POLYGON`, `POINT`) and spatial functions (`ST_Contains`, `ST_AsGeoJSON`, `ST_MakePoint`) to PostgreSQL — this is what makes "is this point inside this zone" a real, fast database query instead of manual math |
| Data exchange format | **GeoJSON** | Standard format for geographic data; PostGIS can emit it directly (`ST_AsGeoJSON`), and OpenLayers can read it directly (`ol.format.GeoJSON`) — no custom parsing needed |

## Architecture

```
┌─────────────────────────┐        ┌──────────────────────────┐
│   delivery-ops-map.html │        │       driver.html         │
│   (owner's dashboard)   │        │   (driver's phone page)   │
│                          │        │                            │
│  OpenLayers map          │        │  Reads phone GPS via       │
│  - draws zones           │        │  navigator.geolocation     │
│  - draws driver dots     │        │  OR simulates a fake route │
│  - draws stops           │        │  through Bacoor for testing│
│  - polls every 4s        │        │  Sends update every 8s     │
└───────────┬──────────────┘        └─────────────┬──────────────┘
            │ GET (fetch)                          │ PATCH (fetch)
            ▼                                      ▼
┌─────────────────────────────────────────────────────────────────┐
│                        PHP API  (D:\PHP\api\)                    │
│                                                                    │
│  zones.php     GET  → all zones for a company, as GeoJSON         │
│  drivers.php   GET  → all drivers for a company, as GeoJSON       │
│                PATCH → update one driver's lon/lat/status         │
│  stops.php     GET  → all delivery stops, as GeoJSON              │
│  locate.php    GET  → "which zone contains this point?"           │
│                        (real ST_Contains spatial query)           │
└───────────────────────────────┬───────────────────────────────────┘
                                 │ PDO (pdo_pgsql)
                                 ▼
┌─────────────────────────────────────────────────────────────────┐
│              PostgreSQL 16 + PostGIS  (hyra_delivery DB)          │
│                                                                    │
│  companies   id, name                                             │
│  zones       id, company_id, name, color, schedule, geom(POLYGON) │
│  drivers     id, company_id, name, status, zone_id, geom(POINT)   │
│  stops       id, company_id, label, zone_id, geom(POINT)          │
└─────────────────────────────────────────────────────────────────┘
```

## The live-tracking data flow (step by step)

1. A driver opens `driver.html` on their phone and presses **Start sharing
   location** (or, for testing without being physically in the Philippines,
   **Simulate driving route**).
2. The browser's GPS (`navigator.geolocation.watchPosition`) — or the
   simulator's fake coordinates — produces a `{lon, lat}` pair.
3. Every 8 seconds (or 1.5s in simulation mode), the page sends:
   `PATCH /api/drivers.php?id=<driver_id>` with `{lon, lat, status}` in the
   body.
4. `drivers.php` runs:
   ```sql
   UPDATE drivers
   SET geom = ST_SetSRID(ST_MakePoint(:lon, :lat), 4326), status = :status
   WHERE id = :id
   ```
5. The owner's dashboard (`delivery-ops-map.html`) polls
   `GET /api/drivers.php?company_id=1` every 4 seconds and redraws all
   driver dots from whatever is currently in the database.
6. Because step 4 and step 5 are fully decoupled (one writes, the other
   reads, with no direct connection between the two pages), any number of
   drivers and any number of dashboard viewers can be live at once — this
   is the same basic pattern used by real fleet-tracking apps (Grab,
   Lalamove), just using simple polling instead of push (WebSockets),
   which is the natural next upgrade.

## Why PostGIS specifically (not just storing lon/lat as numbers)

Storing `lon`/`lat` as two plain numbers would only let you *display* a
point. PostGIS stores it as a real `geometry` type, which unlocks queries
that would otherwise require fetching all rows and doing math in PHP:

- `ST_Contains(zone.geom, point)` — is this point inside this zone?
  (used by `locate.php`)
- `ST_Distance(a, b)` — how far apart are two points? (not yet used, but
  needed for "nearest driver" features later)
- `ST_AsGeoJSON(geom)` — convert any stored geometry directly to the format
  the map understands, with no manual conversion code

These become fast, indexed database operations (`GIST` indexes are already
created on every `geom` column in the schema) instead of slow, error-prone
application code.

## File map

```
D:\PHP\
├── delivery-ops-map.html   Owner's dashboard (OpenLayers map)
├── driver.html             Driver's location-sharing page + route simulator
├── api\
│   ├── db.php              Shared PostgreSQL connection (holds local dev password)
│   ├── zones.php           GET zones as GeoJSON
│   ├── drivers.php         GET drivers as GeoJSON; PATCH to update one driver's position
│   ├── stops.php           GET delivery stops as GeoJSON
│   └── locate.php          GET → which zone contains a given point
├── db\
│   └── schema.sql          Full table definitions + sample Bacoor data (re-runnable)
└── docs\
    └── project-overview.md This file
```

## What's been added since the first prototype

- **Login/authentication** — `login.html` + `api/login.php`, `logout.php`,
  `me.php`, backed by a `users` table (email, bcrypt password hash,
  company_id) and PHP sessions. Every dashboard-facing endpoint
  (`zones.php`, `stops.php`, `locate.php`, and the GET side of
  `drivers.php`) now calls `require_company_id()` from `api/auth.php`,
  which derives the company from the session — not from a trusted URL
  parameter — and returns 401 if there's no session. Demo login:
  `owner@palitlogistics.ph` / `Owner_Pass123`.
- **Dynamic sidebar** — the zone list and driver list in the dashboard are
  now rendered from whatever is actually in `zoneSource`/`driverSource`
  (i.e. the live API data), not hardcoded HTML.
- **Add Zone by drawing on the map** — "+ Draw zone" puts the map into a
  click-to-place-points mode; on Finish, a modal collects name/schedule/
  color and POSTs the polygon to `zones.php`.
- **Add Driver / Add Stop forms** — modals that POST to `drivers.php` /
  `stops.php` instead of requiring manual SQL.
- **Delivery status on stops** — `stops` now has a `status` column
  (`pending` / `delivered` / `failed`) and an optional `driver_id`.
  Clicking a stop marker opens a modal to update its status via
  `PATCH /api/stops.php?id=`. Stop markers are color-coded by status.

## Known limitations (still open)

- **Driver location updates are still unauthenticated** — `PATCH
  /api/drivers.php` (called by `driver.html`, a driver's own phone) takes
  a bare numeric `id` with no proof the caller is that driver. Anyone who
  knows or guesses a driver's id can overwrite their location. This was
  deliberately left open for the prototype; before real deployment, each
  driver needs a private access token (e.g. `driver.html?token=...`)
  checked against a stored token instead of a raw id.
- **Password in plaintext** — `api/db.php` holds the local PostgreSQL
  password directly in the file. Fine for local development only; never
  commit this file to version control or deploy it as-is.
- **Only one company/user exists** — the multi-tenant `company_id`
  scoping is implemented (every query filters by it), but it has not been
  tested against a second company's data to confirm isolation actually
  holds end-to-end.
- **Polling, not push** — the dashboard re-fetches every 4 seconds instead
  of receiving instant updates. Fine for a handful of drivers; would need
  Socket.io (already used elsewhere in the Hyphen OI Pro stack) at scale.
- **"Today" stat tiles are still hardcoded** — deliveries done, avg. time
  in zone, etc. are fixed sample numbers, not computed from real stop/
  driver data.
- **Browser-tab GPS is fragile** — a real driver's phone must keep the
  browser tab open and awake to keep sending location; locking the screen
  or switching apps typically pauses it. A packaged mobile app (or PWA
  with background location permission) would be needed for reliable
  all-day tracking.
