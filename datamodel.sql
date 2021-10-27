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
SET GLOBAL TRANSACTION ISOLATION LEVEL READ UNCOMMITTED ;

DROP SCHEMA IF EXISTS zaplog;
CREATE SCHEMA zaplog
    DEFAULT CHARACTER SET utf8
    DEFAULT COLLATE utf8_general_ci;
USE zaplog;

-- -----------------------------------------------------
-- Channels are collections of links
-- -----------------------------------------------------

CREATE TABLE channels
(
    id               INT                NOT NULL AUTO_INCREMENT,
    -- we don't store anything from the user, just hashed email address
    userid           CHAR(32)           NOT NULL,
    name             VARCHAR(55)        NOT NULL,
    language         CHAR(2)            DEFAULT NULL,
    -- automatic RSS content
    feedurl          VARCHAR(255)       DEFAULT NULL,
    theme            VARCHAR(255)       DEFAULT NULL,
    createdatetime   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updatedatetime   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    refeeddatetime   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    lastseendatetime TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- inline base64 encoded avatars
    avatar           VARCHAR(16384)     DEFAULT NULL,
    header           VARCHAR(255)       DEFAULT NULL,
    bio              VARCHAR(255)       DEFAULT NULL,
    moneroaddress    CHAR(93)           DEFAULT NULL,
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
-- Set the updatedatetime on changed channel content
-- -----------------------------------------------------

DELIMITER //
CREATE TRIGGER on_before_update_channel BEFORE UPDATE ON channels FOR EACH ROW
BEGIN
    IF NEW.bio<>OLD.bio
        OR NEW.name<>OLD.name
        OR NEW.moneroaddress<>OLD.moneroaddress
        OR NEW.avatar<>OLD.avatar THEN
        SET NEW.updatedatetime = CURRENT_TIMESTAMP;
    END IF;
END//
DELIMITER ;

-- -----------------------------------------------------
-- Posts that are placed on this channel are collaborative
-- (can be edited by any member with enough reputation)
-- -----------------------------------------------------

INSERT INTO channels(name,bio,userid,avatar) VALUES ("zaplog", "Next-generation social blogging platform.", MD5("zaplog@patricksavalle.com"), "https://gitlab.com/uploads/-/system/group/avatar/13533618/zapruderlogo.png");

-- -----------------------------------------------------
-- For public queries. Hide privacy data.
-- -----------------------------------------------------

CREATE VIEW channels_public_view AS
    SELECT id,name,createdatetime,updatedatetime,bio,avatar,ROUND(reputation) AS reputation FROM channels;

-- -----------------------------------------------------
-- The links that are being shared, rated, etc.
-- -----------------------------------------------------

CREATE TABLE links
(
    id             INT           NOT NULL AUTO_INCREMENT,
    createdatetime TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updatedatetime TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    checkdatetime  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- datetime of the original publication
    origdatetime   DATETIME               DEFAULT NULL,
    channelid      INT           NOT NULL,
    published      BOOL          NOT NULL DEFAULT TRUE,
    urlhash        CHAR(32) GENERATED ALWAYS AS (MD5(url)),
    url            VARCHAR(1024) NOT NULL,
    source         ENUM ('feed','api','site') DEFAULT 'site',
    waybackurl     VARCHAR(1024) NULL     DEFAULT NULL,
    location       VARCHAR(256)  NULL     DEFAULT NULL,
    latitude       FLOAT         NULL     DEFAULT NULL,
    longitude      FLOAT         NULL     DEFAULT NULL,
    language       CHAR(2)                DEFAULT NULL,
    title          VARCHAR(256)  NOT NULL,
    copyright      ENUM (
        'No Rights Apply',
        'All Rights Reserved',
        'No Rights Reserved (CC0 1.0)',
        'Some Rights Reserved (CC BY-NC-SA 4.0)' ) DEFAULT NULL,
    -- Original raw markdown input
    markdown       TEXT                   DEFAULT NULL,
    -- Clean text blurb
    description    VARCHAR(256)           DEFAULT NULL,
    -- Parsed and filtered XHTML output, placeholder, set on output by PHP layer
    xtext          TEXT GENERATED ALWAYS AS (''),
    image          VARCHAR(256)           DEFAULT NULL,
    -- because this system is very read intensive we will keep totals in this table
    -- instead of counting/joining the respective tables each time
    reactionscount INT                    DEFAULT 0,
    uniquereactors INT                    DEFAULT 0,
    uniquereferrers INT                   DEFAULT 0,
    viewscount     INT                    DEFAULT 0,
    votescount     INT                    DEFAULT 0,
    tagscount      INT                    DEFAULT 0,
    score          INT GENERATED ALWAYS AS (

            -- the scoring algorithm
            (votescount+1) *
            -- no tags, no score
            IF(tagscount = 0 , 0, 1) *
            -- no content, no score
            IF(markdown IS NULL OR LENGTH(markdown) = 0, 0, 1) *
            -- double score for real articles
            IF(copyright IS NULL OR copyright = 'No Rights Apply', 1, 2) *
            -- weigh the active and passive factors
            (
                ROUND(
                    LOG(10, 1 + uniquereactors * 10) +
                    LOG(10, 1 + IF(markdown IS NULL, 0, LENGTH(markdown))) +
                    LOG(10, 1 + uniquereferrers) +
                    LOG(10, 1 + reactionscount) +
                    LOG(10, 1 + viewscount / 10)
                )
            )

        ),
    PRIMARY KEY (id),
    INDEX (channelid),
    INDEX (published),
    INDEX (createdatetime),
    INDEX (updatedatetime),
    INDEX (score),
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
        OR NEW.url<>OLD.url
        OR NEW.image<>OLD.image
        -- also update on added reactions (used for 'discussion' query)
        OR NEW.reactionscount>OLD.reactionscount) THEN
        SET NEW.updatedatetime = CURRENT_TIMESTAMP;
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
    SELECT DISTINCT links.* FROM links WHERE published=TRUE AND createdatetime<SUBDATE(arg_datetime, INTERVAL 3 HOUR)
      -- order by score, give old posts some half life decay after 3 hours
    ORDER BY (score / GREATEST(9, POWER(TIMESTAMPDIFF(HOUR, arg_datetime, createdatetime), 2))) DESC LIMIT 24;
END //
DELIMITER ;

CREATE VIEW frontpage AS
    SELECT DISTINCT links.* FROM links WHERE published=TRUE AND createdatetime<SUBDATE(CURRENT_TIMESTAMP, INTERVAL 3 HOUR)
    ORDER BY (score / GREATEST(9, POWER(TIMESTAMPDIFF(HOUR, CURRENT_TIMESTAMP, createdatetime), 2))) DESC LIMIT 24;

-- -------------------------------------------------------------------------
-- apply half life decay to current channel reputations and add delta score,
-- 0.9981 every day halfs the reputation in a year (0.9981^365=0.5)
-- -------------------------------------------------------------------------

DELIMITER //
CREATE PROCEDURE calculate_channel_reputations()
BEGIN
    INSERT INTO interactions(type) VALUES('on_reputation_calculated');
    UPDATE channels SET reputation = reputation * 0.9981 + score - prevscore, prevscore = score;
END //
DELIMITER ;

CREATE EVENT calculate_channel_reputations ON SCHEDULE EVERY 24 HOUR DO CALL calculate_channel_reputations();

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
        OR NEW.moneroaddress<>OLD.moneroaddress
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
    -- Clean text blurb
    description    VARCHAR(256)       DEFAULT NULL,
    -- Purified xhtml from markdown input, no need to store original input because immutable
    xtext          TEXT               DEFAULT NULL,
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

-- --------------------------------------------------
-- Link tags
-- --------------------------------------------------

CREATE TABLE tags
(
    id             INT         NOT NULL AUTO_INCREMENT,
    linkid         INT         NOT NULL,
    -- channel (=user) that added the tags
    channelid      INT         NOT NULL,
    tag            VARCHAR(50) NOT NULL,
    PRIMARY KEY (id),
    INDEX (linkid),
    INDEX (channelid),
    UNIQUE INDEX (tag, linkid),
    FOREIGN KEY (linkid) REFERENCES links (id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    FOREIGN KEY (channelid) REFERENCES channels (id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
);

DELIMITER //
CREATE TRIGGER on_insert_tag AFTER INSERT ON tags FOR EACH ROW
BEGIN
    UPDATE links SET tagscount = tagscount + 1 WHERE id = NEW.linkid;
    INSERT INTO interactions(linkid,channelid,type) VALUES(NEW.linkid, NEW.channelid,'on_insert_tag');
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

-- -----------------------------------------------------
-- Users (channels) that reacted last hour
-- -----------------------------------------------------

-- optimal/profiled
CREATE VIEW activeusers AS
    SELECT DISTINCT channels.*
    FROM channels_public_view AS channels JOIN interactions ON interactions.channelid=channels.id
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
-- (optimized)
CREATE VIEW trendingtopics AS
    SELECT tag FROM tags
    JOIN links ON tags.linkid=links.id
    WHERE tags.linkid in (SELECT id FROM frontpage) AND links.published=TRUE
    GROUP BY tag
    ORDER BY SUM(score) DESC LIMIT 50;

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

-- should be cached higher up in the stack
-- (optimized)
CREATE VIEW trendingchannels AS
    SELECT channels.* FROM channels_public_view AS channels
    JOIN (SELECT * FROM frontpage) AS links ON channels.id = links.channelid
    GROUP BY channels.id
    ORDER BY SUM(score) DESC LIMIT 50;

-- optimal/profiled
CREATE VIEW topchannels AS
    SELECT channels.* FROM channels_public_view AS channels ORDER BY reputation DESC LIMIT 50;

-- optimal/profiled
CREATE VIEW updatedchannels AS
    SELECT DISTINCT channels.* FROM channels_public_view AS channels
    JOIN links ON links.channelid=channels.id
    GROUP BY channels.id
    ORDER BY MAX(links.id) DESC LIMIT 50;

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

-- ---------------------------------------------------------------------------------
-- Returns forum style reactions, order by most recent thread, 3 comments per thread
-- ---------------------------------------------------------------------------------

DELIMITER //
CREATE PROCEDURE select_discussion(IN arg_channelid INT, IN arg_offset INT, IN arg_count INT)
BEGIN
    SELECT
        reactions.id,
        reactions.createdatetime,
        reactions.description,
        reactions.channelid,
        reactions.linkid,
        channels.avatar,
        channels.name,
        links.title,
        links.createdatetime AS linkdatetime
    FROM (
         SELECT id, channelid, linkid, x.threadid FROM (
            SELECT r.threadid, r.id, r.channelid, r.linkid, (@num:=if(@threadid = r.threadid, @num +1, if(@threadid := r.threadid, 1, 1))) AS row_num
            FROM reactions AS r
            ORDER BY r.threadid DESC, r.id DESC
         ) AS x
         JOIN (
            SELECT threadid FROM reactions
            WHERE (arg_channelid IS NULL OR channelid=arg_channelid)
            GROUP BY threadid
            ORDER BY threadid DESC LIMIT arg_offset, arg_count) AS t ON x.threadid=t.threadid
         WHERE x.row_num <= 3
    ) AS r
    JOIN reactions ON reactions.id=r.id
    JOIN channels ON channels.id=r.channelid
    LEFT JOIN links ON links.id=r.linkid
    ORDER by r.threadid DESC, r.id ASC;
END //
DELIMITER ;

DELIMITER //
CREATE PROCEDURE insert_reaction(IN arg_channelid INT, IN arg_linkid INT, IN arg_xtext TEXT, IN arg_description VARCHAR(256))
BEGIN
    INSERT INTO reactions (channelid,linkid,xtext,description)
        VALUES(arg_channelid,arg_linkid,arg_xtext,arg_description);
    UPDATE reactions SET threadid=LAST_INSERT_ID() WHERE linkid=arg_linkid;
END //
DELIMITER ;


-- --------------------------------------------------
--
-- --------------------------------------------------

CREATE TABLE referrers
(
    id     INT           NOT NULL AUTO_INCREMENT,
    hash   BINARY(16)    NOT NULL,
    url    VARCHAR(1024) NOT NULL,
    domain VARCHAR(50)   NOT NULL,
    PRIMARY KEY (id),
    UNIQUE INDEX (hash)
);

CREATE TABLE referrals
(
    referrerid  INT NOT NULL,
    linkid      INT NOT NULL,
    INDEX (linkid)
)