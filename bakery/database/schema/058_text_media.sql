-- Text media columns for MMS support (Twilio texting command center).
-- kind: sms|mms. media_json: [{url, content_type, path, bytes}] where path is a
-- local file under storage/text_media/ served through text_media.php.
-- Additive; reversible by dropping the three columns.

ALTER TABLE text_messages ADD COLUMN kind VARCHAR(8) NOT NULL DEFAULT 'sms';
ALTER TABLE text_messages ADD COLUMN media_count SMALLINT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE text_messages ADD COLUMN media_json TEXT NULL;
