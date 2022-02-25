USE zaplog;

UPDATE links
    SET
        markdown = CONCAT(
            IF (NOT url IS NULL AND LOCATE(CONCAT("](" , url , ")"),markdown)=0,CONCAT("![titleimage](" , url , ")\r\n"),"") ,
            "#", title, "\r\n",
            IFNULL(markdown,""),
            IF(url IS NULL,"", CONCAT("\r\n\r\n[", REGEXP_SUBSTR(url, '(\\w+\\.)?\\w+\\.\\w+(?=/|$)'), "](", url, ")"))),
        xtext=null;

ALTER TABLE links
DROP COLUMN mimetype,
    DROP COLUMN urlhash,
    DROP COLUMN url,
    DROP COLUMN location,
    DROP COLUMN longitude,
    DROP COLUMN latitude,
DROP INDEX urlhash;
