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
6. Provision the first owner only through the controlled process below. This schema intentionally creates no privileged account.

The SQL uses CREATE TABLE IF NOT EXISTS and records a migration version so an interrupted first import can be retried. It does not alter an existing table. Before any future schema change, create a new numbered migration, back up the database, review its effect, and record its version only after it succeeds. Do not expose a browser-accessible migration runner.

The schema does not declare foreign keys because the target hosting account's privileges and engine behavior are unverified; application queries must enforce relationship scope. The database helper sets each connection to UTC, and application-generated timestamps must also use UTC. Migration `002_unique_purchase_references.sql` adds a unique index so one normalized receipt reference can earn stamps once per business branch.

## Authentication and access control

The `/admin/` entry requires an active staff account; business access additionally requires an active membership for that business. Login uses a normalized email lookup HMAC and `password_hash()` / `password_verify()`; the database stores no plaintext staff email or password. Login attempts are limited to 8 per normalized email and 20 per source IP in a 15-minute window. A successful login clears those current-window buckets. Rate-limit state is stored as keyed hashes in `rate_limit_buckets` and is cleaned opportunistically after one day.

Sessions use `HttpOnly`, `SameSite=Lax`, and production-only `Secure` cookies. Authentication rotates the session ID. Protected requests recheck the staff account, business, membership, and branch status. Sessions expire after 30 minutes of inactivity or 12 hours total. Login and logout forms use CSRF tokens. Sign-in is available only for pre-provisioned staff; there is no public owner or staff signup flow.

The approved role policy is enforced server-side: owners can manage their business and all active branches; managers can operate only assigned branches and cannot manage memberships or loyalty rules; staff can issue stamps and redeem rewards only at assigned branches. Only owners can change manager/staff branch assignments or deactivate their membership in that business. An owner cannot deactivate owner memberships through the admin page. Deactivating a business membership does not disable the staff account's memberships in other businesses.

Branch-scoped permission checks require an explicit branch ID; a business-only permission check rejects branch operations without one. The admin page can update or clear branch assignments and deactivate existing manager/staff memberships. It does not create accounts or memberships or provide password setup/reset, so new staff still require controlled provisioning until a credential handoff flow is approved.

## Local-only first-owner setup

For Herd/DBngin development, import `002_unique_purchase_references.sql` after the initial schema, then run the CLI-only helper from the repository root:

```powershell
php clients/terra-mango/database/provision_local_admin.php
```

The helper requires `app.environment` to be `development`, a loopback database host, a database name ending in `_local`, and both database migrations. It refuses to run over HTTP or when the database already contains a business or staff account. It creates a draft Terra Mango local business, one active branch, its first owner, an active one-stamp-per-receipt program, an 8-stamp Mango Milk Tea reward, and one demo customer card. The password is randomly generated and displayed once; save it securely.

The local stamp desk blocks repeated receipt references within the same business branch. Receipt references are normalized to uppercase and stored as keyed hashes. A new POST request token makes retries safe without creating another transaction.

The local public page reads `businessSlug` from `config/site.js` and requests public profile, active branch, and active link data from `public-business.php`. Set that value to the slug printed by the local owner setup helper if you chose a non-default slug. Draft businesses are visible through the public endpoint only in development when the request comes from a loopback client and uses `localhost`, a loopback host, or a Herd `.test` host; production responses require an active business. The public response excludes customer, staff, and other private records. Product cards still use the existing static frontend configuration until product management is implemented.

Owners can edit the business profile, branch details, and public links from `/admin/business.php`. These changes update existing database rows and write audit entries. Branches and links are retained; links can be hidden without deleting them.

Do not deploy this helper. Remove it before any deployment or production packaging.

## Controlled first-owner provisioning

There is no browser-accessible owner-creation endpoint. Provision the first owner through a reviewed, temporary local/offline script and phpMyAdmin:

1. On a non-production database, create the business row with `status='draft'` and at least one active branch. Use the actual business name and slug approved by the business owner.
2. On a trusted local PHP runtime, load the private configuration and use `app_normalize_staff_email()`, `app_staff_email_lookup_hash()`, `app_encrypt_staff_email()`, and `password_hash($password, PASSWORD_DEFAULT)` from `backend/auth.php` to prepare the account fields. The password must be 1–72 bytes; choose a unique, high-entropy password. Never expose the helper through a web route. Email encryption uses AES-256-GCM and requires `openssl_encrypt()` in the local provisioning runtime.
3. Insert the generated binary values and password hash into `staff_users`, then insert a `business_memberships` row with that user ID, the business ID, `role_code='owner'`, and `status='active'`. If inserting through phpMyAdmin, first convert each binary helper result to hexadecimal with `bin2hex()`, then use `UNHEX()` around that hex text. Do not include plaintext email or password in the SQL.
4. Confirm the owner can sign in over HTTPS, sees only the intended business, and has the expected active-branch access. Remove the temporary script and generated plaintext values after provisioning.

The host's OpenSSL availability, production PHP version, HTTPS behavior, and direct-access rules still require account-level verification. If a trusted local PHP CLI is unavailable, stop and choose a reviewed offline provisioning method; do not replace this process with a public setup page.

## Backup and restore

Export database dumps through phpMyAdmin and download website files through FTP. Keep backups in a separate protected location. Restore into a clean non-production database first, then verify the schema version and application data before considering a production restore.

The hosting account, PHP/database versions, import limits, and backup retention have not been verified from this repository. Confirm them with the target account before production setup.

On this workstation, Herd executes PHP but does not apply the `.htaccess` deny rules; backend helper files therefore return HTTP 200 when requested directly. The config example and any copy made from it also have a PHP include guard. This local behavior does not establish target-host access control. Verify direct access to `private/` and `backend/` is denied on the hosting account; if its web server ignores `.htaccess`, configure equivalent host rules or keep secrets outside the document root.
