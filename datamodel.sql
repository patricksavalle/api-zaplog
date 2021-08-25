/**
 * zaplog V2
 * @author:     patrick@patricksavalle.com
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
    ON SCHEDULE EVERY 1 HOUR
    DO DELETE FROM tokens WHERE expirationdatetime < CURRENT_TIMESTAMP//
DELIMITER ;

-- -----------------------------------------------------
-- Activity stream data, remembers activity that is needed
-- to calculate frontpage-ranking
-- No locking, referential integrity, write-only --> MYISAM
-- -----------------------------------------------------

CREATE TABLE activities
(
    id        INT         NOT NULL AUTO_INCREMENT,
    channelid INT                DEFAULT NULL,
    linkid    INT                DEFAULT NULL,
    activity  ENUM ('post', 'vote', 'bookmark', 'tag', 'share', 'untag', 'unbookmark'),
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
    email          VARCHAR(55) NOT NULL,
    name           VARCHAR(55)          DEFAULT NULL,
    createdatetime TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    avatar         VARCHAR(55)          DEFAULT NULL,
    score          INT                  DEFAULT 0,
    PRIMARY KEY (id),
    INDEX (createdatetime),
    UNIQUE INDEX (name)
);

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
    title          VARCHAR(128)  NOT NULL,
    description    TEXT                   DEFAULT NULL,
    image          VARCHAR(256)           DEFAULT NULL,
    domain         VARCHAR(50)            DEFAULT NULL,
    site           VARCHAR(50)            DEFAULT NULL,
    -- because this system is very read intensive we will keep totals in this table
    -- instead of counting/joining the respective tables
    bookmarkscount INT                    DEFAULT 0,
    viewscount     INT                    DEFAULT 0,
    votescount     INT                    DEFAULT 0,
    tagscount      INT                    DEFAULT 0,
    score          INT GENERATED ALWAYS AS (votescount * 5 + bookmarkscount * 10 + FLOOR(SQRT(tagscount)) + FLOOR(LOG(viewscount+1))),

    PRIMARY KEY (id),
    INDEX (channelid),
    UNIQUE INDEX (urlhash),
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

-- --------------------------------------------------
-- Link tags
-- --------------------------------------------------

CREATE TABLE tags
(
    id        INT         NOT NULL AUTO_INCREMENT,
    linkid    INT         NOT NULL,
    datetime  TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
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
    INSERT INTO activities(channelid, linkid, activity) VALUES (NEW.channelid, NEW.linkid, 'tag');
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
    SELECT tag, SUM(frontpage.score) AS score FROM tags
    JOIN frontpage ON tags.linkid=frontpage.id
    GROUP BY tags.tag
    ORDER BY SUM(frontpage.score) DESC
    LIMIT 25;