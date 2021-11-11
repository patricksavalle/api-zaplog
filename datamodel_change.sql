USE zaplog;
DROP EVENT select_frontpage;
DELIMITER //
CREATE EVENT select_frontpage ON SCHEDULE EVERY 90 MINUTE DO
    BEGIN
        -- select new frontpage
        CREATE TABLE frontpage_new
        SELECT DISTINCT links.* FROM links
        WHERE published=TRUE
          AND language=IFNULL((SELECT language FROM channels WHERE id=1),language)
          AND createdatetime<SUBDATE(CURRENT_TIMESTAMP, INTERVAL 3 HOUR)
        ORDER BY (score / GREATEST(9, POW(TIMESTAMPDIFF(HOUR, CURRENT_TIMESTAMP, createdatetime),2))) DESC LIMIT 18;

        -- atomic swap
        RENAME TABLE frontpage_current TO frontpage_old, frontpage_new TO frontpage_current;
        DROP TABLE frontpage_old;

        -- notify frontpage selection in reactions, use temp table because of triggers
        CREATE TABLE reactions_temp
            SELECT
                id AS linkid,
                1 AS channelid,
                "<em>-- selected for frontpage by system --</em>" AS xtext,
                "-- selected for frontpage by system --" AS description
            FROM frontpage;
        INSERT INTO reactions(linkid,channelid,xtext,description)
            SELECT linkid,channelid,xtext,description FROM reactions_temp;
        UPDATE reactions AS r
            JOIN reactions_temp AS t ON r.linkid=t.linkid
        SET threadid=(SELECT MAX(id) FROM reactions WHERE linkid=r.linkid);
        DROP TABLE reactions_temp;

        -- notification
        INSERT INTO interactions(type) VALUES('on_frontpage_calculated');
    END //
DELIMITER ;
SET GLOBAL event_scheduler = ON;


