USE zaplog;
UPDATE links SET markdown = CONCAT("#", title, "\r\n", IFNULL(markdown,""), IF(url IS NULL,"", CONCAT("\r\n\r\n[", REGEXP_SUBSTR(url, '\\w+\\.\\w+(?=/|$)'), "](", url, ")")));
ALTER TABLE links
DROP COLUMN mimetype,
    DROP COLUMN urlhash,
    DROP COLUMN url,
    DROP COLUMN location,
    DROP COLUMN longitude,
    DROP COLUMN latitude,
DROP INDEX urlhash;
