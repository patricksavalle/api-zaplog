USE zaplog;
ALTER TABLE links
    CHANGE COLUMN markdown markdown MEDIUMTEXT NULL DEFAULT NULL ,
    CHANGE COLUMN xtext xtext MEDIUMTEXT NULL DEFAULT NULL ;
ALTER TABLE links ADD FULLTEXT (markdown);
DROP VIEW topreactions;
CREATE VIEW topreactions AS
SELECT links.title, channels.name, channels.avatar, x.* FROM (
     SELECT
         COUNT(reactionvotes.id) AS votecount,
         reactions.id,
         reactions.linkid,
         reactions.channelid,
         reactions.description
     FROM reactions
              LEFT JOIN reactionvotes ON reactions.id = reactionid AND reactions.createdatetime > SUBDATE(CURRENT_TIMESTAMP, INTERVAL 12 HOUR)
     WHERE reactions.channelid<>1
     GROUP BY reactions.id
     ORDER BY reactions.id DESC LIMIT 50
    ) AS x
     JOIN links ON x.linkid = links.id
     JOIN channels ON x.channelid = channels.id
ORDER BY votecount DESC, id DESC;


