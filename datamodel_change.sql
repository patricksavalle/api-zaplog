USE zaplog;
ALTER TABLE tags DROP FOREIGN KEY tags_ibfk_2;
ALTER TABLE tags DROP COLUMN channelid, DROP INDEX channelid;

DROP TRIGGER on_insert_tag;
DELIMITER //
CREATE TRIGGER on_insert_tag AFTER INSERT ON tags FOR EACH ROW
BEGIN
    UPDATE links SET tagscount = tagscount + 1 WHERE id = NEW.linkid;
END//
DELIMITER ;

