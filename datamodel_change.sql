USE zaplog;
DROP TRIGGER on_before_update_link;
DELIMITER //
CREATE TRIGGER on_before_update_link BEFORE UPDATE ON links FOR EACH ROW
BEGIN
    IF (NEW.title<>OLD.title
        OR NEW.markdown<>OLD.markdown
        OR NEW.copyright<>OLD.copyright
        OR NEW.language<>OLD.language
        OR NEW.url<>OLD.url
        OR NEW.image<>OLD.image
        -- also update on added reactions (used for 'discussion' query)
        OR NEW.reactionscount>OLD.reactionscount) THEN
        SET NEW.updatedatetime = CURRENT_TIMESTAMP;
    END IF;
END//
DELIMITER ;
DROP TRIGGER on_update_link;
DELIMITER //
CREATE TRIGGER on_update_link AFTER UPDATE ON links FOR EACH ROW
BEGIN
    IF (NEW.markdown<>OLD.markdown
        OR NEW.image<>OLD.image
        OR NEW.copyright<>OLD.copyright
        OR NEW.language<>OLD.language
        OR NEW.title<>OLD.title
        OR NEW.url<>OLD.url) THEN
        INSERT INTO interactions(channelid, linkid, type) VALUES (NEW.channelid, NEW.id, 'on_update_link');
    END IF;
    -- accumulate link scores into parent channel
    IF (NEW.score<>OLD.score) THEN
        UPDATE channels SET score=score+(NEW.score-OLD.score) WHERE id=NEW.channelid;
    END IF;
END//
DELIMITER ;

