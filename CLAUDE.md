# CLAUDE.md — Folio Take-Home

## What this app does
Folio is a simple internal document-sharing tool. Staff create documents,
generate share links for recipients, and recipients view documents via
those links. All data is stored in SQLite.

## Stack
- PHP (no framework)
- SQLite via PDO
- Docker / docker compose for local development
- No build system; plain PHP and vanilla JS/CSS

## Project structure
- `public/` — all web-accessible PHP files (admin.php, share.php, view.php)
- `lib/bootstrap.php` — database connection, audit_log(), current_staff(),
  random_token(), h() (XSS escaping)
- `lib/layout.php` — render_header() and render_footer() for shared HTML
- `schema.sql` — initial schema (do not edit directly; use migrations instead)
- `migrations/` — numbered SQL migration files (001_*.sql, 002_*.sql, etc.)
- `seed.php` — seeds the database on fresh container start
- `db.sqlite` — SQLite database file (not committed to version control)

## Database conventions
- All tables use INTEGER PRIMARY KEY (auto-increment)
- Datetimes are stored as TEXT in UTC using SQLite's datetime('now')
- `audit_log` is written via the audit_log() function in bootstrap.php —
  never insert to it directly
- Foreign keys are enabled via PRAGMA

## Patterns to follow
- All DB access uses PDO prepared statements — never string interpolation in SQL
- User output is always escaped with h() — never echo raw input
- New DB operations that modify data should be wrapped in transactions
- New features go through migrations — never alter schema.sql directly
- audit_log() should be called for every meaningful state change

## Migration system
Migrations live in migrations/ as numbered SQL files (e.g. 001_add_publish_at.sql).
The migration runner (migrate.php) executes any file not yet recorded in the
migrations table. Docker startup runs migrate.php automatically before serving.

## What Claude may do autonomously
- Read any file in the project
- Write new migration files
- Write new PHP features following the patterns above
- Add fields to existing queries when adding new columns
- Write the FTS5 virtual table and trigger SQL

## What Claude must not do without checking first
- Modify schema.sql
- Change bootstrap.php, layout.php, or seed.php without explicit instruction
- Add dependencies or change the Docker configuration
- Delete or rename existing files
- Change how current_staff() works or introduce session handling

## Known issues (do not fix — note in video walkthrough instead)
- Share links are never invalidated after use (not truly one-time)
- No audit log entry when a recipient views a document
- No CSRF protection on forms
- current_staff() always returns id=1 and functions as a database health
  check; a real deployment would resolve the current user from a session
- Document create and share create are not wrapped in transactions
  (fix this in new features going forward)

## Timezone note
bootstrap.php sets date_default_timezone_set('America/Chicago'). SQLite's
datetime('now') returns UTC. Store all datetimes in the database in UTC.
Convert to local time for display only.
