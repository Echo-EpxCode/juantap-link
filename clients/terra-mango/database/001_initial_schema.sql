-- JuanTap Link initial schema.
-- Apply manually through phpMyAdmin after confirming InnoDB is available.
-- This file creates structure only. It creates no business, owner, or loyalty rules.

CREATE TABLE IF NOT EXISTS schema_migrations (
    version VARCHAR(64) NOT NULL,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS businesses (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug VARCHAR(120) NOT NULL,
    display_name VARCHAR(160) NOT NULL,
    tagline VARCHAR(255) NULL,
    description TEXT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_businesses_slug (slug),
    KEY ix_businesses_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS business_links (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    business_id BIGINT UNSIGNED NOT NULL,
    link_type VARCHAR(32) NOT NULL,
    label VARCHAR(120) NOT NULL,
    url VARCHAR(2048) NOT NULL,
    icon VARCHAR(64) NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_business_links_public (business_id, is_active, link_type, sort_order),
    KEY ix_business_links_business (business_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS branches (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    business_id BIGINT UNSIGNED NOT NULL,
    slug VARCHAR(100) NOT NULL,
    display_name VARCHAR(160) NOT NULL,
    address TEXT NULL,
    public_phone VARCHAR(40) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_branches_business_slug (business_id, slug),
    KEY ix_branches_business_status (business_id, status),
    KEY ix_branches_business_id (business_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS staff_users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email_value_ciphertext BLOB NOT NULL,
    email_lookup_hash BINARY(32) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_staff_users_email_hash (email_lookup_hash),
    KEY ix_staff_users_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS business_memberships (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    business_id BIGINT UNSIGNED NOT NULL,
    staff_user_id BIGINT UNSIGNED NOT NULL,
    role_code VARCHAR(32) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_membership_business_user (business_id, staff_user_id),
    KEY ix_membership_user_status (staff_user_id, status),
    KEY ix_membership_business_status (business_id, status),
    KEY ix_membership_business_id (business_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS membership_branches (
    business_membership_id BIGINT UNSIGNED NOT NULL,
    business_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (business_membership_id, branch_id),
    KEY ix_membership_branches_business_branch (business_id, branch_id),
    KEY ix_membership_branches_branch (branch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    business_id BIGINT UNSIGNED NOT NULL,
    display_name VARCHAR(160) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_customers_business_status (business_id, status),
    KEY ix_customers_business_id (business_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_contacts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    business_id BIGINT UNSIGNED NOT NULL,
    customer_id BIGINT UNSIGNED NOT NULL,
    channel VARCHAR(16) NOT NULL,
    contact_value_ciphertext TEXT NOT NULL,
    contact_lookup_hash BINARY(32) NOT NULL,
    is_verified TINYINT(1) NOT NULL DEFAULT 0,
    verified_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_customer_contacts_lookup (business_id, channel, contact_lookup_hash),
    KEY ix_customer_contacts_customer (business_id, customer_id, is_verified),
    KEY ix_customer_contacts_business_id (business_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS verification_challenges (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    business_id BIGINT UNSIGNED NOT NULL,
    customer_contact_id BIGINT UNSIGNED NOT NULL,
    purpose VARCHAR(24) NOT NULL,
    challenge_hash BINARY(32) NOT NULL,
    expires_at DATETIME NOT NULL,
    attempts_used SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    consumed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_verification_challenge_hash (challenge_hash),
    KEY ix_verification_contact_purpose_expiry (customer_contact_id, purpose, expires_at),
    KEY ix_verification_business_id (business_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS loyalty_programs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    business_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(160) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_loyalty_programs_business_status (business_id, status),
    KEY ix_loyalty_programs_business_id (business_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS loyalty_program_versions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    business_id BIGINT UNSIGNED NOT NULL,
    loyalty_program_id BIGINT UNSIGNED NOT NULL,
    version_number INT UNSIGNED NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
    terms TEXT NOT NULL,
    effective_from DATETIME NULL,
    effective_until DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_program_version (loyalty_program_id, version_number),
    KEY ix_program_versions_business_status (business_id, status, effective_from),
    KEY ix_program_versions_business_id (business_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS loyalty_earning_rules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    business_id BIGINT UNSIGNED NOT NULL,
    program_version_id BIGINT UNSIGNED NOT NULL,
    rule_key VARCHAR(190) NOT NULL,
    rule_type VARCHAR(50) NOT NULL,
    scope_type VARCHAR(24) NOT NULL,
    scope_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    rule_config TEXT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_earning_rule_scope (program_version_id, rule_key),
    KEY ix_earning_rules_business_status (business_id, status),
    KEY ix_earning_rules_product_scope (scope_type, scope_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS loyalty_rewards (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    business_id BIGINT UNSIGNED NOT NULL,
    program_version_id BIGINT UNSIGNED NOT NULL,
    reward_key VARCHAR(80) NOT NULL,
    name VARCHAR(160) NOT NULL,
    description TEXT NULL,
    terms TEXT NOT NULL,
    stamp_cost BIGINT UNSIGNED NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_reward_version_key (program_version_id, reward_key),
    KEY ix_rewards_business_status (business_id, status),
    KEY ix_rewards_version_status (program_version_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS products (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    business_id BIGINT UNSIGNED NOT NULL,
    product_key VARCHAR(80) NULL,
    name VARCHAR(180) NOT NULL,
    description TEXT NULL,
    image_path VARCHAR(500) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
    archived_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_products_business_key (business_id, product_key),
    KEY ix_products_business_status (business_id, status, archived_at),
    KEY ix_products_business_id (business_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS branch_product_availability (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    business_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    is_available TINYINT(1) NOT NULL DEFAULT 1,
    display_price DECIMAL(12,2) NULL,
    currency_code CHAR(3) NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_branch_product (branch_id, product_id),
    KEY ix_branch_products_business_availability (business_id, branch_id, is_available),
    KEY ix_branch_products_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS loyalty_cards (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    business_id BIGINT UNSIGNED NOT NULL,
    customer_id BIGINT UNSIGNED NOT NULL,
    loyalty_program_id BIGINT UNSIGNED NOT NULL,
    enrollment_version_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_card_customer_program (customer_id, loyalty_program_id),
    KEY ix_cards_business_status (business_id, status),
    KEY ix_cards_business_id (business_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS card_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    business_id BIGINT UNSIGNED NOT NULL,
    loyalty_card_id BIGINT UNSIGNED NOT NULL,
    token_hash BINARY(32) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at DATETIME NULL,
    last_used_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_card_token_hash (token_hash),
    KEY ix_card_tokens_card_state (loyalty_card_id, revoked_at),
    KEY ix_card_tokens_business_id (business_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS card_balances (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    business_id BIGINT UNSIGNED NOT NULL,
    loyalty_card_id BIGINT UNSIGNED NOT NULL,
    stamp_balance BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_card_balance_card (loyalty_card_id),
    KEY ix_card_balances_business_id (business_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stamp_transactions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    business_id BIGINT UNSIGNED NOT NULL,
    loyalty_card_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NOT NULL,
    actor_membership_id BIGINT UNSIGNED NULL,
    program_version_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NULL,
    transaction_type VARCHAR(24) NOT NULL,
    stamp_delta BIGINT NOT NULL,
    idempotency_key_hash BINARY(32) NOT NULL,
    purchase_reference_hash BINARY(32) NULL,
    reverses_transaction_id BIGINT UNSIGNED NULL,
    reference_note VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_stamp_idempotency (business_id, idempotency_key_hash),
    KEY ix_stamp_purchase_reference (business_id, branch_id, purchase_reference_hash),
    KEY ix_stamp_card_history (loyalty_card_id, created_at, id),
    KEY ix_stamp_business_branch_time (business_id, branch_id, created_at),
    KEY ix_stamp_reversal_target (reverses_transaction_id),
    KEY ix_stamp_business_id (business_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reward_redemptions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    business_id BIGINT UNSIGNED NOT NULL,
    loyalty_card_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NOT NULL,
    actor_membership_id BIGINT UNSIGNED NOT NULL,
    reward_id BIGINT UNSIGNED NOT NULL,
    stamp_transaction_id BIGINT UNSIGNED NOT NULL,
    idempotency_key_hash BINARY(32) NOT NULL,
    reward_name_snapshot VARCHAR(160) NOT NULL,
    reward_terms_snapshot TEXT NOT NULL,
    stamp_cost_snapshot BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_redemption_ledger_transaction (stamp_transaction_id),
    UNIQUE KEY uq_redemption_idempotency (business_id, idempotency_key_hash),
    KEY ix_redemptions_card_history (loyalty_card_id, created_at, id),
    KEY ix_redemptions_business_branch_time (business_id, branch_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    business_id BIGINT UNSIGNED NULL,
    actor_user_id BIGINT UNSIGNED NULL,
    actor_membership_id BIGINT UNSIGNED NULL,
    event_type VARCHAR(64) NOT NULL,
    subject_type VARCHAR(40) NULL,
    subject_id BIGINT UNSIGNED NULL,
    request_id CHAR(32) NULL,
    details TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_audit_business_time (business_id, created_at, id),
    KEY ix_audit_actor_time (actor_user_id, created_at, id),
    KEY ix_audit_subject (subject_type, subject_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_limit_buckets (
    action_key VARCHAR(40) NOT NULL,
    bucket_hash BINARY(32) NOT NULL,
    window_started_at DATETIME NOT NULL,
    attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (action_key, bucket_hash, window_started_at),
    KEY ix_rate_limit_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_migrations (version) VALUES ('001_initial_schema');
