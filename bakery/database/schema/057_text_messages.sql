-- Text messages ledger (Twilio Texting Command Center).
-- One row per outbound or inbound SMS. Additive; reversible by dropping text_messages.
-- Column names match includes/text_comms.php bakery_text_record().

CREATE TABLE IF NOT EXISTS text_messages (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    direction VARCHAR(16) NOT NULL DEFAULT 'outbound',
    status VARCHAR(24) NOT NULL DEFAULT 'queued',
    from_number VARCHAR(32) NOT NULL DEFAULT '',
    to_number VARCHAR(32) NOT NULL DEFAULT '',
    body TEXT NOT NULL,
    twilio_sid VARCHAR(64) DEFAULT NULL,
    error_message TEXT NULL,
    customer_id INT UNSIGNED DEFAULT NULL,
    staff_user_id INT UNSIGNED DEFAULT NULL,
    context_type VARCHAR(40) NOT NULL DEFAULT 'manual',
    context_id INT UNSIGNED DEFAULT NULL,
    operating_date DATE DEFAULT NULL,
    read_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_text_messages_twilio_sid (twilio_sid),
    KEY idx_text_messages_operating_date (operating_date),
    KEY idx_text_messages_customer (customer_id),
    KEY idx_text_messages_to_number (to_number),
    KEY idx_text_messages_from_number (from_number),
    KEY idx_text_messages_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
