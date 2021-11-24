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
        OR NEW.published<>OLD.published) THEN
        SET NEW.updatedatetime = CURRENT_TIMESTAMP;
    END IF;
    IF (NEW.published=FALSE AND OLD.published=TRUE) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cannot unpublish only delete';
    END IF;
    IF (NEW.published=TRUE AND OLD.published=FALSE) THEN
        SET NEW.createdatetime = CURRENT_TIMESTAMP;
    END IF;
END//
DELIMITER ;