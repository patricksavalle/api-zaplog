USE zaplog;
ALTER TABLE interactions ADD COLUMN reactionid INT NULL DEFAULT NULL AFTER linkid;
ALTER TABLE zaplog.interactions ADD INDEX (reactionid);
DROP TRIGGER on_insert_reaction;
DELIMITER //
CREATE TRIGGER on_insert_reaction AFTER INSERT ON reactions FOR EACH ROW
BEGIN
    UPDATE links SET
                     reactionscount = reactionscount + 1,
                     uniquereactors = (SELECT COUNT(DISTINCT channelid) FROM reactions WHERE linkid = NEW.linkid)
    WHERE id = NEW.linkid;
    INSERT INTO interactions(linkid,channelid,reactionid,type) VALUES(NEW.linkid, NEW.channelid,NEW.id,'on_insert_reaction');
END//
DELIMITER ;

