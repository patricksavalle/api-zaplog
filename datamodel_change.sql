USE zaplog;
DROP PROCEDURE insert_reaction;
ALTER TABLE reactions DROP COLUMN threadid, DROP INDEX threadid;

