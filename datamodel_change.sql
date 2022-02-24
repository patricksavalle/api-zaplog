USE zaplog;
ALTER TABLE links
    DROP COLUMN mimetype,
    DROP COLUMN urlhash,
    DROP COLUMN url,
    DROP COLUMN location,
    DROP COLUMN longitude,
    DROP COLUMN latitude,
    DROP INDEX urlhash;
UPDATE links SET markdown = CONCAT(title, "\r\n", markdown, "\r\n", IF(url IS NULL,"", CONCAT("[", REGEXP_SUBSTR(url, '\\w+\\.\\w+(?=/|$)'), "](", url, ")")));