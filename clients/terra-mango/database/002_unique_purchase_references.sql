-- One receipt may earn stamps once per business branch.
-- Import after 001_initial_schema.sql and before enabling the stamp desk.
ALTER TABLE stamp_transactions
    ADD UNIQUE KEY uq_stamp_purchase_reference
        (business_id, branch_id, purchase_reference_hash);

INSERT IGNORE INTO schema_migrations (version)
VALUES ('002_unique_purchase_references');
