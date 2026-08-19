-- Packing checklist progress: one checked customer×product line per delivery date.
-- Shared by all staff packing the same operating date.

CREATE TABLE IF NOT EXISTS pack_progress (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pack_date DATE NOT NULL,
    line_key VARCHAR(64) NOT NULL,
    checked_by_user_id INT NULL,
    checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pack_line (pack_date, line_key),
    KEY idx_pack_progress_date (pack_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
