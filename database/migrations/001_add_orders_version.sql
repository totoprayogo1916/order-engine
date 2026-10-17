-- Upgrades databases created before optimistic locking (poin 3) existed.
-- Fresh installs skip this via the DatabaseMigrator baseline — schema.sql
-- already includes the version column.
ALTER TABLE orders ADD COLUMN version INT NOT NULL DEFAULT 1;
