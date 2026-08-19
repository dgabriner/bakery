-- 044 — Agent Learning Studio / Homebase
-- Living briefing, curriculum, whiteboard, bug watchlist, and session log
-- for Cursor agents and the administrator who coaches them.

CREATE TABLE IF NOT EXISTS agent_lessons (
  id INT NOT NULL AUTO_INCREMENT,
  slug VARCHAR(80) NOT NULL,
  track ENUM('product','practices','bugs','craft') NOT NULL DEFAULT 'product',
  title VARCHAR(180) NOT NULL,
  summary VARCHAR(400) NOT NULL DEFAULT '',
  body_md MEDIUMTEXT NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_required TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_agent_lessons_slug (slug),
  KEY idx_agent_lessons_track (track, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS agent_lesson_progress (
  id INT NOT NULL AUTO_INCREMENT,
  agent_name VARCHAR(120) NOT NULL,
  lesson_id INT NOT NULL,
  notes TEXT NULL,
  completed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_agent_lesson_progress (agent_name, lesson_id),
  KEY idx_agent_lesson_progress_lesson (lesson_id),
  CONSTRAINT fk_agent_lesson_progress_lesson
    FOREIGN KEY (lesson_id) REFERENCES agent_lessons(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS agent_sessions (
  id INT NOT NULL AUTO_INCREMENT,
  agent_name VARCHAR(120) NOT NULL,
  mission VARCHAR(240) NOT NULL DEFAULT '',
  status ENUM('open','handed_off','abandoned') NOT NULL DEFAULT 'open',
  started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ended_at TIMESTAMP NULL DEFAULT NULL,
  files_touched TEXT NULL,
  handoff_md MEDIUMTEXT NULL,
  created_by_user_id INT NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_agent_sessions_open (status, started_at),
  KEY idx_agent_sessions_agent (agent_name, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS agent_whiteboard (
  id INT NOT NULL AUTO_INCREMENT,
  column_key ENUM('now','next','decided','parked') NOT NULL DEFAULT 'now',
  title VARCHAR(180) NOT NULL,
  body TEXT NOT NULL,
  agent_name VARCHAR(120) NOT NULL DEFAULT '',
  sort_order INT NOT NULL DEFAULT 0,
  archived_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_agent_whiteboard_board (archived_at, column_key, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS agent_bugs (
  id INT NOT NULL AUTO_INCREMENT,
  slug VARCHAR(80) NULL DEFAULT NULL,
  title VARCHAR(180) NOT NULL,
  detail TEXT NOT NULL,
  severity ENUM('critical','watch','broken-window') NOT NULL DEFAULT 'watch',
  status ENUM('open','watching','fixed','wont-fix') NOT NULL DEFAULT 'open',
  focus_area VARCHAR(80) NOT NULL DEFAULT 'ops',
  source VARCHAR(80) NOT NULL DEFAULT 'homebase',
  agent_name VARCHAR(120) NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_agent_bugs_slug (slug),
  KEY idx_agent_bugs_status (status, severity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS agent_notes (
  id INT NOT NULL AUTO_INCREMENT,
  kind ENUM('insight','question','coach') NOT NULL DEFAULT 'insight',
  title VARCHAR(180) NOT NULL DEFAULT '',
  body TEXT NOT NULL,
  agent_name VARCHAR(120) NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_agent_notes_kind (kind, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
