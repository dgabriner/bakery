-- =============================================================================
-- Post-0E: add customers.zone_id FK to zones.id (backfill from text zone names)
-- Idempotent: skips ADD COLUMN if zone_id already exists
-- =============================================================================

-- zone_id column (added via migration runner if missing)
-- Backfill from text names
UPDATE customers c
INNER JOIN zones z ON c.zone = z.name
SET c.zone_id = z.id
WHERE c.zone IS NOT NULL
  AND c.zone != ''
  AND (c.zone_id IS NULL OR c.zone_id != z.id);

-- Keep text zone in sync where zone_id is set but text is stale
UPDATE customers c
INNER JOIN zones z ON c.zone_id = z.id
SET c.zone = z.name
WHERE c.zone_id IS NOT NULL
  AND (c.zone IS NULL OR c.zone = '' OR c.zone != z.name);
