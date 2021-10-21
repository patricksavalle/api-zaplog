/**
 * zaplog V2
 * @author:     patrick@patricksavalle.com
 */

/*
    I tried to make the datamodel as complete and self-contained as possible.
    It should be possible to use the model with a different code-base on top.

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
    avatar           VARCHAR(255)       DEFAULT NULL,
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

INSERT INTO channels(name,bio,userid,avatar) VALUES ("zaplog", "Next-generation social blogging platform.", MD5("patrick@patricksavalle.com"), "https://gitlab.com/uploads/-/system/group/avatar/13533618/zapruderlogo.png");

-- -----------------------------------------------------
-- For public queries. Hide privacy data.
-- -----------------------------------------------------

CREATE VIEW channels_public_view AS
    SELECT id,name,createdatetime,updatedatetime,bio,avatar,feedurl,reputation,moneroaddress FROM channels;

-- -----------------------------------------------------
-- The links that are being shared, rated, etc.
-- -----------------------------------------------------

CREATE TABLE links
(
    id             INT           NOT NULL AUTO_INCREMENT,
    createdatetime TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updatedatetime TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    checkdatetime  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    channelid      INT           NOT NULL,
    published      BOOL          NOT NULL DEFAULT TRUE,
    urlhash        CHAR(32) GENERATED ALWAYS AS (MD5(url)),
    url            VARCHAR(1024) NOT NULL,
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
    viewscount     INT                    DEFAULT 0,
    votescount     INT                    DEFAULT 0,
    tagscount      INT                    DEFAULT 0,
    score          INT GENERATED ALWAYS AS (
                           FLOOR(LN(uniquereactors + 2.71828) * reactionscount * 5) +
                           votescount * 10 +
                           tagscount * 2 +
                           FLOOR(LOG(viewscount + 1))
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
CREATE PROCEDURE calculate_frontpage()
BEGIN
    START TRANSACTION;
    DROP TABLE IF EXISTS frontpage;
    CREATE TABLE frontpage
        SELECT DISTINCT links.*
        FROM interactions
        JOIN links ON interactions.linkid = links.id
        WHERE published=TRUE
        -- order by score, give old posts some half life decay after 3 hours
        ORDER BY (score / GREATEST(9, POWER(TIMESTAMPDIFF(HOUR, CURRENT_TIMESTAMP, createdatetime), 2))) DESC LIMIT 18;
    COMMIT;
END //
DELIMITER ;

CREATE EVENT calculate_frontpage ON SCHEDULE EVERY 1 HOUR DO CALL calculate_frontpage();

-- -------------------------------------------------------------------------
-- apply half life decay to current channel reputations and add delta score,
-- 0.9981 every day halfs the reputation in a year (0.9981^365=0.5)
-- -------------------------------------------------------------------------

DELIMITER //
CREATE EVENT apply_reputation_decay ON SCHEDULE EVERY 24 HOUR DO
    BEGIN
        INSERT INTO interactions(type) VALUES('on_reputation_calculated');
        UPDATE channels SET reputation = reputation * 0.9981 + score - prevscore, prevscore = score;
    END//
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
    -- optimization for forum-style display
    threadid       INT       NULL DEFAULT NULL,
    createdatetime TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    linkid         INT       NOT NULL,
    channelid      INT       NOT NULL,
    published      BOOL      NOT NULL DEFAULT TRUE,
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
SELECT (SELECT COUNT(*) FROM links WHERE createdatetime > SUBDATE(CURRENT_TIMESTAMP, INTERVAL 1 DAY))      AS numposts24h,
       (SELECT COUNT(*) FROM links WHERE createdatetime > SUBDATE(CURRENT_TIMESTAMP, INTERVAL 1 MONTH))    AS numposts1m,
       (SELECT COUNT(*) FROM channels)                                                                     AS numchannels,
       (SELECT COUNT(*) FROM links)                                                                        AS numposts,
       (SELECT COUNT(*) FROM channels WHERE createdatetime > SUBDATE(CURRENT_TIMESTAMP, INTERVAL 1 MONTH)) AS newchannels1m,
       (SELECT COUNT(*) FROM tags)                                                                         AS numtags,
       (SELECT COUNT(*) FROM votes)                                                                        AS numvotes;

-- --------------------------------------------------------
-- Most popular tags, this query should be cached by server
-- --------------------------------------------------------

-- should be cached higher up in the stack
-- (optimized)
CREATE VIEW trendingtopics AS
    SELECT tags.* FROM tags
    JOIN links ON tags.linkid=links.id
    WHERE tags.linkid in (SELECT id FROM frontpage)
    GROUP BY tag
    ORDER BY SUM(score) DESC LIMIT 50;

-- should be cached higher up in the stack
-- (optimized/profiled)
CREATE VIEW toptopics AS
    SELECT DISTINCT tag FROM tags
    JOIN (SELECT id, score FROM links ORDER BY score DESC limit 1000) AS links
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

CREATE VIEW updatedchannels AS
    SELECT DISTINCT channels.id, channels.name, channels.avatar FROM channels_public_view AS channels
    JOIN links ON links.channelid=channels.id
    GROUP BY channels.id
    ORDER BY MAX(links.id) DESC LIMIT 50;

-- -----------------------------------------------------
-- Returns a channel's most popular tags
-- -----------------------------------------------------

DELIMITER //
CREATE PROCEDURE select_channel_tags(IN arg_channelid INT)
BEGIN
    SELECT tag, COUNT(tag) AS tagscount
    FROM tags JOIN links ON tags.linkid=links.id
    WHERE links.channelid=arg_channelid
    GROUP BY tag ORDER BY SUM(score) DESC LIMIT 20;
END //
DELIMITER ;

-- -----------------------------------------------------
-- Returns forum style reactions, order by most recent
-- TODO why not just a view?
-- -----------------------------------------------------

DELIMITER //
CREATE PROCEDURE select_discussion(IN arg_offset INT, IN arg_count INT)
BEGIN
    SELECT ranked_reactions.*, links.title FROM
        (SELECT reactions.*,
               @link_rank := IF(@current = linkid, @link_rank + 1, 1) AS link_rank,
               @current := linkid
        FROM reactions
        ORDER BY threadid DESC, reactions.id DESC) AS ranked_reactions
        LEFT JOIN links ON links.id=ranked_reactions.linkid AND link_rank=1
    WHERE link_rank<=3
    LIMIT arg_offset, arg_count;
END //
DELIMITER ;

DELIMITER //
CREATE PROCEDURE insert_reaction(IN arg_channelid INT, IN arg_linkid INT, IN arg_xtext TEXT)
BEGIN
    INSERT INTO reactions(channelid,linkid,xtext)VALUES(arg_channelid,arg_linkid,arg_xtext);
    UPDATE reactions SET threadid=LAST_INSERT_ID() WHERE linkid=arg_linkid;
END //
DELIMITER ;


