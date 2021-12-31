USE zaplog;
ALTER TABLE links ADD COLUMN orig_language CHAR(2)DEFAULT NULL AFTER language;