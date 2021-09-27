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
    id             INT                NOT NULL AUTO_INCREMENT,
    -- we don't store anything from the user, just hashed email address
    userid         CHAR(32)           NOT NULL,
    name           VARCHAR(55)        NOT NULL,
    language       CHAR(2)            DEFAULT NULL,
    -- automatic RSS content
    feedurl        VARCHAR(255)       DEFAULT NULL,
    themeurl       VARCHAR(255)       DEFAULT NULL,
    createdatetime TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updatedatetime TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    refeeddatetime TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    avatar         VARCHAR(255)       DEFAULT NULL,
    bkgimage       VARCHAR(55)        DEFAULT NULL,
    bio            VARCHAR(255)       DEFAULT NULL,
    moneroaddress  CHAR(93)           DEFAULT NULL,
    -- sum of all related link scores
    score          INT                DEFAULT 0,
    -- for internal bookkeeping during reputation calculations
    prevscore      INT                DEFAULT 0,
    -- score with a half life / decay
    reputation     FLOAT              NOT NULL DEFAULT 1.0,
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

-- -------------------------------------------------------------------------
-- apply half life decay to current channel reputations and add delta score,
-- 0.9981 every day halfs the reputation in a year (0.9981^365=0.5)
-- -------------------------------------------------------------------------

CREATE EVENT apply_reputation_decay ON SCHEDULE EVERY 24 HOUR DO
    UPDATE channels SET reputation = reputation * 0.9981 + score - prevscore, prevscore = score;

-- -----------------------------------------------------
-- Posts that are place on this channel are collaborative
-- (can be edited by any member with enough reputation)
-- -----------------------------------------------------

INSERT INTO channels(name,bio,userid) VALUES ("Zaplog", "Next-generation social blogging platform.", MD5("patrick@patricksavalle.com"));

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
    title          VARCHAR(256)  NOT NULL,
    copyright      VARCHAR(256)           DEFAULT NULL,
    description    TEXT                   DEFAULT NULL,
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
        OR NEW.description<>OLD.description
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
    channelid      INT         NOT NULL,
    datetime       TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    type           ENUM (
        'on_insert_channel',
        'on_update_channel',
        'on_insert_link',
        'on_update_link',
        'on_insert_reaction',
        'on_insert_vote',
        'on_insert_tag',
        'on_receive_cash'
        )                      NOT NULL,
    PRIMARY KEY (id),
    INDEX (datetime),
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

-- should be cached higher up in the stack
CREATE VIEW frontpage AS
    SELECT DISTINCT links.* FROM interactions JOIN links ON interactions.linkid = links.id
    WHERE published=TRUE
          -- order by score, give old posts some half life decay after 3 hours
    ORDER BY (score / GREATEST(9, POWER(TIMESTAMPDIFF(HOUR, CURRENT_TIMESTAMP, createdatetime), 2))) DESC LIMIT 25;

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
    IF (NEW.description<>OLD.description
        OR NEW.image<>OLD.image
        OR NEW.title<>OLD.title
        OR NEW.url<>OLD.url) THEN
        INSERT INTO interactions(channelid, type) VALUES (NEW.id, 'on_update_link');
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
    createdatetime TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    linkid         INT       NOT NULL,
    channelid      INT       NOT NULL,
    published      BOOL      NOT NULL DEFAULT TRUE,
    text           TEXT               DEFAULT NULL,
    PRIMARY KEY (id),
    INDEX (channelid),
    INDEX (linkid),
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

CREATE TRIGGER on_delete_vote AFTER DELETE ON votes FOR EACH ROW
    UPDATE links SET votescount = votescount - 1 WHERE id = OLD.linkid;

-- -----------------------------------------------------
-- Users (channels) that reacted last hour
-- -----------------------------------------------------

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
       (SELECT COUNT(*) FROM channels WHERE createdatetime > SUBDATE(CURRENT_TIMESTAMP, INTERVAL 1 MONTH)) AS newchannels1m,
       (SELECT COUNT(*) FROM tags)                                                                         AS numtags,
       (SELECT COUNT(*) FROM votes)                                                                        AS numvotes;

-- --------------------------------------------------------
-- Most popular tags, this query should be cached by server
-- --------------------------------------------------------

-- should be cached higher up in the stack
CREATE VIEW trendingtopics AS
    SELECT tags.* FROM tags
    JOIN (SELECT id, score FROM frontpage) AS links ON tags.linkid = links.id
    GROUP BY tags.tag
    ORDER BY SUM(score) DESC LIMIT 25;

-- should be cached higher up in the stack
CREATE VIEW toptopics AS
    SELECT tags.* FROM tags
    JOIN (SELECT id, score FROM links ORDER BY score DESC limit 1000) AS links
    ON tags.linkid = links.id
    GROUP BY tag ORDER BY SUM(score) DESC LIMIT 25;

CREATE VIEW newtopics AS
    SELECT tags.* FROM tags GROUP BY tag ORDER BY id DESC LIMIT 25;

-- --------------------------------------------------------
-- Most popular channels, this query should be cached by server
-- --------------------------------------------------------

-- should be cached higher up in the stack
CREATE VIEW trendingchannels AS
    SELECT channels.* FROM channels_public_view AS channels
    JOIN (SELECT * FROM frontpage) AS links ON channels.id = links.channelid
    GROUP BY channels.id
    ORDER BY SUM(score) DESC LIMIT 25;

CREATE VIEW topchannels AS
    SELECT channels.* FROM channels_public_view AS channels ORDER BY reputation DESC LIMIT 25;

CREATE VIEW newchannels AS
    SELECT channels.* FROM channels_public_view AS channels ORDER BY id DESC LIMIT 25;

-- -----------------------------------------------------
-- RAW activity stream, needs PHP post-processing
-- -----------------------------------------------------

CREATE VIEW activitystream AS
    SELECT
        interactions.*,
        channels.name AS channelname,
        channels.avatar AS channelavatar,
        links.title AS linktitle,
        links.image AS linkimage,
        links.description AS linktext
    FROM interactions
    JOIN channels_public_view AS channels ON channels.id=interactions.channelid
    LEFT JOIN links ON links.id=interactions.linkid AND interactions.type = 'on_insert_link';

-- -----------------------------------------------------
-- Returns a channel's most popular tags
-- -----------------------------------------------------

DELIMITER //
CREATE PROCEDURE select_channel_tags(IN arg_channelid INT)
BEGIN
    SELECT tag, COUNT(tag) AS tagscount
    FROM tags JOIN links ON tags.linkid=links.id
    WHERE links.channelid=arg_channelid
    GROUP BY tag ORDER BY SUM(score) DESC LIMIT 10;
END //
DELIMITER ;

-- -----------------------------------------------------
-- Returns forum style reactions, order by most recent
-- -----------------------------------------------------

DELIMITER //
CREATE PROCEDURE select_discussion(IN arg_offset INT, IN arg_count INT)
BEGIN
    SELECT ranked_reactions.*, links.title FROM
        (SELECT reactions.*,
                @link_rank := IF(@current = linkid, @link_rank + 1, 1) AS link_rank,
                @current := linkid
         FROM reactions JOIN links ON reactions.linkid=links.id
         ORDER BY updatedatetime DESC, linkid, reactions.id) AS ranked_reactions
            LEFT JOIN links ON links.id=ranked_reactions.linkid AND link_rank=1
    WHERE link_rank<=3
    LIMIT arg_offset, arg_count;
END //
DELIMITER ;

-- -----------------------------------------------------
-- RSS-feeds
-- -----------------------------------------------------

INSERT INTO channels(name, feedurl, avatar, userid) VALUES
    ("NYT Science", "http://www.nytimes.com/services/xml/rss/nyt/Science.xml", "https://api.multiavatar.com/nyt", "1@ab55c.xyz"),
    ("Breitbart", "https://feeds.feedburner.com/breitbart", "https://api.multiavatar.com/Breitbart", "1@abc.xyz"),
    ("Wired", "http://blog.wired.com/wiredscience/atom.xml", "https://api.multiavatar.com/Wired", "2@abc.xyz"),
    ("Tech Xplore", "https://techxplore.com/rss-feed/", "https://api.multiavatar.com/Tech Xplore", "3@abc.xyz"),
    ("PhysOrg", "https://phys.org/rss-feed/", "https://api.multiavatar.com/PhysOrg", "4@abc.xyz"),
    ("CNET", "https://www.cnet.com/rss/all", "https://api.multiavatar.com/CNET", "5@abc.xyz"),
    ("Gizmodo", "https://gizmodo.com/rss", "https://api.multiavatar.com/Gizmodo", "6@abc.xyz");

INSERT INTO channels(name, avatar, userid) VALUES
    ("Website Victories", "https://api.multiavatar.com/Zaplog", "7@abc.xyz"),
    ("Pro Star", "https://api.multiavatar.com/Zaplog", "4sssss@abc.xyz"),
    ("Future Bright", "https://api.multiavatar.com/Zaplog", "4asd@abc.xyz"),
    ("Pinnacle Inc", "https://api.multiavatar.com/Zaplog", "4wer@abc.xyz"),
    ("Corinthian Designs", "https://api.multiavatar.com/Zaplog", "4234@abc.xyz"),
    ("Lawn N’ Order Garden Car", "https://api.multiavatar.com/Zaplog", "4wef@abc.xyz"),
    ("Show and Tell", "https://api.multiavatar.com/Zaplog", "asd4@abc.xyz"),
    ("Hiking Diary", "https://api.multiavatar.com/Zaplog", "uiyui4@abc.xyz"),
    ("Dreamscape Garden Care", "https://api.multiavatar.com/Zaplog", "4bnr@abc.xyz"),
    ("Blogged Bliss", "https://api.multiavatar.com/Zaplog", "4der5@abc.xyz"),
    ("Fresh Internet Services", "https://api.multiavatar.com/Zaplog", "48ikfd@abc.xyz"),
    ("Mystique", "https://api.multiavatar.com/Zaplog", "43455hjn@abc.xyz"),
    ("Olax Net", "https://api.multiavatar.com/Zaplog", "456udv@abc.xyz"),
    ("Superior Interior Design", "https://api.multiavatar.com/Zaplog", "4435grfgs@abc.xyz"),
    ("Electric Essence", "https://api.multiavatar.com/Zaplog", "4add@abc.xyz"),
    ("Strength Gurus", "https://api.multiavatar.com/Zaplog", "4ssss@abc.xyz"),
    ("Will Thrill", "https://api.multiavatar.com/Zaplog", "4dddd@abc.xyz"),
    ("Custom Lawn Care", "https://api.multiavatar.com/Zaplog", "4ffff@abc.xyz"),
    ("Total Network Development", "https://api.multiavatar.com/Zaplog", "4gggg@abc.xyz"),
    ("Blogify", "https://api.multiavatar.com/Zaplog", "hhhh4@abc.xyz"),
    ("Cut Rite", "https://api.multiavatar.com/Zaplog", "hhhffd4@abc.xyz"),
    ("Gold Leaf Garden Management", "https://api.multiavatar.com/Zaplog", "4fgfg@abc.xyz"),
    ("Cupid Inc", "https://api.multiavatar.com/Zaplog", "ertio4@abc.xyz"),
    ("Omni Tech Solutions", "https://api.multiavatar.com/Zaplog", "uio4@abc.xyz"),
    ("Locksmith", "https://api.multiavatar.com/Zaplog", "4uio@abc.xyz"),
    ("Perisolution", "https://api.multiavatar.com/Zaplog", "4hkjh@abc.xyz"),
    ("Pen And Paper", "https://api.multiavatar.com/Zaplog", "4try@abc.xyz"),
    ("Strawberry Inc", "https://api.multiavatar.com/Zaplog", "yuimn4@abc.xyz"),
    ("Dye Hard", "https://api.multiavatar.com/Zaplog", "4werwer@abc.xyz"),
    ("Solution Answers", "https://api.multiavatar.com/Zaplog", "wer@abc.xyz"),
    ("Netmark", "https://api.multiavatar.com/Zaplog", "5464567@abc.xyz"),
    ("Afforda", "https://api.multiavatar.com/Zaplog", "vbnvbnvnb@abc.xyz"),
    ("The Nosh Pit", "https://api.multiavatar.com/Zaplog", "aq1@abc.xyz");
