-- 069 — Bread Education course template-formula handoff.
-- template_formula_id maps a finished course to the standard formula a
-- student can bake with one click; NULL keeps the course teach-only.
-- Deleting the standard formula frees its courses instead of stranding
-- students, mirroring how 068 treats retired offerings.

ALTER TABLE sfb_courses
  ADD COLUMN template_formula_id INT NULL DEFAULT NULL,
  ADD INDEX idx_sfb_courses_template_formula (template_formula_id),
  ADD CONSTRAINT fk_sfb_course_template_formula
    FOREIGN KEY (template_formula_id) REFERENCES sfb_formulas (id) ON DELETE SET NULL;
