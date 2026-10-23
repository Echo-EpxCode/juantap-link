# Terra Mango database setup

The database is scoped to Terra Mango and supports owner/admin and optional customer accounts. Visitors can browse without an account. New customers register with an email address, full name, and password, then wait for owner approval before accessing loyalty features. The email is used as the unique login name; passwords are stored with PHP `password_hash()`.

## Schema and migration order

For a new database, import these files in order through phpMyAdmin or another controlled database client:

1. `001_initial_schema.sql`
2. `002_unique_purchase_references.sql`
3. `003_owner_customer_accounts.sql`
4. Review the cutover checks below, then `004_remove_legacy_staff_tables.sql`
5. `005_branchless_loyalty.sql`

Migration 003 adds the `admin_users` and `admin_businesses` tables, customer credentials and approval details, a unique customer contact index, and admin actor columns in the transaction and audit ledgers. It copies only legacy owner accounts and active owner-to-business assignments into the new tables. It archives the legacy staff and membership tables and copies historical owner actor IDs into the new admin actor columns. Existing customers are marked approved so their existing cards continue working; registration explicitly creates new customers as pending.

Migration 004 drops the operational `staff_users`, `business_memberships`, and `membership_branches` tables. It is intentionally separate from 003. The archive tables remain in the database and preserve old staff, memberships, and branch assignments for historical reference. Do not drop the archive tables as part of this cutover.

Migration 005 removes branch scope from loyalty operations. It makes the stamp and redemption `branch_id` nullable so all new v1 stamp and redemption rows are business-wide, replaces the per-branch purchase-reference unique index with a per-business index, and replaces branch-oriented activity indexes. Existing rows retain their `branch_id` values for history. The `branches` table and existing business/public-site branch data remain available to the public profile endpoint. The stamp desk now records selected stamp numbers in the existing `reference_note` field; the purchase-reference hash index remains for legacy transaction data.

For a database that already has data, take and retain a backup before any migration. Run the preflight review below before 003, then verify the archive and migrated-owner row counts before applying 004. The migrations have not been applied against the live Terra Mango database by this code change.

## Read-only preflight and cutover review

Run these read-only queries against the target database before migration 003:

```sql
-- Review owners and legacy roles. Only owner rows are migrated to admin_users.
SELECT bm.role_code, bm.status AS membership_status, su.status AS account_status,
       b.id AS business_id, b.display_name AS business_name, su.id AS legacy_user_id
FROM business_memberships bm
JOIN staff_users su ON su.id = bm.staff_user_id
JOIN businesses b ON b.id = bm.business_id
ORDER BY bm.role_code, b.id, su.id;

-- Review duplicate stored contact hashes that would prevent the new unique index.
SELECT business_id, channel, HEX(contact_lookup_hash) AS lookup_hash, COUNT(*) AS matches
FROM customer_contacts
GROUP BY business_id, channel, contact_lookup_hash
HAVING COUNT(*) > 1;

-- Review history that refers to legacy membership or staff IDs.
SELECT 'stamp_transactions' AS source, actor_membership_id AS legacy_actor_id, COUNT(*) AS rows_found
FROM stamp_transactions WHERE actor_membership_id IS NOT NULL GROUP BY actor_membership_id
UNION ALL
SELECT 'reward_redemptions', actor_membership_id, COUNT(*)
FROM reward_redemptions WHERE actor_membership_id IS NOT NULL GROUP BY actor_membership_id
UNION ALL
SELECT 'audit_logs.actor_user_id', actor_user_id, COUNT(*)
FROM audit_logs WHERE actor_user_id IS NOT NULL GROUP BY actor_user_id
UNION ALL
SELECT 'audit_logs.actor_membership_id', actor_membership_id, COUNT(*)
FROM audit_logs WHERE actor_membership_id IS NOT NULL GROUP BY actor_membership_id;
```

The duplicate-hash query finds stored-hash duplicates. If older email contacts used a different HMAC format, it cannot identify the same plaintext email represented by different hashes. Compare normalized, decrypted email values with the configured encryption key before migration where legacy email contacts exist. Do not print or export plaintext emails unnecessarily. Check the target PHP runtime and key configuration before relying on this review.

After 003, compare source and archive counts:

```sql
SELECT 'staff_users' AS source, (SELECT COUNT(*) FROM staff_users) AS source_rows,
       (SELECT COUNT(*) FROM legacy_staff_users_archive) AS archive_rows
UNION ALL
SELECT 'business_memberships', (SELECT COUNT(*) FROM business_memberships),
       (SELECT COUNT(*) FROM legacy_business_memberships_archive)
UNION ALL
SELECT 'membership_branches', (SELECT COUNT(*) FROM membership_branches),
       (SELECT COUNT(*) FROM legacy_membership_branches_archive);

-- Every active owner of an active/draft business should have an active mapping.
SELECT bm.staff_user_id, bm.business_id
FROM legacy_business_memberships_archive bm
JOIN legacy_staff_users_archive su ON su.id = bm.staff_user_id
JOIN businesses b ON b.id = bm.business_id
LEFT JOIN admin_businesses ab
  ON ab.admin_user_id = bm.staff_user_id AND ab.business_id = bm.business_id AND ab.status = 'active'
WHERE bm.role_code = 'owner' AND bm.status = 'active' AND su.status = 'active'
  AND b.status IN ('active', 'draft') AND ab.admin_user_id IS NULL;

-- Confirm old owner actor fields were additionally mapped to admin actors.
SELECT COUNT(*) AS missing_owner_stamp_actors
FROM stamp_transactions st
JOIN legacy_business_memberships_archive m ON m.id = st.actor_membership_id AND m.role_code = 'owner'
WHERE st.actor_admin_id IS NULL;
SELECT COUNT(*) AS missing_owner_redemption_actors
FROM reward_redemptions rr
JOIN legacy_business_memberships_archive m ON m.id = rr.actor_membership_id AND m.role_code = 'owner'
WHERE rr.actor_admin_id IS NULL;
SELECT COUNT(*) AS missing_owner_audit_actors
FROM audit_logs al
JOIN admin_users a ON a.id = al.actor_user_id
WHERE al.actor_admin_id IS NULL;
```

The source/archive counts should match and all missing-owner-actor counts should be zero before 004. Resolve any duplicates, unmigrated owners, or unexpected actor references before continuing. Once verified, apply 004, then confirm its version is present in `schema_migrations`. Keep an off-database backup of the original data and the SQL migration outputs.

## Account and loyalty behavior

- Public pages remain available without registration.
- Customer registration requires a unique email, full name, and password; duplicate email lookup hashes return a conflict. Email is encrypted in `customer_contacts`; the normalized lookup hash is used for login and the unique index.
- An owner manually approves or rejects pending accounts. Approval is recorded with the owner ID, timestamp, and (for rejection) reason. Approval does not verify that the customer controls the email address.
- An approved customer can view only their own card, balance, active rewards, and stamp history. Customers cannot issue stamps or redeem rewards themselves.
- Only an owner/admin mapped to a business can use its admin tools. There are no manager or staff accounts or permissions.
- Each issuance records one selected stamp number from 1 to 8. Numbers already issued in the customer's current set are disabled in the form and rejected by the server; the card balance and transaction history determine current-set progress.
- The owner stamp desk shows the latest 20 stamp and redemption records for the selected business; loyalty activity is not filtered by branch.
- Redemption deducts the reward's configured stamp cost in the same transaction that writes its redemption and history. Any surplus remains on the card. For an eight-stamp reward, a balance of eight becomes zero; a balance of nine becomes one.
- Stamps and redemptions lock the card balance and commit their ledger, balance, and audit changes atomically. InnoDB row locks and transactions are required.

## Local-only first-owner setup

For a new Herd/DBngin development database, apply migrations 001 through 005 in the order above, create the host-only `private/config.local.php` from `private/config.example.php`, then run this helper from the repository root:

```powershell
php clients/terra-mango/database/provision_local_admin.php
```

The helper requires `app.environment` to be `development`, a loopback database host, a database name ending in `_local`, and all five schema versions. It refuses to run over HTTP or when the local database already contains a business or admin account. It creates a draft Terra Mango business, one active branch for the existing public profile model, an owner/admin account and business mapping, an active one-stamp-per-receipt program, an eight-stamp Mango Milk Tea reward, and a demo approved customer card. Stamp and redemption operations do not use that branch. It prints a randomly generated password once; save it securely. Do not deploy this helper.

If an existing local admin's email or password is unknown, first apply and review the account migrations, then run `php clients/terra-mango/database/recover_local_admin.php` from the repository root. It lists decrypted admin emails and business mappings, lets you select an active owner account, and resets that account's password to a randomly generated value shown once. This recovery command is CLI-only and refuses non-development, non-loopback, or non-`_local` databases. It cannot recover a previous password. Do not deploy it.

The local public page reads `businessSlug` from `config/site.js` and requests public profile, active branch, and active link data from `public-business.php`. Draft businesses are visible through the public endpoint only in development for loopback requests on localhost or a Herd `.test` host; production responses require an active business. The public response excludes private customer and admin records. Product cards still use the existing static frontend configuration.

Owner/admin authentication uses a normalized email lookup HMAC and `password_hash()` / `password_verify()`. Login attempts are limited to eight per identifier and 20 per source IP in a 15-minute window. Customer login and registration use separate rate-limit buckets; registration is limited to five attempts per email and 20 per source IP per 15-minute window. Sessions use `HttpOnly`, `SameSite=Lax`, production-only `Secure` cookies, session ID rotation, and 30-minute idle / 12-hour absolute expiry. Protected owner requests recheck admin status and business mapping. Forms use CSRF tokens.

## Backup, restore, and hosting checks

Export database dumps through phpMyAdmin and download site files through FTP. Keep backups in a separate protected location. Restore into a clean non-production database first, then verify schema versions and application data before considering a production restore.

The hosting account, PHP/MySQL versions, import limits, OpenSSL availability, HTTPS behavior, direct-access rules, and backup retention have not been verified from this repository. Confirm them with the target host before production setup. On this workstation, Herd does not apply `.htaccess` deny rules, so test direct access to `private/` and `backend/` on the hosting account and configure equivalent host rules if needed.
