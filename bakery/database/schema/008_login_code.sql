-- 4-digit login codes for staff (admin / baker / driver)
-- Applied via scripts/run_migrations.php (adds column when missing)

ALTER TABLE users
  ADD COLUMN login_code CHAR(4) NULL AFTER password_hash;

ALTER TABLE users
  ADD UNIQUE KEY uq_users_login_code (login_code);
