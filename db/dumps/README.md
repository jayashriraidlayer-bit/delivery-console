# Database dump — Delivery Zone Console

`hyra_delivery.dump` is a full export of the local database, including
schema (tables, indexes) and all sample data (companies, zones, drivers,
stops, and the demo login user).

## How to import it

1. Make sure PostgreSQL + PostGIS are installed (see
   `../../database-setup-guide.md` in the parent project if not).
2. Create an empty database:
   ```bash
   createdb -U postgres hyra_delivery
   ```
3. Enable the PostGIS extension in it (required before restoring, since
   the dump references PostGIS types):
   ```bash
   psql -U postgres -d hyra_delivery -c "CREATE EXTENSION postgis;"
   ```
4. Restore the dump:
   ```bash
   pg_restore -U postgres -d hyra_delivery hyra_delivery.dump
   ```
5. Set up `.env` (copy from `.env.example` in the project root) with your
   local Postgres password, then run the app as usual.

## Demo login

- Email: `owner@palitlogistics.ph`
- Password: `Owner_Pass123`

(This is a demo account with a bcrypt-hashed password in the dump — not
a real production credential, but treat this dump like any other
internal file and don't publish it publicly.)
