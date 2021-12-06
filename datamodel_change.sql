USE zaplog;

DROP VIEW activeusers;
CREATE VIEW activeusers AS
    SELECT DISTINCT channels.*
    FROM channels JOIN interactions ON interactions.channelid=channels.id
    WHERE interactions.datetime > SUBDATE(CURRENT_TIMESTAMP, INTERVAL 1 HOUR);

DROP VIEW topchannels;
CREATE VIEW topchannels AS
    SELECT channels.* FROM channels ORDER BY reputation DESC LIMIT 50;

DROP VIEW updatedchannels;
CREATE VIEW updatedchannels AS
    SELECT DISTINCT channels.* FROM channels
    JOIN links ON links.channelid=channels.id
    GROUP BY channels.id
    ORDER BY MAX(links.id) DESC LIMIT 50;

DROP VIEW channels_public_view;
