-- Additional request, credential, session, and client context for login audit.

ALTER TABLE login_audit
  ADD COLUMN credential_method VARCHAR(32) NULL DEFAULT NULL AFTER failure_reason,
  ADD COLUMN credential_fingerprint CHAR(64) NULL DEFAULT NULL AFTER credential_method,
  ADD COLUMN credential_suffix CHAR(2) NULL DEFAULT NULL AFTER credential_fingerprint,
  ADD COLUMN request_method VARCHAR(10) NULL DEFAULT NULL AFTER ip_address,
  ADD COLUMN request_uri VARCHAR(1024) NULL DEFAULT NULL AFTER request_method,
  ADD COLUMN referer VARCHAR(1024) NULL DEFAULT NULL AFTER request_uri,
  ADD COLUMN accept_language VARCHAR(255) NULL DEFAULT NULL AFTER referer,
  ADD COLUMN forwarded_for VARCHAR(1000) NULL DEFAULT NULL AFTER accept_language,
  ADD COLUMN server_protocol VARCHAR(20) NULL DEFAULT NULL AFTER forwarded_for,
  ADD COLUMN server_port SMALLINT UNSIGNED NULL DEFAULT NULL AFTER server_protocol,
  ADD COLUMN session_id_hash CHAR(64) NULL DEFAULT NULL AFTER server_port,
  ADD COLUMN last_page_path VARCHAR(1024) NULL DEFAULT NULL AFTER session_id_hash,
  ADD COLUMN last_page_at TIMESTAMP NULL DEFAULT NULL AFTER last_page_path,
  ADD COLUMN page_views_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER last_page_at,
  ADD KEY idx_login_audit_session_hash (session_id_hash),
  ADD KEY idx_login_audit_page (last_page_path(191));
