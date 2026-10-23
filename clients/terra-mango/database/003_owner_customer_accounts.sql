-- Owner/admin-only authentication and optional customer accounts.
-- Back up the target database and review the preflight queries in README.md first.
-- This migration archives legacy staff data and preserves old actor IDs in history.
-- Run 004_remove_legacy_staff_tables.sql only after verifying both archive tables.

CREATE TABLE IF NOT EXISTS admin_users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email_value_ciphertext BLOB NOT NULL,
    email_lookup_hash BINARY(32) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_admin_users_email_hash (email_lookup_hash),
    KEY ix_admin_users_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Keep business ownership scoped after removing memberships. This is an
-- owner-to-business relation, not a staff role or permission table.
CREATE TABLE IF NOT EXISTS admin_businesses (
    admin_user_id BIGINT UNSIGNED NOT NULL,
    business_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (admin_user_id, business_id),
    KEY ix_admin_businesses_business_status (business_id, status, admin_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS legacy_staff_users_archive LIKE staff_users;
CREATE TABLE IF NOT EXISTS legacy_business_memberships_archive LIKE business_memberships;
CREATE TABLE IF NOT EXISTS legacy_membership_branches_archive LIKE membership_branches;

INSERT IGNORE INTO legacy_staff_users_archive
    (id, email_value_ciphertext, email_lookup_hash, password_hash, status, last_login_at, created_at, updated_at)
SELECT id, email_value_ciphertext, email_lookup_hash, password_hash, status, last_login_at, created_at, updated_at
FROM staff_users;

INSERT IGNORE INTO legacy_business_memberships_archive
    (id, business_id, staff_user_id, role_code, status, created_at, updated_at)
SELECT id, business_id, staff_user_id, role_code, status, created_at, updated_at
FROM business_memberships;

INSERT IGNORE INTO legacy_membership_branches_archive
    (business_membership_id, business_id, branch_id, created_at)
SELECT business_membership_id, business_id, branch_id, created_at
FROM membership_branches;

-- Preserve IDs and encrypted emails for owner accounts so existing actor_user_id
-- values in audit_logs still identify the same owner after cutover.
INSERT IGNORE INTO admin_users
    (id, email_value_ciphertext, email_lookup_hash, password_hash, status, last_login_at, created_at, updated_at)
SELECT su.id, su.email_value_ciphertext, su.email_lookup_hash, su.password_hash,
       CASE WHEN su.status = 'active' AND EXISTS (
                    SELECT 1
                    FROM business_memberships active_owner
                    INNER JOIN businesses b ON b.id = active_owner.business_id
                    WHERE active_owner.staff_user_id = su.id
                      AND active_owner.role_code = 'owner'
                      AND active_owner.status = 'active'
                      AND b.status IN ('active', 'draft')
                ) THEN 'active' ELSE 'inactive' END,
       su.last_login_at, su.created_at, su.updated_at
FROM staff_users su
WHERE EXISTS (
    SELECT 1 FROM business_memberships any_owner
    WHERE any_owner.staff_user_id = su.id AND any_owner.role_code = 'owner'
);

INSERT IGNORE INTO admin_businesses (admin_user_id, business_id, status, created_at)
SELECT bm.staff_user_id, bm.business_id, 'active', bm.created_at
FROM business_memberships bm
INNER JOIN staff_users su ON su.id = bm.staff_user_id
INNER JOIN businesses b ON b.id = bm.business_id
WHERE bm.role_code = 'owner'
  AND bm.status = 'active'
  AND su.status = 'active'
  AND b.status IN ('active', 'draft');

ALTER TABLE customers
    ADD COLUMN password_hash VARCHAR(255) NULL AFTER display_name,
    -- Existing walk-in customers keep their historical card access. New
    -- registrations explicitly set this to pending in customer_auth.php.
    ADD COLUMN approval_status VARCHAR(16) NOT NULL DEFAULT 'approved' AFTER status,
    ADD COLUMN reviewed_by_admin_id BIGINT UNSIGNED NULL AFTER approval_status,
    ADD COLUMN approval_decided_at DATETIME NULL AFTER reviewed_by_admin_id,
    ADD COLUMN rejection_reason VARCHAR(500) NULL AFTER approval_decided_at,
    ADD KEY ix_customers_business_approval (business_id, approval_status, created_at);

ALTER TABLE customer_contacts
    ADD UNIQUE KEY uq_customer_contact_email (business_id, channel, contact_lookup_hash);

ALTER TABLE stamp_transactions
    ADD COLUMN actor_admin_id BIGINT UNSIGNED NULL AFTER actor_membership_id,
    ADD KEY ix_stamp_admin_time (actor_admin_id, created_at, id);

ALTER TABLE reward_redemptions
    MODIFY actor_membership_id BIGINT UNSIGNED NULL,
    ADD COLUMN actor_admin_id BIGINT UNSIGNED NULL AFTER actor_membership_id,
    ADD KEY ix_redemption_admin_time (actor_admin_id, created_at, id);

ALTER TABLE audit_logs
    ADD COLUMN actor_admin_id BIGINT UNSIGNED NULL AFTER actor_membership_id,
    ADD KEY ix_audit_admin_time (actor_admin_id, created_at, id);

-- Resolve owner actor IDs before dropping the role tables. Legacy actor
-- columns are retained as historical references; non-owner actor IDs remain
-- available through the archive tables.
UPDATE stamp_transactions st
INNER JOIN legacy_business_memberships_archive m
    ON m.id = st.actor_membership_id AND m.role_code = 'owner'
INNER JOIN admin_users a ON a.id = m.staff_user_id
SET st.actor_admin_id = a.id
WHERE st.actor_membership_id IS NOT NULL;

UPDATE reward_redemptions rr
INNER JOIN legacy_business_memberships_archive m
    ON m.id = rr.actor_membership_id AND m.role_code = 'owner'
INNER JOIN admin_users a ON a.id = m.staff_user_id
SET rr.actor_admin_id = a.id
WHERE rr.actor_membership_id IS NOT NULL;

UPDATE audit_logs al
INNER JOIN admin_users a ON a.id = al.actor_user_id
SET al.actor_admin_id = a.id
WHERE al.actor_user_id IS NOT NULL;

INSERT IGNORE INTO schema_migrations (version) VALUES ('003_owner_customer_accounts');
