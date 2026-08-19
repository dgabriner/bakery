-- Customer-facing notifications (separate from operational_events audit timeline).

CREATE TABLE IF NOT EXISTS customer_notifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_id INT NOT NULL,
    event_type VARCHAR(64) NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    link_url VARCHAR(512) NULL DEFAULT NULL,
    related_entity_type VARCHAR(64) NULL DEFAULT NULL,
    related_entity_id INT NULL DEFAULT NULL,
    dedupe_key VARCHAR(128) NOT NULL,
    email_status ENUM('none','pending','sent','failed','skipped') NOT NULL DEFAULT 'none',
    email_sent_at DATETIME NULL DEFAULT NULL,
    read_at DATETIME NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_customer_notification_dedupe (customer_id, dedupe_key),
    KEY idx_customer_unread (customer_id, read_at, created_at DESC),
    CONSTRAINT fk_customer_notifications_customer
        FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_notification_preferences (
    customer_id INT NOT NULL,
    order_in_app TINYINT(1) NOT NULL DEFAULT 1,
    order_email TINYINT(1) NOT NULL DEFAULT 1,
    delivery_in_app TINYINT(1) NOT NULL DEFAULT 1,
    delivery_email TINYINT(1) NOT NULL DEFAULT 0,
    billing_in_app TINYINT(1) NOT NULL DEFAULT 1,
    billing_email TINYINT(1) NOT NULL DEFAULT 1,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (customer_id),
    CONSTRAINT fk_customer_notification_prefs_customer
        FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
