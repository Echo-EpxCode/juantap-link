# Database setup

This directory contains versioned SQL for the JuanTap Link backend. The initial schema is structure-only: it creates no business, staff account, active loyalty rules, or customer data.

## Before applying

- Confirm the target account supports InnoDB transactions and row-level locking. The stamp and redemption workflows depend on them.
- Create or select a non-production database first and take a backup before applying any later migration to a database with data.
- Confirm the account's database host, name, username, and password in the hosting panel. Do not put production credentials in Git or frontend JavaScript.

## Initial setup

1. Create the database in the hosting control panel.
2. Open that database in phpMyAdmin and import 001_initial_schema.sql.
3. Confirm every table uses InnoDB and verify transactions, rollback, unique indexes, and SELECT ... FOR UPDATE on a test database.
4. Check schema_migrations for 001_initial_schema.
5. Create the host-only private/config.local.php from private/config.example.php, replace all placeholders, and verify direct HTTP access to the private directory is denied.
6. Provision the first owner only through the documented, controlled process after the authentication phase is approved. This schema intentionally creates no privileged account.

The SQL uses CREATE TABLE IF NOT EXISTS and records a migration version so an interrupted first import can be retried. It does not alter an existing table. Before any future schema change, create a new numbered migration, back up the database, review its effect, and record its version only after it succeeds. Do not expose a browser-accessible migration runner.

The schema does not declare foreign keys because the target hosting account's privileges and engine behavior are unverified; application queries must enforce relationship scope. The database helper sets each connection to UTC, and application-generated timestamps must also use UTC. Purchase-reference hashes are indexed for lookup but are not unique until the duplicate-reference policy is approved.

## Backup and restore

Export database dumps through phpMyAdmin and download website files through FTP. Keep backups in a separate protected location. Restore into a clean non-production database first, then verify the schema version and application data before considering a production restore.

The hosting account, PHP/database versions, import limits, and backup retention have not been verified from this repository. Confirm them with the target account before production setup.

On this workstation, Herd executes PHP but does not apply the `.htaccess` deny rules; backend helper files therefore return HTTP 200 when requested directly. The config example and any copy made from it also have a PHP include guard. This local behavior does not establish target-host access control. Verify direct access to `private/` and `backend/` is denied on the hosting account; if its web server ignores `.htaccess`, configure equivalent host rules or keep secrets outside the document root.
