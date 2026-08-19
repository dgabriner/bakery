-- Customer delivery issue / service resolution workflow



CREATE TABLE IF NOT EXISTS customer_delivery_issues (

    id INT NOT NULL AUTO_INCREMENT,

    customer_id INT NOT NULL,

    daily_order_id INT NOT NULL,

    order_date DATE NOT NULL,

    product_id INT NULL DEFAULT NULL,

    category ENUM(

        'missing_quantity',

        'wrong_product',

        'damaged',

        'quality',

        'delivery_problem',

        'billing',

        'other'

    ) NOT NULL DEFAULT 'other',

    ordered_quantity INT NULL DEFAULT NULL,

    driver_delivered_quantity INT NULL DEFAULT NULL,

    customer_reported_quantity INT NULL DEFAULT NULL,

    description TEXT NOT NULL,

    attachment_path VARCHAR(512) NULL DEFAULT NULL,

    status ENUM('submitted', 'under_review', 'resolved', 'closed') NOT NULL DEFAULT 'submitted',

    credit_recommendation ENUM('none', 'requested', 'recommended') NOT NULL DEFAULT 'none',

    credit_pieces INT NULL DEFAULT NULL,

    resolution_note TEXT NULL DEFAULT NULL COMMENT 'Customer-visible when resolved',

    internal_note TEXT NULL DEFAULT NULL,

    assigned_to_user_id INT NULL DEFAULT NULL,

    resolved_by_user_id INT NULL DEFAULT NULL,

    resolved_at TIMESTAMP NULL DEFAULT NULL,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    KEY idx_cdi_customer_status (customer_id, status, created_at DESC),

    KEY idx_cdi_order (daily_order_id),

    KEY idx_cdi_status_created (status, created_at DESC),

    CONSTRAINT fk_cdi_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE,

    CONSTRAINT fk_cdi_daily_order FOREIGN KEY (daily_order_id) REFERENCES daily_orders (id) ON DELETE CASCADE,

    CONSTRAINT fk_cdi_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE SET NULL

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


