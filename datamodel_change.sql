USE zaplog;
UPDATE channels SET language='nl' WHERE id=1;
DROP EVENT IF EXISTS zaplog.select_frontpage;
DELIMITER //
CREATE EVENT select_frontpage ON SCHEDULE EVERY 60 MINUTE DO
    BEGIN
        CREATE TABLE frontpage_new
        SELECT DISTINCT links.* FROM links
        WHERE published=TRUE
          AND language=IFNULL((SELECT language FROM channels WHERE id=1),language)
          AND createdatetime<SUBDATE(CURRENT_TIMESTAMP, INTERVAL 3 HOUR)
        ORDER BY (score / GREATEST(9, POW(TIMESTAMPDIFF(HOUR, CURRENT_TIMESTAMP, createdatetime),2))) DESC LIMIT 18;
        -- atomic swap
        RENAME TABLE frontpage_current TO frontpage_old, frontpage_new TO frontpage_current;
        DROP TABLE frontpage_old;
    END //
DELIMITER ;
