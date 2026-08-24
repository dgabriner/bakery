-- Surveys phase 2: multi-question custom surveys + respondent attribution.
-- questions_json: [{key,text,type,options[]}] where type is text|yes_no|choice.
-- Legacy rows keep using the single `question` column and render as one text question.
-- Additive; reversible by dropping the added columns.

ALTER TABLE surveys ADD COLUMN title VARCHAR(120) NULL DEFAULT NULL;
ALTER TABLE surveys ADD COLUMN questions_json TEXT NULL;

ALTER TABLE survey_responses ADD COLUMN question_key VARCHAR(24) NULL DEFAULT NULL;
ALTER TABLE survey_responses ADD COLUMN respondent VARCHAR(80) NULL DEFAULT NULL;
