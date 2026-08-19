-- 040 — Synthetic Studio persona profiles.
-- Local/test can also create this at runtime; production applies it here
-- because USE_PROD_DB blocks runtime DDL.

CREATE TABLE IF NOT EXISTS sfb_persona_profiles (
    customer_id INT NOT NULL,
    persona_key VARCHAR(80) NOT NULL,
    cohort VARCHAR(40) NOT NULL,
    locale VARCHAR(8) NOT NULL DEFAULT 'en',
    is_mentor TINYINT(1) NOT NULL DEFAULT 0,
    seeded_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (customer_id),
    KEY idx_sfb_persona_key (persona_key),
    CONSTRAINT fk_sfb_persona_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
