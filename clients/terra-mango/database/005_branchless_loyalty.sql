-- Make loyalty operations business-wide for JuanTap Link v1.
-- Existing branch IDs remain attached to historical rows; new rows use NULL.
-- Import after migrations 001 through 004.
ALTER TABLE stamp_transactions
    DROP INDEX uq_stamp_purchase_reference,
    DROP INDEX ix_stamp_purchase_reference,
    DROP INDEX ix_stamp_business_branch_time,
    MODIFY branch_id BIGINT UNSIGNED NULL,
    ADD UNIQUE KEY uq_stamp_business_purchase_reference (business_id, purchase_reference_hash),
    ADD KEY ix_stamp_business_time (business_id, created_at, id);

ALTER TABLE reward_redemptions
    DROP INDEX ix_redemptions_business_branch_time,
    MODIFY branch_id BIGINT UNSIGNED NULL,
    ADD KEY ix_redemptions_business_time (business_id, created_at, id);

INSERT IGNORE INTO schema_migrations (version)
VALUES ('005_branchless_loyalty');
