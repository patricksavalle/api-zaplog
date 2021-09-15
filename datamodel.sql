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
    id             INT       NOT NULL AUTO_INCREMENT,
    -- we don't store anything from the user, just an identity hash (email, phone, anything)
    userid         CHAR(32)           DEFAULT NULL,
    name           VARCHAR(55)        DEFAULT NULL,
    language       CHAR(2)            DEFAULT NULL,
    -- automatic RSS content
    feedurl        VARCHAR(256)       DEFAULT NULL,
    themeurl       VARCHAR(256)       DEFAULT NULL,
    createdatetime TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updatedatetime TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    refeeddatetime TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    avatar         VARCHAR(55)        DEFAULT NULL,
    bkgimage       VARCHAR(55)        DEFAULT NULL,
    bio            VARCHAR(255)       DEFAULT NULL,
    moneroaddress  CHAR(93)           DEFAULT NULL,
    -- accumulate statistics on posts of this channel
    -- because this system is very read intensive we will keep totals in this table
    -- instead of counting/joining the respective tables each time
    bookmarkscount INT                DEFAULT 0,
    postscount     INT                DEFAULT 0,
    viewscount     INT                DEFAULT 0,
    votescount     INT                DEFAULT 0,
    uniquevoters   INT                DEFAULT 0,
    reactionscount INT                DEFAULT 0,
    uniquereactors INT                DEFAULT 0,
    tagscount      INT                DEFAULT 0,
    score          INT GENERATED ALWAYS AS (
                           FLOOR(LN(uniquereactors + 2.71828) * reactionscount * 2) +
                           FLOOR(LN(uniquevoters + 2.71828) * votescount * 5) +
                           bookmarkscount * 20 +
                           postscount * 10 +
                           tagscount * 1 +
                           FLOOR(LOG(viewscount + 1))
                       ),
    prevscore      INT                DEFAULT 0,
    -- score with a half life / decay
    reputation     FLOAT              DEFAULT 0.0,
    PRIMARY KEY (id),
    UNIQUE INDEX (userid),
    UNIQUE INDEX (name),
    UNIQUE INDEX (feedurl),
    INDEX (reputation)
);

-- -----------------------------------------------------
-- For public queries. Hide privacy data.
-- -----------------------------------------------------

CREATE VIEW channels_public_view AS
SELECT id,
       name,
       createdatetime,
       updatedatetime,
       bio,
       avatar,
       postscount,
       viewscount,
       votescount,
       score,
       reputation
FROM channels;

-- -----------------------------------------------------
-- The links that are being shared, rated, etc.
-- -----------------------------------------------------

CREATE TABLE links
(
    id             INT           NOT NULL AUTO_INCREMENT,
    createdatetime TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    crawldatetime  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
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
    bookmarkscount INT                    DEFAULT 0,
    viewscount     INT                    DEFAULT 0,
    votescount     INT                    DEFAULT 0,
    tagscount      INT                    DEFAULT 0,
    score          INT GENERATED ALWAYS AS (
                           FLOOR(LN(uniquereactors + 2.71828) * reactionscount * 2) +
                           votescount * 5 +
                           bookmarkscount * 20 +
                           tagscount * 1 +
                           FLOOR(LOG(viewscount + 1))
                       ),
    PRIMARY KEY (id),
    INDEX (channelid),
    INDEX (urlhash),
    INDEX (published),
    INDEX (createdatetime),
    INDEX (score),
    FOREIGN KEY (channelid) REFERENCES channels (id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
);

-- --------------------------------------------------------------
-- Stores last 24h of interactions, used for frontpage algorithm
-- An event removes expired links (>24hr)
-- This is an optimisation that simplifies many queries and
-- avoids the need for datetimes in votes and tags
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
        'on_insert_bookmark',
        'on_receive_btc'
        )                      NOT NULL,
    PRIMARY KEY (id),
    INDEX (datetime),
    FOREIGN KEY (linkid) REFERENCES links (id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    FOREIGN KEY (channelid) REFERENCES channels (id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
);

DELIMITER //
CREATE TRIGGER on_insert_channel
    AFTER INSERT
    ON channels
    FOR EACH ROW
BEGIN
    INSERT INTO interactions(channelid,type) VALUES(NEW.id,'on_insert_channel');
END//
DELIMITER ;

DELIMITER //
CREATE TRIGGER on_update_channel
    AFTER UPDATE
    ON channels
    FOR EACH ROW
BEGIN
    IF (NEW.bio<>OLD.bio OR NEW.name<>OLD.name OR NEW.avatar<>OLD.avatar) THEN
        INSERT INTO interactions(channelid,type) VALUES(NEW.id,'on_update_channel');
    END IF;
END//
DELIMITER ;

DELIMITER //
CREATE TRIGGER on_insert_link
    AFTER INSERT
    ON links
    FOR EACH ROW
BEGIN
    UPDATE channels SET postscount = postscount + 1 WHERE id = NEW.channelid;
    INSERT INTO interactions(linkid,channelid,type) VALUES(NEW.id, NEW.channelid,'on_insert_link');
END//
DELIMITER ;

DELIMITER //
CREATE TRIGGER on_update_link
    AFTER UPDATE
    ON links
    FOR EACH ROW
BEGIN
    IF (NEW.description<>OLD.description
        OR NEW.image<>OLD.image
        OR NEW.title<>OLD.title
        OR NEW.image<>OLD.image
        OR NEW.url<>OLD.url) THEN
        INSERT INTO interactions(channelid,type) VALUES(NEW.id,'on_update_link');
    END IF;
    IF (NEW.viewscount<>OLD.viewscount) THEN
        UPDATE channels SET viewscount=viewscount+(NEW.viewscount-OLD.viewscount) WHERE id=NEW.channelid;
    END IF;
END//
DELIMITER ;

DELIMITER //
CREATE TRIGGER on_delete_link
    AFTER DELETE
    ON links
    FOR EACH ROW
BEGIN
    UPDATE channels SET postscount = postscount - 1 WHERE id = OLD.channelid;
END//
DELIMITER ;

-- --------------------------------------------------
-- reactions
-- --------------------------------------------------

CREATE TABLE reactions
(
    id             INT       NOT NULL AUTO_INCREMENT,
    createdatetime TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    linkid         INT       NOT NULL,
    channelid      INT       NOT NULL,
    comment        TEXT               DEFAULT NULL,
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
CREATE TRIGGER on_insert_reaction
    AFTER INSERT
    ON reactions
    FOR EACH ROW
BEGIN
    UPDATE links
    SET reactionscount = reactionscount + 1,
        uniquereactors = (SELECT COUNT(DISTINCT channelid) FROM reactions WHERE linkid = NEW.linkid)
    WHERE id = NEW.linkid;
    UPDATE channels SET reactionscount = reactionscount + 1 WHERE id = NEW.channelid;
    INSERT INTO interactions(linkid,channelid,type) VALUES(NEW.linkid, NEW.channelid,'on_insert_reaction');
END//
DELIMITER ;

DELIMITER //
CREATE TRIGGER on_delete_reaction
    AFTER DELETE
    ON reactions
    FOR EACH ROW
BEGIN
    UPDATE links
    SET reactionscount      = reactionscount - 1,
        uniquereactors = (SELECT COUNT(DISTINCT channelid) FROM reactions WHERE linkid = OLD.linkid)
    WHERE id = OLD.linkid;
    UPDATE channels SET reactionscount = reactionscount - 1 WHERE id = OLD.channelid;
END//
DELIMITER ;

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
CREATE TRIGGER on_insert_tag
    AFTER INSERT
    ON tags
    FOR EACH ROW
BEGIN
    UPDATE links SET tagscount = tagscount + 1 WHERE id = NEW.linkid;
    UPDATE channels SET tagscount = tagscount + 1 WHERE id = NEW.channelid;
    INSERT INTO interactions(linkid,channelid,type) VALUES(NEW.linkid, NEW.channelid,'on_insert_tag');
END//
DELIMITER ;

DELIMITER //
CREATE TRIGGER on_delete_tag
    AFTER DELETE
    ON tags
    FOR EACH ROW
BEGIN
    UPDATE links SET tagscount = tagscount - 1 WHERE id = OLD.linkid;
    UPDATE channels SET tagscount = tagscount - 1 WHERE id = OLD.channelid;
END//
DELIMITER ;

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
CREATE TRIGGER on_insert_vote
    AFTER INSERT
    ON votes
    FOR EACH ROW
BEGIN
    UPDATE links SET votescount = votescount + 1 WHERE id = NEW.linkid;
    UPDATE channels SET votescount = votescount + 1 WHERE id = NEW.channelid;
    INSERT INTO interactions(linkid,channelid,type) VALUES(NEW.linkid, NEW.channelid,'on_insert_vote');
END//
DELIMITER ;

DELIMITER //
CREATE TRIGGER on_delete_vote
    AFTER DELETE
    ON votes
    FOR EACH ROW
BEGIN
    UPDATE links SET votescount = votescount - 1 WHERE id = OLD.linkid;
    UPDATE channels SET votescount = votescount - 1 WHERE id = OLD.channelid;
END//
DELIMITER ;

-- --------------------------------------------------
--
-- --------------------------------------------------

CREATE TABLE bookmarks
(
    id              INT       NOT NULL AUTO_INCREMENT,
    createdatetime  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    linkid          INT       NOT NULL,
    channelid       INT       NOT NULL,
    PRIMARY KEY (id),
    INDEX (createdatetime),
    INDEX (linkid),
    INDEX (channelid),
    FOREIGN KEY (linkid) REFERENCES links (id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    FOREIGN KEY (channelid) REFERENCES channels (id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
);

DELIMITER //
CREATE TRIGGER on_insert_bookmark
    AFTER INSERT
    ON bookmarks
    FOR EACH ROW
BEGIN
    UPDATE links SET bookmarkscount = bookmarkscount + 1 WHERE id = NEW.linkid;
    UPDATE channels SET bookmarkscount = bookmarkscount + 1 WHERE id = NEW.channelid;
    INSERT INTO interactions(linkid,channelid,type) VALUES(NEW.linkid, NEW.channelid,'on_insert_bookmark');
END//
DELIMITER ;

DELIMITER //
CREATE TRIGGER on_delete_bookmark
    AFTER DELETE
    ON bookmarks
    FOR EACH ROW
BEGIN
    UPDATE links SET bookmarkscount = bookmarkscount - 1 WHERE id = OLD.linkid;
    UPDATE channels SET bookmarkscount = bookmarkscount - 1 WHERE id = OLD.channelid;
END//
DELIMITER ;

-- -----------------------------------------------------
-- Users (channels) that reacted last hour
-- This query must be cached by the server
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
       (SELECT COUNT(*) FROM tags)                                                                         AS numtags;

-- -----------------------------------------------------
-- Frontpage, this query should be cached by server
-- -----------------------------------------------------

DELIMITER //
CREATE EVENT apply_reputation_decay
    ON SCHEDULE EVERY 24 HOUR
    DO
    BEGIN
        -- apply half life decay to current channel reputations and add delta score,
        -- 0.9981 every day halfs the reputation in a year
        UPDATE channels SET reputation = reputation * 0.9981 + score - prevscore, prevscore = score;
    END//
DELIMITER ;

DELIMITER //
CREATE EVENT expire_interactions
    ON SCHEDULE EVERY 1 HOUR
    DO
    BEGIN
        -- remove interactions
        DELETE FROM interactions WHERE datetime < SUBDATE(CURRENT_TIMESTAMP, INTERVAL 24 HOUR);
    END//
DELIMITER ;

-- TODO to be implemented as bookmarks on the 'frontpage' channel
CREATE VIEW frontpage AS
SELECT * FROM links WHERE id IN (SELECT DISTINCT id FROM interactions)
                          -- order by score, give old posts some half life decay after 3 hours
ORDER BY (score / GREATEST(9, POWER(TIMESTAMPDIFF(HOUR, CURRENT_TIMESTAMP, createdatetime), 2))) DESC LIMIT 25;

-- --------------------------------------------------------
-- All unique tags, alphabetical
-- --------------------------------------------------------

CREATE VIEW tagindex AS
SELECT tag, COUNT(tag) as linkscount FROM tags GROUP BY tag ORDER BY tag;

-- --------------------------------------------------------
-- Most popular tags, this query should be cached by server
-- --------------------------------------------------------

CREATE VIEW trendingtopics AS
SELECT tags.* FROM tags
                       JOIN frontpage AS links ON tags.linkid = links.id
GROUP BY tags.tag
ORDER BY SUM(links.score / GREATEST(9, POWER(TIMESTAMPDIFF(HOUR, CURRENT_TIMESTAMP, links.createdatetime), 2))) DESC
LIMIT 25;

-- --------------------------------------------------------
-- Most popular tags, this query should be cached by server
-- --------------------------------------------------------

CREATE VIEW trendingchannels AS
SELECT channels.* FROM channels_public_view AS channels
                           JOIN frontpage AS links ON channels.id = links.channelid
GROUP BY channels.id
ORDER BY SUM(links.score / GREATEST(9, POWER(TIMESTAMPDIFF(HOUR, CURRENT_TIMESTAMP, links.createdatetime), 2))) DESC
LIMIT 25;

CREATE VIEW topchannels AS
SELECT channels.* FROM channels_public_view AS channels ORDER BY reputation DESC LIMIT 25;

CREATE VIEW newchannels AS
SELECT channels.* FROM channels_public_view AS channels ORDER BY id DESC LIMIT 25;

-- -----------------------------------------------------
--
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
-- RSS-feeds
-- -----------------------------------------------------

INSERT INTO channels(name) VALUES ("frontpage");
INSERT INTO channels(name, feedurl) VALUES ("Russia Today", "https://www.rt.com/rss");
INSERT INTO channels(name, feedurl) VALUES ("Off-guardian", "https://off-guardian.org/feed");
INSERT INTO channels(name, feedurl) VALUES ("Zero Hedge", "https://feeds.feedburner.com/zerohedge/feed");
INSERT INTO channels(name, feedurl) VALUES ("Infowars", "https://www.infowars.com/rss.xml");
INSERT INTO channels(name, feedurl) VALUES ("Xandernieuws", "https://www.xandernieuws.net/feed");
INSERT INTO channels(name, feedurl) VALUES ("CNET", "https://www.cnet.com/rss/all");
INSERT INTO channels(name, feedurl) VALUES ("Gizmodo", "https://gizmodo.com/rss");

