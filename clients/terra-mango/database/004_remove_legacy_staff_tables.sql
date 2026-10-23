-- Run only after 003_owner_customer_accounts.sql and after verifying:
-- 1. Legacy archive row counts match the original staff/membership tables.
-- 2. Every active owner account has a corresponding admin_users row.
-- 3. No application route still depends on staff/membership tables.
-- Take and retain a database backup before running these DROP statements.

DROP TABLE IF EXISTS membership_branches;
DROP TABLE IF EXISTS business_memberships;
DROP TABLE IF EXISTS staff_users;

INSERT IGNORE INTO schema_migrations (version) VALUES ('004_remove_legacy_staff_tables');
