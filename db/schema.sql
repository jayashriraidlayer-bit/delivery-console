-- Hyra Delivery Ops — PostGIS schema
-- Run once against a fresh database, e.g.:
--   createdb hyra_delivery
--   psql -d hyra_delivery -f schema.sql

CREATE EXTENSION IF NOT EXISTS postgis;
CREATE EXTENSION IF NOT EXISTS pgcrypto;

-- Companies (multi-tenant root, matches Hyphen OI Pro's companyId pattern)
CREATE TABLE companies (
    id          SERIAL PRIMARY KEY,
    name        TEXT NOT NULL,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Users: log in as an owner/dispatcher of exactly one company
CREATE TABLE users (
    id            SERIAL PRIMARY KEY,
    company_id    INTEGER NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
    email         TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    name          TEXT,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Delivery zones: polygons per company
CREATE TABLE zones (
    id          SERIAL PRIMARY KEY,
    company_id  INTEGER NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
    name        TEXT NOT NULL,
    color       TEXT NOT NULL DEFAULT '#2e6f5e',
    schedule    TEXT,                          -- e.g. "Tue-Sun", "Daily", "Sat-Sun only"
    geom        GEOMETRY(POLYGON, 4326) NOT NULL,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_zones_geom ON zones USING GIST (geom);
CREATE INDEX idx_zones_company ON zones (company_id);

-- Drivers: live point location per company
CREATE TABLE drivers (
    id            SERIAL PRIMARY KEY,
    company_id    INTEGER NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
    name          TEXT NOT NULL,
    status        TEXT NOT NULL DEFAULT 'ok' CHECK (status IN ('ok', 'warn')),
    zone_id       INTEGER REFERENCES zones(id) ON DELETE SET NULL,
    access_token  TEXT NOT NULL UNIQUE DEFAULT encode(gen_random_bytes(16), 'hex'),
    geom          GEOMETRY(POINT, 4326) NOT NULL,
    updated_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_drivers_geom ON drivers USING GIST (geom);
CREATE INDEX idx_drivers_company ON drivers (company_id);

-- Delivery stops: point per company, optionally tied to a zone and a driver
CREATE TABLE stops (
    id          SERIAL PRIMARY KEY,
    company_id  INTEGER NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
    label       TEXT NOT NULL,
    zone_id     INTEGER REFERENCES zones(id) ON DELETE SET NULL,
    driver_id   INTEGER REFERENCES drivers(id) ON DELETE SET NULL,
    status      TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'delivered', 'failed')),
    geom        GEOMETRY(POINT, 4326) NOT NULL,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_stops_geom ON stops USING GIST (geom);
CREATE INDEX idx_stops_company ON stops (company_id);

-- Sample data: one company, Bacoor zones/drivers/stops (matches the demo map)
INSERT INTO companies (name) VALUES ('Palit Logistics') RETURNING id;
-- (assume id = 1 below; adjust if not running on a fresh database)

INSERT INTO zones (company_id, name, color, schedule, geom) VALUES
(1, 'Molino Core', '#2e6f5e', 'Tue-Sun',
    ST_GeomFromText('POLYGON((120.9330 14.4650,120.9420 14.4680,120.9480 14.4620,120.9440 14.4560,120.9350 14.4580,120.9330 14.4650))', 4326)),
(1, 'Niog – Talaba', '#3f8f5c', 'Daily',
    ST_GeomFromText('POLYGON((120.9440 14.4560,120.9480 14.4620,120.9560 14.4600,120.9580 14.4500,120.9480 14.4470,120.9440 14.4560))', 4326)),
(1, 'Zapote Edge', '#c97a2b', 'Peak surcharge',
    ST_GeomFromText('POLYGON((120.9560 14.4600,120.9640 14.4650,120.9680 14.4580,120.9600 14.4530,120.9560 14.4600))', 4326)),
(1, 'Salinas Ext.', '#6b8f9c', 'Sat-Sun only',
    ST_GeomFromText('POLYGON((120.9330 14.4650,120.9350 14.4580,120.9280 14.4540,120.9230 14.4610,120.9280 14.4670,120.9330 14.4650))', 4326));

INSERT INTO drivers (company_id, name, status, zone_id, geom) VALUES
(1, 'R. Manalo', 'ok',   1, ST_SetSRID(ST_MakePoint(120.9390, 14.4610), 4326)),
(1, 'J. Dizon',  'ok',   2, ST_SetSRID(ST_MakePoint(120.9500, 14.4550), 4326)),
(1, 'C. Reyes',  'warn', 3, ST_SetSRID(ST_MakePoint(120.9610, 14.4600), 4326)),
(1, 'M. Santos', 'ok',   2, ST_SetSRID(ST_MakePoint(120.9530, 14.4520), 4326)),
(1, 'A. Cruz',   'ok',   4, ST_SetSRID(ST_MakePoint(120.9290, 14.4600), 4326)),
(1, 'L. Bautista','ok',  1, ST_SetSRID(ST_MakePoint(120.9450, 14.4630), 4326)),
(1, 'E. Torres', 'warn', 3, ST_SetSRID(ST_MakePoint(120.9645, 14.4610), 4326));

INSERT INTO stops (company_id, label, zone_id, geom) VALUES
(1, 'Aling Rosa Sari-Sari',        1, ST_SetSRID(ST_MakePoint(120.9400, 14.4600), 4326)),
(1, 'Villa Esperanza Compound',    2, ST_SetSRID(ST_MakePoint(120.9500, 14.4560), 4326)),
(1, 'Zapote Public Market',        3, ST_SetSRID(ST_MakePoint(120.9615, 14.4590), 4326)),
(1, 'Salinas II Barangay Hall',    4, ST_SetSRID(ST_MakePoint(120.9300, 14.4595), 4326)),
(1, 'Molino Blvd. Apartments',     1, ST_SetSRID(ST_MakePoint(120.9410, 14.4645), 4326));
