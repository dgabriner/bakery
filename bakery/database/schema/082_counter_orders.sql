-- Counter pickup slips: date, place (capp / panaderia / delivery),
-- free-text order, pay status, optional contact, and an optional photo.
-- Operational notes for cashiers, the manager, and bakers.
-- Does not write Square sales, daily_orders, or finished goods.
-- Hosted-gate portable: CREATE TABLE IF NOT EXISTS only.

CREATE TABLE IF NOT EXISTS counter_orders (
  id              INT NOT NULL AUTO_INCREMENT,
  pickup_date     DATE NOT NULL,
  pickup_place    ENUM('capp','panaderia','delivery') NOT NULL,
  order_text      VARCHAR(2000) NOT NULL DEFAULT '',
  pay_status      ENUM('paid','unpaid','other') NOT NULL DEFAULT 'unpaid',
  pay_note        VARCHAR(160) NOT NULL DEFAULT '',
  customer_name   VARCHAR(120) NOT NULL DEFAULT '',
  customer_phone  VARCHAR(40) NOT NULL DEFAULT '',
  customer_email  VARCHAR(160) NOT NULL DEFAULT '',
  photo_path      VARCHAR(500) NOT NULL DEFAULT '',
  photo_mime      VARCHAR(100) NOT NULL DEFAULT '',
  status          ENUM('pending','done') NOT NULL DEFAULT 'pending',
  created_by      INT NOT NULL,
  done_by         INT NULL DEFAULT NULL,
  done_at         TIMESTAMP NULL DEFAULT NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_counter_orders_status_date (status, pickup_date),
  KEY idx_counter_orders_place_date (pickup_place, pickup_date),
  CONSTRAINT fk_counter_orders_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_counter_orders_done_by FOREIGN KEY (done_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
