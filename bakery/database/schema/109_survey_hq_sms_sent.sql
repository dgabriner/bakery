-- One HQ survey text per driver per delivery date per survey kind.
-- driver_id 0 is the combined HQ page (every driver in one text).
-- The first submit claims the row and sends. Later submits the same day
-- save the survey and do not send again. There is no follow-up "updated" text.
-- 107 is reserved by the open closeout photo branch, so this file is 109.
-- Hosted-gate portable: CREATE TABLE IF NOT EXISTS only.

CREATE TABLE IF NOT EXISTS survey_hq_sms_sent (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  kind VARCHAR(32) NOT NULL,
  driver_id INT UNSIGNED NOT NULL DEFAULT 0,
  delivery_date DATE NOT NULL,
  text_message_id INT UNSIGNED NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_survey_hq_sms_sent (kind, driver_id, delivery_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
