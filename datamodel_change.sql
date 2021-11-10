USE zaplog;

DROP PROCEDURE calculate_channel_reputations;

DROP EVENT calculate_channel_reputations;

DELIMITER //
CREATE EVENT calculate_channel_reputations ON SCHEDULE EVERY 24 HOUR DO
    BEGIN
        INSERT INTO interactions(type) VALUES('on_reputation_calculated');
        UPDATE channels SET reputation = IF(reputation * 0.9981 + score - prevscore < 1, 1, reputation * 0.9981 + score - prevscore), prevscore = score;
    END //
DELIMITER ;

ALTER TABLE links CHANGE COLUMN score score INT GENERATED ALWAYS AS (
    ROUND (
        -- no votes no score
        votescount *
        -- no tags, no score
        IF(tagscount = 0, 0, 1) *
        -- longer articles are better
        IF(markdown IS NULL OR LENGTH(markdown) < 100, 0, LOG(10, LENGTH(markdown)) - LOG(10, 10)) *
        -- double score for real articles
        (
            CASE copyright
                WHEN 'No Rights Apply' THEN 1
                WHEN 'All Rights Reserved' THEN 2
                WHEN 'No Rights Reserved (CC0 1.0)' THEN 2
                WHEN 'Some Rights Reserved (CC BY-SA 4.0)' THEN 2
                ELSE 1 END
            ) *
        -- weigh the passive factors, decreasing returns
        (
            -- more different interactors is better
            LOG(10, 1 + uniquereactors * 10) +
            -- more external reach is better
            LOG(10, 1 + uniquereferrers) +
            -- more reactions is better
            LOG(10, 1 + reactionscount) / 5 +
            -- more views always better
            LOG(10, 1 + viewscount / 10)
        )
    )
);

UPDATE channels JOIN links ON channels.id=links.channelid
SET channels.score=channels.score + links.score;

UPDATE channels SET reputation = 1;

