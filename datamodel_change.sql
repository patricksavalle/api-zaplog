USE zaplog;
DROP TABLE referrals;
DROP TABLE referrers;
CREATE VIEW topreactions AS
    SELECT links.title, channels.name, channels.avatar, x.* FROM (
        SELECT
            COUNT(reactionvotes.id) AS votecount,
            reactions.id,
            reactions.linkid,
            reactions.channelid,
            reactions.description
        FROM reactions
        LEFT JOIN reactionvotes ON reactions.id = reactionid AND reactions.createdatetime > SUBDATE(CURRENT_TIMESTAMP, INTERVAL 3 HOUR)
        WHERE reactions.channelid<>1
        GROUP BY reactions.id
        ORDER BY reactions.id DESC LIMIT 50
    ) AS x
    JOIN links ON x.linkid = links.id
    JOIN channels ON x.channelid = channels.id
    ORDER BY votecount DESC, id DESC;
CREATE TABLE emailaddresses
(
    id             INT       NOT NULL AUTO_INCREMENT,
    channelid      INT       NOT NULL,
    email          VARCHAR(60) NOT NULL,
    createdatetime TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE INDEX (channelid, email),
    FOREIGN KEY (channelid) REFERENCES channels (id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
);

