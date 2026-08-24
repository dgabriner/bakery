-- Owner-requested surveys: Twilio text with text-reply capture or a tokenized
-- clickable URL (route review: skip/unskip stops, claim unassigned stops).
-- One row per survey; responses land in survey_responses. Additive;
-- reversible by dropping both tables.

CREATE TABLE IF NOT EXISTS surveys (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    token CHAR(32) NOT NULL,
    mode VARCHAR(12) NOT NULL DEFAULT 'link',
    kind VARCHAR(24) NOT NULL DEFAULT 'route_review',
    audience VARCHAR(12) NOT NULL DEFAULT 'driver',
    driver_id INT UNSIGNED DEFAULT NULL,
    staff_user_id INT UNSIGNED DEFAULT NULL,
    target_phone VARCHAR(32) NOT NULL DEFAULT '',
    question TEXT NULL,
    delivery_date DATE DEFAULT NULL,
    status VARCHAR(12) NOT NULL DEFAULT 'open',
    created_by INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    closed_at DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_surveys_token (token),
    KEY idx_surveys_phone_status (target_phone, status),
    KEY idx_surveys_driver (driver_id, delivery_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS survey_responses (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    survey_id INT UNSIGNED NOT NULL,
    text_message_id INT UNSIGNED DEFAULT NULL,
    action VARCHAR(24) NOT NULL DEFAULT 'reply',
    daily_order_id INT UNSIGNED DEFAULT NULL,
    customer_id INT UNSIGNED DEFAULT NULL,
    response TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_survey_responses_survey (survey_id, created_at),
    CONSTRAINT fk_survey_responses_survey
        FOREIGN KEY (survey_id) REFERENCES surveys(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
