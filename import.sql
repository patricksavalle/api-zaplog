-- import old zaplog to new

-- -----------------------------------------------------------------
-- Importing needs PHP processing, these are the intermediate tables
-- -----------------------------------------------------------------

DROP TABLE IF EXISTS zaplog.imported_users;
DROP TABLE IF EXISTS zaplog.imported_posts;
DROP TABLE IF EXISTS zaplog.imported_tags;
DROP TABLE IF EXISTS zaplog.imported_votes;
DROP TABLE IF EXISTS zaplog.imported_comments;

CREATE TABLE zaplog.imported_users
SELECT member_id, MD5(email) AS userid, FROM_UNIXTIME(join_date) AS join_date, FROM_UNIXTIME(last_visit) AS last_visit_date
FROM zaplog_site.exp_members WHERE total_entries>0 OR total_comments>0;

ALTER TABLE zaplog.imported_users ADD INDEX (userid);

CREATE TABLE zaplog.imported_tags
SELECT DISTINCT tagentries.entry_id, MD5(members.email) as userid, CONVERT(CAST(CONVERT(tagnames.tag_name USING latin1) AS BINARY) USING UTF8) as tag_name
FROM zaplog_site.exp_tag_tags AS tagnames
JOIN zaplog_site.exp_tag_entries AS tagentries ON tagentries.tag_id=tagnames.tag_id
JOIN zaplog_site.exp_members as members ON tagentries.author_id=members.member_id;

ALTER TABLE zaplog.imported_tags ADD INDEX (userid), ADD INDEX(entry_id);

CREATE TABLE zaplog.imported_votes
SELECT MD5(email) AS userid, entry_id FROM zaplog_site.exp_favorites AS favorites
JOIN zaplog_site.exp_members as members ON favorites.member_id=members.member_id;

ALTER TABLE zaplog.imported_votes ADD INDEX (userid), ADD INDEX(entry_id);

CREATE TABLE zaplog.imported_posts
SELECT DISTINCT
    MD5(members.email) as userid,
    titles.entry_id,
    CONVERT(CAST(CONVERT(title USING latin1) AS BINARY) USING UTF8) AS title,
    CONVERT(CAST(CONVERT(field_id_1 USING latin1) AS BINARY) USING UTF8) AS description,
    field_id_2 AS link,
    field_id_4 AS copyright,
    from_unixtime(titles.entry_date) AS createdatetime,
    view_count_one AS viewscount
FROM zaplog_site.exp_weblog_data AS data
JOIN zaplog_site.exp_weblog_titles AS titles ON titles.entry_id=data.entry_id
JOIN zaplog_site.exp_members as members ON titles.author_id=members.member_id
WHERE view_count_one>100 AND status="open" AND titles.weblog_id=1 AND LENGTH(field_id_1)>100;

ALTER TABLE zaplog.imported_posts ADD INDEX (userid), ADD INDEX(entry_id);

CREATE TABLE zaplog.imported_comments
SELECT comment_id, entry_id, MD5(members.email) AS userid, FROM_UNIXTIME(comment_date) as comment_date, CONVERT(CAST(CONVERT(comment USING latin1) AS BINARY) USING UTF8) AS comment
FROM zaplog_site.exp_comments AS comments
JOIN zaplog_site.exp_members as members ON comments.author_id=members.member_id;

ALTER TABLE zaplog.imported_comments ADD INDEX (userid), ADD INDEX(entry_id), ADD INDEX(comment_id);

ALTER TABLE zaplog.links
ADD COLUMN entryid INT NULL AFTER id,
ADD INDEX (entryid);

