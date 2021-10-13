-- import old zaplog to new

-- -----------------------------------------------------------------
-- Importing needs PHP processing, these are the intermediate tables
-- -----------------------------------------------------------------

CREATE TABLE zaplog.imported_users
    SELECT member_id, email, username, FROM_UNIXTIME(join_date) AS join_date, FROM_UNIXTIME(last_visit) AS last_visit_date FROM zaplog_site.exp_members WHERE total_entries>0 OR total_comments>0;

CREATE TABLE zaplog.imported_posts
    SELECT
        titles.author_id,
        title,
        field_id_1 AS description,
        field_id_2 AS link,
        field_id_4 AS copyright,
        from_unixtime(entry_date) AS createdatetime,
        view_count_one AS viewscount
    FROM zaplog_site.exp_weblog_data AS data
    JOIN zaplog_site.exp_weblog_titles AS titles ON titles.entry_id=data.entry_id
    WHERE view_count_one>100 AND status="open" AND titles.weblog_id=1;

CREATE TABLE zaplog.imported_tags
    SELECT tagentries.entry_id, tagentries.author_id, tagnames.tag_name
    FROM zaplog_site.exp_tag_tags AS tagnames
    JOIN zaplog_site.exp_tag_entries AS tagentries ON tagentries.tag_id=tagnames.tag_id;

CREATE TABLE zaplog.imported_votes
    SELECT member_id, entry_id FROM zaplog_site.exp_favorites;

CREATE TABLE zaplog.imported_comments
    SELECT entry_id, author_id, email, FROM_UNIXTIME(comment_date) as comment_date, comment FROM zaplog_site.exp_comments;