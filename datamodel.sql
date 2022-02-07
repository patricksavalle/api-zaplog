/**
 * zaplog V2
 * @author:     patrick@patricksavalle.com
 */

/*
    I tried to make the datamodel as complete and self-contained as possible.
    Data-integrity is maintained within the datamodel itself.

    This datamodel should be understandable WITHOUT reading the PHP/code layer
    (and vice versa)
 */

-- we need the event scheduler!!!
SET GLOBAL event_scheduler = ON;

DROP SCHEMA IF EXISTS zaplog;
CREATE SCHEMA zaplog
    DEFAULT CHARACTER SET utf8
    DEFAULT COLLATE utf8_general_ci;
USE zaplog;
-- data integrity is not critical, choose maxumum performance
SET GLOBAL TRANSACTION ISOLATION LEVEL READ UNCOMMITTED;

-- -----------------------------------------------------
-- Channels are collections of links
-- -----------------------------------------------------

CREATE TABLE channels
(
    id               INT                NOT NULL AUTO_INCREMENT,
    -- we don't store anything from the user, just hashed email address
    userid           CHAR(32)           NOT NULL,
    name             VARCHAR(55)        NOT NULL,
    -- language used for content selection and possibly (front-end) locale
    language         CHAR(2)            DEFAULT NULL,
    algorithm        ENUM (
        'all',                      -- all articles selected for channelpage
        'channel',                  -- only own articles selected for channelpage
        'popular',                  -- only most popular selected for channelpage
        'voted',                    -- articles voted upon selected for channelpage
        'mixed' ) DEFAULT 'channel',-- popular|channels + voted
    -- automatic RSS content
    feedurl          VARCHAR(255)       DEFAULT NULL,
    theme            VARCHAR(255)       DEFAULT NULL,
    createdatetime   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updatedatetime   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    lastseendatetime TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- inline base64 encoded avatars
    avatar           VARCHAR(16384)     DEFAULT NULL,
    -- url to image
    header           VARCHAR(255)       DEFAULT NULL,
    bio              VARCHAR(255)       DEFAULT NULL,
    bitcoinaddress   VARCHAR(60)        DEFAULT NULL,
    -- number of chars translated by DeepL, reset every month
    deeplusage       INT                DEFAULT 0,
    -- sum of all related link scores
    score            INT                DEFAULT 0,
    -- for internal bookkeeping during reputation calculations
    prevscore        INT                DEFAULT 0,
    -- score with a half life / decay
    reputation       FLOAT              NOT NULL DEFAULT 1.0,
    PRIMARY KEY (id),
    UNIQUE INDEX (userid),
    UNIQUE INDEX (name),
    INDEX (reputation)
);

-- -----------------------------------------------------
--
-- -----------------------------------------------------

CREATE EVENT reset_deeplusage ON SCHEDULE EVERY 1 MONTH STARTS '2021-01-01 00:00:00' DO
UPDATE channels SET deeplusage=0;

-- -----------------------------------------------------
-- Set the updatedatetime on changed channel content
-- -----------------------------------------------------

DELIMITER //
CREATE TRIGGER on_before_update_channel BEFORE UPDATE ON channels FOR EACH ROW
BEGIN
    IF NEW.bio<>OLD.bio
        OR NEW.name<>OLD.name
        OR NEW.bitcoinaddress<>OLD.bitcoinaddress
        OR NEW.avatar<>OLD.avatar THEN
        SET NEW.updatedatetime = CURRENT_TIMESTAMP;
    END IF;
END//
DELIMITER ;

-- ------------------------------------------------------------
-- Channel 1 has a special status
-- - Language setting is used for frontpage language
-- - cryptoaddress is used for incoming group payments
-- - administrative / editorial notes show in sidebar
-- ------------------------------------------------------------

INSERT INTO channels(name,userid) VALUES ("admin", "");

-- -----------------------------------------------------
-- The links that are being shared, rated, etc.
-- -----------------------------------------------------

CREATE TABLE links
(
    id             INT           NOT NULL AUTO_INCREMENT,
    channelid      INT           NOT NULL,
    createdatetime TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updatedatetime TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- datetime of the original publication
    origdatetime   DATETIME               DEFAULT NULL,
    published      BOOL          NOT NULL DEFAULT TRUE,
    url            VARCHAR(1024)          DEFAULT NULL,
    urlhash        CHAR(32) GENERATED ALWAYS AS (MD5(url)),
    mimetype       VARCHAR(128)           DEFAULT NULL,
    location       VARCHAR(256)           DEFAULT NULL,
    latitude       FLOAT                  DEFAULT NULL,
    longitude      FLOAT                  DEFAULT NULL,
    language       CHAR(2)                DEFAULT NULL,
    orig_language  CHAR(2)                DEFAULT NULL,
    title          VARCHAR(256)  NOT NULL,
    copyright      ENUM (
        'No Rights Apply', -- linkdump
        'All Rights Reserved',
        'No Rights Reserved (CC0 1.0)',
        'Some Rights Reserved (CC BY-SA 4.0)' ) DEFAULT NULL,
    -- Original raw markdown input
    markdown       MEDIUMTEXT             DEFAULT NULL,
    -- Parsed and filtered XHTML output, xtext can safely be cleared the API will render when null
    xtext          MEDIUMTEXT             DEFAULT NULL,
    -- Clean text blurb, set on insert
    description    VARCHAR(256)           DEFAULT NULL,
    image          VARCHAR(256)           DEFAULT NULL,
    -- because this system is very read intensive we will keep totals in this table
    -- instead of counting/joining the respective tables each time
    reactionscount INT                    DEFAULT 0,
    uniquereactors INT                    DEFAULT 0,
    uniquereferrers INT                   DEFAULT 0,
    viewscount     INT                    DEFAULT 0,
    votescount     INT                    DEFAULT 0,
    tagscount      INT                    DEFAULT 0,
    -- the scoring algorithm
    score          INT GENERATED ALWAYS AS (
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
    ),
    PRIMARY KEY (id),
    INDEX (channelid),
    INDEX (published),
    INDEX (createdatetime),
    INDEX (urlhash),
    INDEX (score),
    FULLTEXT (markdown),
    FOREIGN KEY (channelid) REFERENCES channels (id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
);

-- -----------------------------------------------------
-- Set the updatedatetime on changed link content only
-- -----------------------------------------------------

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
        BEGIN
            SET NEW.updatedatetime = CURRENT_TIMESTAMP;
            IF (OLD.published=FALSE) THEN
                SET NEW.createdatetime = CURRENT_TIMESTAMP;
            END IF;
        END;
    END IF;
    IF (NEW.published=FALSE AND OLD.published=TRUE) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cannot unpublish only delete';
    END IF;
END//
DELIMITER ;

-- --------------------------------------------------------------
-- Stores last 24h of interactions, used for frontpage algorithm
-- An event removes expired links (>24hr)
-- This is an optimisation that simplifies many queries and
-- avoids the need for datetimes+indexes in votes and tags
-- --------------------------------------------------------------

CREATE TABLE interactions
(
    id             INT         NOT NULL AUTO_INCREMENT,
    linkid         INT                  DEFAULT NULL,
    channelid      INT                  DEFAULT NULL,
    datetime       TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    type           ENUM (
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
    )                      NOT NULL,
    PRIMARY KEY (id),
    INDEX (datetime DESC),
    INDEX (linkid),
    INDEX (channelid),
    FOREIGN KEY (linkid) REFERENCES links (id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    FOREIGN KEY (channelid) REFERENCES channels (id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
);

-- -----------------------------------------------------
-- Purge interactions older than 24 hours
-- -----------------------------------------------------

CREATE EVENT purge_interactions ON SCHEDULE EVERY 1 HOUR DO
    DELETE FROM interactions WHERE datetime < SUBDATE(CURRENT_TIMESTAMP, INTERVAL 24 HOUR);

-- -----------------------------------------------------------
-- Select frontpage links from all links that had interactions
-- -----------------------------------------------------------

DELIMITER //
CREATE PROCEDURE select_frontpage(IN arg_datetime TIMESTAMP)
BEGIN
    IF (arg_datetime IS NULL) THEN
        SET arg_datetime=CURRENT_TIMESTAMP;
    END IF;
    SELECT DISTINCT links.* FROM links
        WHERE published=TRUE
        AND createdatetime<SUBDATE(arg_datetime, INTERVAL 3 HOUR)
      -- order by score, give old posts some half life decay after 3 hours
    ORDER BY (score / GREATEST(9, POW(TIMESTAMPDIFF(HOUR, arg_datetime, createdatetime),2))) DESC LIMIT 18;
END //
DELIMITER ;

-- We need a table WITHOUT indexes, preserve order of inserts, this will do that
CREATE TABLE frontpage_current SELECT * FROM links;

CREATE VIEW frontpage AS
    SELECT a.* FROM frontpage_current AS a JOIN links AS b ON a.id=b.id WHERE b.published=TRUE;

DELIMITER //
CREATE EVENT select_frontpage ON SCHEDULE EVERY 180 MINUTE DO
    BEGIN
        -- select new frontpage
        DROP TABLE IF EXISTS frontpage_new;
        CREATE TABLE frontpage_new
            SELECT DISTINCT links.* FROM links
            WHERE published=TRUE
              AND language=IFNULL((SELECT language FROM channels WHERE id=1),language)
              AND createdatetime<SUBDATE(CURRENT_TIMESTAMP, INTERVAL 3 HOUR)
            ORDER BY (score / GREATEST(9, POW(TIMESTAMPDIFF(HOUR, CURRENT_TIMESTAMP, createdatetime),2))) DESC LIMIT 18;

        -- notify frontpage selection in reactions, use temp table because of triggers
        DROP TABLE IF EXISTS reactions_temp;
        CREATE TABLE reactions_temp
            SELECT
                id AS linkid,
                1 AS channelid,
                "<em>-- selected for frontpage by system --</em>" AS xtext,
                "-- selected for frontpage by system --" AS description
            FROM frontpage_new WHERE NOT id IN (SELECT id FROM frontpage);
        INSERT INTO reactions(linkid,channelid,xtext,description)
            SELECT linkid,channelid,xtext,description FROM reactions_temp;
        UPDATE reactions AS r
            JOIN reactions_temp AS t ON r.linkid=t.linkid
            SET threadid=(SELECT MAX(id) FROM reactions WHERE linkid=r.linkid);

        -- atomic swap
        DROP TABLE IF EXISTS frontpage_old;
        RENAME TABLE frontpage_current TO frontpage_old, frontpage_new TO frontpage_current;

        -- notification
        INSERT INTO interactions(channelid, type) VALUES(1, 'on_frontpage_calculated');
    END //
DELIMITER ;

-- -------------------------------------------------------------------------
-- apply half life decay to current channel reputations and add delta score,
-- 0.9981 every day halfs the reputation in a year (0.9981^365=0.5)
-- -------------------------------------------------------------------------

DELIMITER //
CREATE EVENT calculate_channel_reputations ON SCHEDULE EVERY 24 HOUR DO
BEGIN
    INSERT INTO interactions(channelid,type) VALUES(1, 'on_reputation_calculated');
    UPDATE channels SET reputation = GREATEST(1, reputation * 0.9981 + score - prevscore), prevscore = score;
END //
DELIMITER ;

-- ------------------------------------------------
--
-- ------------------------------------------------

CREATE TRIGGER on_insert_channel AFTER INSERT ON channels FOR EACH ROW
    INSERT INTO interactions(channelid,type) VALUES (NEW.id,'on_insert_channel');

-- ------------------------------------------------
--
-- ------------------------------------------------

DELIMITER //
CREATE TRIGGER on_update_channel AFTER UPDATE ON channels FOR EACH ROW
BEGIN
    IF (NEW.bio<>OLD.bio
        OR NEW.name<>OLD.name
        OR NEW.bitcoinaddress<>OLD.bitcoinaddress
        OR NEW.avatar<>OLD.avatar) THEN
        INSERT INTO interactions(channelid,type) VALUES (NEW.id,'on_update_channel');
    END IF;
END//
DELIMITER ;

-- ------------------------------------------------
--
-- ------------------------------------------------

CREATE TRIGGER on_insert_link AFTER INSERT ON links FOR EACH ROW
    INSERT INTO interactions(linkid, channelid, type) VALUES (NEW.id, NEW.channelid, 'on_insert_link');

-- ------------------------------------------------
--
-- ------------------------------------------------

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

-- ------------------------------------------------
--
-- ------------------------------------------------

CREATE TRIGGER on_delete_link AFTER DELETE ON links FOR EACH ROW
    UPDATE channels SET score = score - OLD.score WHERE id = OLD.channelid;

-- --------------------------------------------------
-- reactions
-- --------------------------------------------------

CREATE TABLE reactions
(
    id             INT       NOT NULL AUTO_INCREMENT,
    -- optimization for forum-style display, all reactions on the same link
    -- have the id of the latest comment as threadid
    threadid       INT       NULL DEFAULT NULL,
    createdatetime TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    linkid         INT       NOT NULL,
    channelid      INT       NOT NULL,
    published      BOOL      NOT NULL DEFAULT TRUE,
    -- Original raw markdown input, currently not used
    markdown       TEXT               DEFAULT NULL,
    -- Clean text blurb, set on insert
    description    VARCHAR(256)       DEFAULT NULL,
    -- Purified xhtml from markdown input, no need to store original input because immutable
    xtext          TEXT               DEFAULT NULL,
    votescount     INT                DEFAULT 0,
    PRIMARY KEY (id),
    INDEX (channelid),
    INDEX (linkid),
    INDEX (threadid),
    INDEX (createdatetime),
    FOREIGN KEY (linkid) REFERENCES links (id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    FOREIGN KEY (channelid) REFERENCES channels (id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
);

DELIMITER //
CREATE TRIGGER on_insert_reaction AFTER INSERT ON reactions FOR EACH ROW
BEGIN
    UPDATE links SET
        reactionscount = reactionscount + 1,
        uniquereactors = (SELECT COUNT(DISTINCT channelid) FROM reactions WHERE linkid = NEW.linkid)
        WHERE id = NEW.linkid;
    INSERT INTO interactions(linkid,channelid,type) VALUES(NEW.linkid, NEW.channelid,'on_insert_reaction');
END//
DELIMITER ;

CREATE TRIGGER on_delete_reaction AFTER DELETE ON reactions FOR EACH ROW
    UPDATE links SET
        reactionscount = reactionscount - 1,
        uniquereactors = (SELECT COUNT(DISTINCT channelid) FROM reactions WHERE linkid = OLD.linkid)
        WHERE id = OLD.linkid;

DELIMITER //
CREATE PROCEDURE insert_reaction(IN arg_channelid INT, IN arg_linkid INT, IN arg_markdown TEXT, IN arg_xtext TEXT, IN arg_description VARCHAR(256))
BEGIN
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;
    SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ;
    START TRANSACTION;
        INSERT INTO reactions (channelid,linkid,markdown,xtext,description)
            VALUES(arg_channelid,arg_linkid,arg_markdown,arg_xtext,arg_description);
        -- threadid is a denormalisation / optimisation needed to be able to show forumstyle reactions pages
        UPDATE reactions SET threadid=LAST_INSERT_ID() WHERE linkid=arg_linkid;
    COMMIT;
END //
DELIMITER ;

-- --------------------------------------------------
-- Link tags
-- --------------------------------------------------

CREATE TABLE tags
(
    id             INT         NOT NULL AUTO_INCREMENT,
    linkid         INT         NOT NULL,
    -- analyzed 340.000 tags on old zaplog, 40 is usefull max
    -- most used table in the system, optimize for speed (no VARCHAR)
    tag            CHAR(40)    NOT NULL,
    PRIMARY KEY (id),
    INDEX (linkid),
    UNIQUE INDEX (tag, linkid),
    FOREIGN KEY (linkid) REFERENCES links (id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
);

DELIMITER //
CREATE TRIGGER on_insert_tag AFTER INSERT ON tags FOR EACH ROW
BEGIN
    UPDATE links SET tagscount = tagscount + 1 WHERE id = NEW.linkid;
END//
DELIMITER ;

CREATE TRIGGER on_delete_tag AFTER DELETE ON tags FOR EACH ROW
    UPDATE links SET tagscount = tagscount - 1 WHERE id = OLD.linkid;

-- --------------------------------------------------
-- The tag index
-- --------------------------------------------------

-- optimal/profiled
CREATE VIEW tagindex AS
    SELECT tag, COUNT(tag) as linkscount FROM tags GROUP BY tag ORDER BY tag;

-- --------------------------------------------------
-- The votes
-- --------------------------------------------------

CREATE TABLE votes
(
    id             INT NOT NULL AUTO_INCREMENT,
    linkid         INT NOT NULL,
    -- channel that votes
    channelid      INT NOT NULL,
    PRIMARY KEY (id),
    UNIQUE INDEX (linkid, channelid),
    INDEX (channelid),
    FOREIGN KEY (linkid) REFERENCES links (id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    FOREIGN KEY (channelid) REFERENCES channels (id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
);

DELIMITER //
CREATE TRIGGER on_insert_vote AFTER INSERT ON votes FOR EACH ROW
BEGIN
    UPDATE links SET votescount = votescount + 1 WHERE id = NEW.linkid;
    INSERT INTO interactions(linkid,channelid,type) VALUES (NEW.linkid, NEW.channelid,'on_insert_vote');
END//
DELIMITER ;

DELIMITER //
CREATE TRIGGER on_delete_vote AFTER DELETE ON votes FOR EACH ROW
BEGIN
    UPDATE links SET votescount = votescount - 1 WHERE id = OLD.linkid;
    INSERT INTO interactions(linkid,channelid,type) VALUES (OLD.linkid, OLD.channelid,'on_delete_vote');
END //
DELIMITER ;

DELIMITER //
CREATE PROCEDURE toggle_vote(IN arg_channelid INT, IN arg_linkid INT)
BEGIN
    IF (SELECT COUNT(*) FROM votes WHERE channelid=arg_channelid AND linkid=arg_linkid)>0 THEN
        DELETE FROM votes WHERE channelid=arg_channelid AND linkid=arg_linkid;
    ELSE
        INSERT INTO votes(channelid,linkid)VALUES(arg_channelid,arg_linkid);
    END IF;
END //
DELIMITER ;

-- --------------------------------------------------
-- The reaction votes
-- --------------------------------------------------

CREATE TABLE reactionvotes
(
    id             INT NOT NULL AUTO_INCREMENT,
    reactionid     INT NOT NULL,
    -- channel that votes
    channelid      INT NOT NULL,
    PRIMARY KEY (id),
    UNIQUE INDEX (reactionid, channelid),
    INDEX (channelid),
    FOREIGN KEY (reactionid) REFERENCES reactions (id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    FOREIGN KEY (channelid) REFERENCES channels (id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
);

DELIMITER //
CREATE TRIGGER on_insert_reactionvote AFTER INSERT ON reactionvotes FOR EACH ROW
BEGIN
    UPDATE reactions SET votescount = votescount + 1 WHERE id = NEW.reactionid;
    UPDATE channels SET score = score + 1 WHERE id = (SELECT channelid FROM reactions WHERE id=NEW.reactionid);
END//
DELIMITER ;

-- -----------------------------------------------------
-- Users (channels) that reacted last hour
-- -----------------------------------------------------

-- optimal/profiled
CREATE VIEW activeusers AS
    SELECT DISTINCT channels.*
    FROM channels JOIN interactions ON interactions.channelid=channels.id
    WHERE interactions.datetime > SUBDATE(CURRENT_TIMESTAMP, INTERVAL 1 HOUR);

-- -----------------------------------------------------
-- 24h Statistics
-- -----------------------------------------------------

CREATE VIEW statistics AS
SELECT (SELECT COUNT(*) FROM reactions)            AS numreactions,
       (SELECT COUNT(*) FROM channels)             AS numchannels,
       (SELECT COUNT(*) FROM links)                AS numposts,
       (SELECT COUNT(DISTINCT tag) FROM tags)      AS numtags,
       (SELECT COUNT(*) FROM votes)                AS numvotes;

-- --------------------------------------------------------
-- Most popular tags, this query should be cached by server
-- --------------------------------------------------------

-- should be cached higher up in the stack
-- (optimized/profiled)
CREATE VIEW toptopics AS
    SELECT DISTINCT tag FROM tags
    JOIN (SELECT id, score FROM links WHERE published=TRUE ORDER BY score DESC limit 1000) AS links
    ON tags.linkid = links.id
    GROUP BY tag
    ORDER BY SUM(score) DESC LIMIT 50;

-- (optimized/profiled)
CREATE VIEW newtopics AS
    SELECT DISTINCT tag FROM tags ORDER BY id DESC LIMIT 50;

-- --------------------------------------------------------
-- Most popular channels, this query should be cached by server
-- --------------------------------------------------------

-- optimal/profiled
CREATE VIEW topchannels AS
    SELECT channels.* FROM channels ORDER BY reputation DESC LIMIT 50;

-- optimal/profiled
CREATE VIEW updatedchannels AS
    SELECT DISTINCT channels.* FROM channels
    JOIN links ON links.channelid=channels.id
    GROUP BY channels.id
    ORDER BY MAX(links.id) DESC LIMIT 50;

-- --------------------------------------------------------
-- Most popular channels, this query should be cached by server
-- --------------------------------------------------------

-- optimal/profiled
CREATE VIEW topreactions AS
    SELECT links.title, channels.name, channels.avatar, x.* FROM (
        SELECT
            COUNT(reactionvotes.id) AS votecount,
            reactions.id,
            reactions.linkid,
            reactions.channelid,
            reactions.description,
            reactions.createdatetime
        FROM reactions
        LEFT JOIN reactionvotes ON reactions.id = reactionid AND reactions.createdatetime > SUBDATE(CURRENT_TIMESTAMP, INTERVAL 12 HOUR)
        WHERE reactions.channelid<>1
        GROUP BY reactions.id
        ORDER BY reactions.id DESC LIMIT 50
    ) AS x
    JOIN links ON x.linkid = links.id
    JOIN channels ON x.channelid = channels.id
    ORDER BY votecount DESC, id DESC;

-- -----------------------------------------------------
-- Returns a channel's most popular tags
-- -----------------------------------------------------

DELIMITER //
CREATE PROCEDURE select_channel_tags(IN arg_channelid INT)
BEGIN
    SELECT tag AS tagscount
    FROM tags JOIN links ON tags.linkid=links.id
    WHERE links.channelid=arg_channelid AND links.published-TRUE
    GROUP BY tag ORDER BY SUM(score) DESC LIMIT 20;
END //
DELIMITER ;

-- -----------------------------------------------------
-- The channel mailinglists
-- -----------------------------------------------------

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

