-- 083 — Full Week Intensive.
-- One baker's guided week points at ordinary SF Baker batches.
-- Coaching dates and lesson access are separate. Lesson rows are seeded
-- in PHP (includes/sfb_intensive.php) so this file stays additive DDL.

CREATE TABLE IF NOT EXISTS sfb_intensive_programs (
  id INT NOT NULL AUTO_INCREMENT,
  slug VARCHAR(64) NOT NULL,
  title VARCHAR(150) NOT NULL,
  offering_id INT NULL DEFAULT NULL,
  course_id INT NULL DEFAULT NULL,
  coaching_days INT NOT NULL DEFAULT 7,
  material_access_days INT NULL DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sfb_intensive_slug (slug),
  KEY idx_sfb_intensive_offering (offering_id),
  KEY idx_sfb_intensive_course (course_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sfb_intensive_enrollments (
  id INT NOT NULL AUTO_INCREMENT,
  program_id INT NOT NULL,
  customer_id INT NOT NULL,
  purchase_id INT NULL DEFAULT NULL,
  assigned_user_id INT NULL DEFAULT NULL,
  start_date DATE NULL DEFAULT NULL,
  coaching_end_date DATE NULL DEFAULT NULL,
  status ENUM('pending','awaiting_staff','active','paused','awaiting_customer','final_review','completed','cancelled') NOT NULL DEFAULT 'pending',
  experience_level VARCHAR(32) NULL DEFAULT NULL,
  primary_goal TEXT NULL,
  customer_background TEXT NULL,
  availability TEXT NULL,
  room_temperature VARCHAR(40) NULL DEFAULT NULL,
  oven_type VARCHAR(80) NULL DEFAULT NULL,
  bake_vessel VARCHAR(40) NULL DEFAULT NULL,
  flour_used VARCHAR(150) NULL DEFAULT NULL,
  staff_objective TEXT NULL,
  internal_notes TEXT NULL,
  summary_changed TEXT NULL,
  summary_lessons TEXT NULL,
  summary_process TEXT NULL,
  summary_next TEXT NULL,
  intake_completed_at DATETIME NULL DEFAULT NULL,
  completed_at DATETIME NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sfb_enroll_customer (customer_id, status),
  KEY idx_sfb_enroll_program (program_id, status),
  KEY idx_sfb_enroll_purchase (purchase_id),
  CONSTRAINT fk_sfb_enroll_program FOREIGN KEY (program_id) REFERENCES sfb_intensive_programs (id) ON DELETE CASCADE,
  CONSTRAINT fk_sfb_enroll_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sfb_intensive_slots (
  id INT NOT NULL AUTO_INCREMENT,
  enrollment_id INT NOT NULL,
  sequence_number TINYINT NOT NULL,
  title VARCHAR(80) NOT NULL,
  objective TEXT NULL,
  formula_id INT NULL DEFAULT NULL,
  batch_id INT NULL DEFAULT NULL,
  status ENUM('upcoming','ready','in_progress','awaiting_review','reviewed') NOT NULL DEFAULT 'upcoming',
  customer_feedback TEXT NULL,
  internal_summary TEXT NULL,
  reviewed_at DATETIME NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sfb_slot_seq (enrollment_id, sequence_number),
  KEY idx_sfb_slot_batch (batch_id),
  CONSTRAINT fk_sfb_slot_enrollment FOREIGN KEY (enrollment_id) REFERENCES sfb_intensive_enrollments (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sfb_intensive_slot_batches (
  id INT NOT NULL AUTO_INCREMENT,
  slot_id INT NOT NULL,
  batch_id INT NOT NULL,
  is_current TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sfb_slot_batch (batch_id),
  KEY idx_sfb_slot_batches_slot (slot_id, is_current),
  CONSTRAINT fk_sfb_slot_batches_slot FOREIGN KEY (slot_id) REFERENCES sfb_intensive_slots (id) ON DELETE CASCADE,
  CONSTRAINT fk_sfb_slot_batches_batch FOREIGN KEY (batch_id) REFERENCES sfb_batches (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sfb_intensive_checkpoints (
  id INT NOT NULL AUTO_INCREMENT,
  slot_id INT NOT NULL,
  stage VARCHAR(32) NOT NULL,
  instruction TEXT NOT NULL,
  status ENUM('requested','satisfied','cancelled') NOT NULL DEFAULT 'requested',
  requested_at DATETIME NOT NULL,
  satisfied_at DATETIME NULL DEFAULT NULL,
  requested_by_user_id INT NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_sfb_checkpoint_slot (slot_id, status),
  CONSTRAINT fk_sfb_checkpoint_slot FOREIGN KEY (slot_id) REFERENCES sfb_intensive_slots (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sfb_intensive_messages (
  id INT NOT NULL AUTO_INCREMENT,
  enrollment_id INT NOT NULL,
  author_type ENUM('baker','admin') NOT NULL,
  author_customer_id INT NULL DEFAULT NULL,
  author_user_id INT NULL DEFAULT NULL,
  author_name VARCHAR(120) NOT NULL,
  body TEXT NOT NULL,
  customer_read_at DATETIME NULL DEFAULT NULL,
  staff_read_at DATETIME NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sfb_intensive_msg (enrollment_id, created_at),
  CONSTRAINT fk_sfb_intensive_msg_enrollment FOREIGN KEY (enrollment_id) REFERENCES sfb_intensive_enrollments (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
