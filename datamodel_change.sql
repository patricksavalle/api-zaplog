USE zaplog;
ALTER TABLE interactions MODIFY COLUMN type ENUM (
    'on_insert_channel',
    'on_update_channel',
    'on_insert_link',
    'on_update_link',
    'on_insert_reaction',
    'on_insert_vote',
    'on_delete_vote',
    'on_insert_tag',
    'on_receive_cash',
    'on_frontpage_calculated',
    'on_reputation_calculated'
    ) NOT NULL;
DROP EVENT select_frontpage;
DELIMITER //
CREATE EVENT select_frontpage ON SCHEDULE EVERY 90 MINUTE DO
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
        INSERT INTO interactions(type) VALUES('on_frontpage_calculated');
    END //
DELIMITER ;
SET GLOBAL event_scheduler = ON;


