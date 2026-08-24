-- 063 — Bread Education Learning Center (Prompt 24).
-- Admin-authored courses → lessons → steps with self-hosted media, plus
-- per-customer progress checkmarks. Content rows are data, not i18n keys;
-- UI chrome stays in lang/en.php and lang/es.php.

CREATE TABLE IF NOT EXISTS sfb_courses (
  id INT NOT NULL AUTO_INCREMENT,
  title VARCHAR(150) NOT NULL,
  description TEXT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sfb_course_lessons (
  id INT NOT NULL AUTO_INCREMENT,
  course_id INT NOT NULL,
  title VARCHAR(150) NOT NULL,
  summary TEXT NULL,
  external_url VARCHAR(500) NULL DEFAULT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sfb_lessons_course (course_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sfb_lesson_steps (
  id INT NOT NULL AUTO_INCREMENT,
  lesson_id INT NOT NULL,
  body_text TEXT NULL,
  media_path VARCHAR(512) NULL DEFAULT NULL,
  media_kind ENUM('photo', 'video') NOT NULL DEFAULT 'photo',
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sfb_steps_lesson (lesson_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sfb_lesson_progress (
  lesson_id INT NOT NULL,
  customer_id INT NOT NULL,
  step_id INT NOT NULL,
  completed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (lesson_id, customer_id, step_id),
  KEY idx_sfb_progress_customer (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
