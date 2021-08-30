/**
 * zaplog V2
 * @author:     patrick@patricksavalle.com
 */

/*
    I tried to make the datamodel as complete and self-contained as possible
    WITHOUT actually using algoritm-like code (loops).

    Actual algoritms will be in the REST/PHP layers. Database integrity and
    data-retention / cleanup typically in the DB.

    This datamodel should be understandable WITHOUT reading the PHP/code layer
    (and vice versa)
 */

DROP SCHEMA IF EXISTS zaplog;
CREATE SCHEMA zaplog
    DEFAULT CHARACTER SET utf8
    DEFAULT COLLATE utf8_general_ci;
USE zaplog;
-- we need the event scheduler!!!
SET GLOBAL event_scheduler = ON;

-- -----------------------------------------------------
-- 2-factor tokens, authentication is based on 2F/email
-- -----------------------------------------------------

CREATE TABLE tokens
(
    hash               CHAR(32)  NOT NULL,
    json               JSON      NOT NULL,
    expirationdatetime TIMESTAMP NOT NULL,
    PRIMARY KEY (hash),
    INDEX ( expirationdatetime )
) ENGINE = MYISAM;

-- ----------------------------------------------------------------------------
-- Delete tokens that are past the expirationdate
-- ----------------------------------------------------------------------------

DELIMITER //
CREATE EVENT expire_tokens
    ON SCHEDULE EVERY 20 MINUTE
    DO DELETE FROM tokens WHERE expirationdatetime < CURRENT_TIMESTAMP//
DELIMITER ;

-- -----------------------------------------------------
-- Activity stream data, remembers activity that is needed
-- to calculate frontpage-ranking
-- No locking, referential integrity, write-only --> MYISAM
--
-- This table should not be updated from outside this datamodel
-- -----------------------------------------------------

CREATE TABLE activities
(
    id        INT         NOT NULL AUTO_INCREMENT,
    channelid INT                DEFAULT NULL,
    linkid    INT                DEFAULT NULL,
    activity  ENUM (
        'post',
        'autopost',
        'vote',
        'bookmark',
        'tag',
        'autotag',
        'share',
        'untag',
        'unbookmark',
        'login',
        'feedrefresh',
        'channelnamechange',
        'newfrontpage',
        'reputationsupdate'
    ),
    datetime  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX (linkid),
    INDEX (datetime)
);

-- -----------------------------------------------------
-- Channels are collections of links, a single email
-- user can create many channels
-- -----------------------------------------------------

CREATE TABLE channels
(
    id             INT         NOT NULL AUTO_INCREMENT,
    email          VARCHAR(55)          DEFAULT NULL,
    name           VARCHAR(55)          DEFAULT NULL,
    -- IF NOT FEEDURL IS NULL -> automatic RSS content
    feedurl        VARCHAR(256)         DEFAULT NULL,
    createdatetime TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    refeeddatetime TIMESTAMP   NOT NULL,
    avatar         VARCHAR(55)          DEFAULT NULL,
    background     VARCHAR(55)          DEFAULT NULL,
    bio            VARCHAR(255)         DEFAULT NULL,
    bookmarkscount INT                  DEFAULT 0,
    postscount     INT                  DEFAULT 0,
    viewscount     INT                  DEFAULT 0,
    votescount     INT                  DEFAULT 0,
    score          INT GENERATED ALWAYS AS (
        postscount * 2 +
        votescount * 5 +
        bookmarkscount * 10 +
        FLOOR(LOG(viewscount+1))
    ),
    reputation     INT                  DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE INDEX (email),
    UNIQUE INDEX (name),
    UNIQUE INDEX (feedurl)
);

DELIMITER //
CREATE TRIGGER on_refeed_channel
    AFTER UPDATE
    ON channels
    FOR EACH ROW
BEGIN
    IF (OLD.refeeddatetime<>NEW.refeeddatetime) THEN
        INSERT INTO activities(channelid, linkid, activity) VALUES (NEW.channelid, NEW.id, 'feedrefresh');
    END IF;
    IF (OLD.name<>NEW.name) THEN
        INSERT INTO activities(channelid, linkid, activity) VALUES (NEW.channelid, NEW.id, 'channelnamechange');
    END IF;
END//
DELIMITER ;

-- For public queries. Hide privacy data.

CREATE VIEW channels_public_view AS
    SELECT id, name, createdatetime, bio, postscount, viewscount, votescount, score, reputation FROM channels;

-- -----------------------------------------------------
-- Authenticated tokens / sessions
-- -----------------------------------------------------

CREATE TABLE sessions
(
    token      CHAR(32)    NOT NULL,
    lastupdate TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
    channelid  INT         NOT NULL,
    UNIQUE INDEX (token),
    INDEX (lastupdate)
) ENGINE=MYISAM;

DELIMITER //
CREATE EVENT expire_sessions
    ON SCHEDULE EVERY 1 HOUR
    DO DELETE FROM sessions WHERE lastupdate < SUBDATE(CURRENT_TIMESTAMP, INTERVAL 1 HOUR)//
DELIMITER ;

-- -----------------------------------------------------
-- The links that are being shared, rated, etc.
-- -----------------------------------------------------

CREATE TABLE links
(
    id             INT           NOT NULL AUTO_INCREMENT,
    createdatetime TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    crawldatetime  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    channelid      INT           NOT NULL,
    -- TODO can be optimized to BINARY(16)
    urlhash        CHAR(32) GENERATED ALWAYS AS (MD5(url)),
    url            VARCHAR(1024) NOT NULL,
    title          VARCHAR(256)  NOT NULL,
    description    TEXT                   DEFAULT NULL,
    image          VARCHAR(256)           DEFAULT NULL,
    -- because this system is very read intensive we will keep totals in this table
    -- instead of counting/joining the respective tables each time
    bookmarkscount INT                    DEFAULT 0,
    viewscount     INT                    DEFAULT 0,
    votescount     INT                    DEFAULT 0,
    tagscount      INT                    DEFAULT 0,
    score          INT GENERATED ALWAYS AS (
        votescount * 5 +
        bookmarkscount * 10 +
        FLOOR(LOG(viewscount+1))
    ),

    PRIMARY KEY (id),
    UNIQUE INDEX (channelid),
    INDEX (urlhash),
    INDEX (score),
    FOREIGN KEY (channelid) REFERENCES channels (id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
);

DELIMITER //
CREATE TRIGGER on_insert_link
    AFTER INSERT
    ON links
    FOR EACH ROW
BEGIN
    INSERT INTO activities(channelid, linkid, activity) VALUES (NEW.channelid, NEW.id, 'post');
END//
DELIMITER ;

-- --------------------------------------------------
-- Link tags
-- --------------------------------------------------

CREATE TABLE tags
(
    id        INT         NOT NULL AUTO_INCREMENT,
    linkid    INT         NOT NULL,
    datetime  TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- channel (=user) that added the tags
    channelid INT                  DEFAULT NULL,
    tag       VARCHAR(50) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE INDEX (linkid, channelid, tag),
    INDEX (tag),
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
    INSERT INTO activities(channelid, linkid, activity) VALUES (NEW.channelid, NEW.linkid, if(NEW.channelid=1,'autotag','tag'));
END//
DELIMITER ;

DELIMITER //
CREATE TRIGGER on_delete_tag
    AFTER DELETE
    ON tags
    FOR EACH ROW
BEGIN
    UPDATE links SET tagscount = tagscount - 1 WHERE id = OLD.linkid;
    INSERT INTO activities(channelid, linkid, activity) VALUES (OLD.channelid, OLD.linkid, 'untag');
END//
DELIMITER ;

-- --------------------------------------------------
--
-- --------------------------------------------------

CREATE TABLE votes
(
    id        INT       NOT NULL AUTO_INCREMENT,
    linkid    INT       NOT NULL,
    channelid INT       NOT NULL,
    datetime  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX (datetime),
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
    INSERT INTO activities(channelid, linkid, activity) VALUES (NEW.channelid, NEW.linkid, 'vote');
END//
DELIMITER ;

-- --------------------------------------------------
--
-- --------------------------------------------------

CREATE TABLE bookmarks
(
    id        INT       NOT NULL AUTO_INCREMENT,
    datetime  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    linkid    INT       NOT NULL,
    channelid INT       NOT NULL,
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

DELIMITER //
CREATE TRIGGER on_insert_bookmark
    AFTER INSERT
    ON bookmarks
    FOR EACH ROW
BEGIN
    UPDATE links SET bookmarkscount = bookmarkscount + 1 WHERE id = NEW.linkid;
    INSERT INTO activities(channelid, linkid, activity) VALUES (NEW.channelid, NEW.linkid, 'bookmark');
END//
DELIMITER ;

DELIMITER //
CREATE TRIGGER on_delete_bookmark
    AFTER DELETE
    ON bookmarks
    FOR EACH ROW
BEGIN
    UPDATE links SET bookmarkscount = bookmarkscount - 1 WHERE id = OLD.linkid;
    INSERT INTO activities(channelid, linkid, activity) VALUES (OLD.channelid, OLD.linkid, 'unbookmark');
END//
DELIMITER ;

-- -----------------------------------------------------
-- enriched activity stream
-- -----------------------------------------------------

CREATE VIEW activitystream AS
    SELECT
        activities.*,
        channels.name as channelname,
        links.title as linktitle,
        links.url as linkurl,
        links.image as linkimage
    FROM activities
    LEFT JOIN channels ON activities.channelid=channels.id
    LEFT JOIN links ON activities.linkid=links.id AND activity IN ('post', 'tag', 'vote', 'bookmark');

-- -----------------------------------------------------
-- Channels that are currently logged in
-- -----------------------------------------------------

CREATE VIEW whosonline AS
    SELECT name, avatar, score, lastupdate FROM sessions
    LEFT JOIN channels ON sessions.channelid=channels.id;

-- -----------------------------------------------------
-- 24h Statistics
-- -----------------------------------------------------

CREATE VIEW statistics AS
SELECT
    (SELECT COUNT(*) FROM links WHERE createdatetime > SUBDATE(CURRENT_TIMESTAMP, INTERVAL 1 DAY)) AS numposts24h,
    (SELECT COUNT(*) FROM links WHERE createdatetime > SUBDATE(CURRENT_TIMESTAMP, INTERVAL 1 MONTH)) AS numposts1m,
    (SELECT COUNT(*) FROM channels) AS numchannels,
    (SELECT COUNT(*) FROM channels WHERE createdatetime > SUBDATE(CURRENT_TIMESTAMP, INTERVAL 1 MONTH)) AS newchannels1m,
    (SELECT COUNT(*) FROM tags) AS numtags,
    (SELECT COUNT(*) FROM sessions) AS numonline;

-- -----------------------------------------------------
-- Most popular links
-- -----------------------------------------------------

-- TODO temporary, for development
CREATE VIEW frontpage AS SELECT * FROM links ORDER BY score DESC LIMIT 20;

-- -----------------------------------------------------
-- Most popular tags
-- -----------------------------------------------------

CREATE VIEW trendingtopics AS
    SELECT tag, SUM(links.score) AS score FROM tags
    JOIN links ON tags.linkid=links.id
    GROUP BY tags.tag
    ORDER BY SUM(links.score) DESC
    LIMIT 25;

-- -----------------------------------------------------
-- Frontpage selection
-- -----------------------------------------------------

CREATE TABLE frontpagelinks
(
    linkid         INT NOT NULL,
    bookmarkscount INT DEFAULT 0,
    viewscount     INT DEFAULT 0,
    votescount     INT DEFAULT 0,
    tagscount      INT DEFAULT 0,
    score          INT NOT NULL DEFAULT 0,
    PRIMARY KEY (linkid),
    INDEX(score)
) ENGINE=MEMORY;

DELIMITER //
CREATE EVENT select_frontpage
    ON SCHEDULE EVERY 3 HOUR
    DO
    BEGIN
        CREATE TABLE newfrontpagelinks LIKE frontpagelinks;

        -- prepare new frontpage selection -> all existing links that had activity
        INSERT INTO frontpagelinks(linkid)
        SELECT id FROM links LEFT JOIN activities ON activities.linkid = links.id;

        -- calculate scores for frontpage
        UPDATE frontpagelinks LEFT JOIN activities ON activities.linkid = frontpagelinks.linkid
        SET score = score +
                    CASE
                        WHEN activity = 'post' THEN 0
                        WHEN activity = 'tag' THEN 0
                        WHEN activity = 'vote' THEN 10
                        WHEN activity = 'bookmark' THEN 15
                        END;

        -- clean the old activity (older than 1 + 3 hours)
        DELETE FROM activities WHERE activity.datetime < SUBDATE(CURRENT_TIMESTAMP, INTERVAL 1 HOUR);

        START TRANSACTION;
        DROP TABLE frontpagelinks;
        RENAME TABLE newfrontpagelinks TO frontpagelinks;
        COMMIT;

    END//
DELIMITER ;

-- -----------------------------------------------------
-- Channel score calculation
-- -----------------------------------------------------

DELIMITER //
CREATE EVENT calculate_reputation
    ON SCHEDULE EVERY 24 HOUR
    DO
    BEGIN
        -- add some half life to existing reputation, nothing lasts forever
        UPDATE channel SET reputation = CAST(reputation * 0.9 AS INTEGER);

        -- add the most recent scores
    END//
DELIMITER ;

-- -----------------------------------------------------
-- RSS-feeds
-- -----------------------------------------------------

INSERT INTO channels(name) VALUES ("system");
INSERT INTO channels(name,feedurl) VALUES ("Russia Today", "https://www.rt.com/rss");
INSERT INTO channels(name,feedurl) VALUES ("Off-guardian", "https://off-guardian.org/feed");
INSERT INTO channels(name,feedurl) VALUES ("Zero Hedge", "https://feeds.feedburner.com/zerohedge/feed");
INSERT INTO channels(name,feedurl) VALUES ("Infowars", "https://www.infowars.com/rss.xml");
INSERT INTO channels(name,feedurl) VALUES ("Xandernieuws", "https://www.xandernieuws.net/feed");
INSERT INTO channels(name,feedurl) VALUES ("CNET", "https://www.cnet.com/rss/all");
INSERT INTO channels(name,feedurl) VALUES ("Gizmodo", "https://gizmodo.com/rss");

# DELIMITER //
# CREATE EVENT expire_autoposted_links
#     ON SCHEDULE EVERY 1 DAY
#     DO
#     DELETE links.* FROM links
#     JOIN channels ON links.channelid=channel.id
#     WHERE NOT channel.feedurl IS NULL
#       AND links.createdatetime < SUBDATE(CURRENT_TIMESTAMP, INTERVAL 1 WEEK)
#       AND links.votescount=0;
# DELIMITER ;
#
# DELIMITER //
# CREATE TRIGGER reject_old_links
#     BEFORE INSERT
#     ON links
#     FOR EACH ROW
# BEGIN
#     IF (NEW.createdatetime < SUBDATE(CURRENT_TIMESTAMP, INTERVAL 1 WEEK)) THEN
#     BEGIN
#
#     END IF;
# END//
# DELIMITER ;
#
