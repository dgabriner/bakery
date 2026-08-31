-- Survey link opens and submits: who clicked, when, and best-guess identity.
-- Additive; reversible by dropping survey_interactions.

CREATE TABLE IF NOT EXISTS survey_interactions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    survey_id INT UNSIGNED NOT NULL,
    interaction_type VARCHAR(12) NOT NULL DEFAULT 'open',
    submit_action VARCHAR(24) NULL DEFAULT NULL,
    staff_user_id INT NULL DEFAULT NULL,
    driver_id INT UNSIGNED NULL DEFAULT NULL,
    survey_response_id INT UNSIGNED NULL DEFAULT NULL,
    target_phone VARCHAR(32) NULL DEFAULT NULL,
    guessed_name VARCHAR(120) NULL DEFAULT NULL,
    match_source VARCHAR(24) NULL DEFAULT NULL,
    ip_address VARCHAR(45) NULL DEFAULT NULL,
    user_agent TEXT NULL,
    referer VARCHAR(1024) NULL DEFAULT NULL,
    request_uri VARCHAR(1024) NULL DEFAULT NULL,
    session_hash CHAR(64) NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_survey_interactions_survey (survey_id, created_at),
    KEY idx_survey_interactions_type (survey_id, interaction_type, created_at),
    KEY idx_survey_interactions_ip (ip_address, created_at),
    CONSTRAINT fk_survey_interactions_survey
        FOREIGN KEY (survey_id) REFERENCES surveys(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
