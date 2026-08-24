-- 068 — Bread Education course gating (Prompt 26 follow-through).
-- required_offering_id marks a course as part of a paid class; NULL keeps it
-- free. Nothing locks until staff assign an offering in-app, and deleting an
-- offering frees its courses instead of stranding students.

ALTER TABLE sfb_courses
  ADD COLUMN required_offering_id INT NULL DEFAULT NULL,
  ADD INDEX idx_sfb_courses_offering (required_offering_id),
  ADD CONSTRAINT fk_sfb_course_offering
    FOREIGN KEY (required_offering_id) REFERENCES sfb_offerings (id) ON DELETE SET NULL;
