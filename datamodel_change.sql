USE zaplog;
DROP PROCEDURE insert_reaction;
DELIMITER //
CREATE PROCEDURE insert_reaction(IN arg_channelid INT, IN arg_linkid INT, IN arg_markdown TEXT, IN arg_xtext TEXT, IN arg_description VARCHAR(256))
BEGIN
    INSERT INTO reactions (channelid,linkid,markdown,xtext,description)
        VALUES(arg_channelid,arg_linkid,arg_markdown,arg_xtext,arg_description);
    UPDATE reactions SET threadid=LAST_INSERT_ID() WHERE linkid=arg_linkid;
END //
DELIMITER ;
