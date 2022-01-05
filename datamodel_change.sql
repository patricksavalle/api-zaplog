USE zaplog;

CREATE TABLE reactionvotes
(
    id             INT NOT NULL AUTO_INCREMENT,
    reactionid     INT NOT NULL,
    -- channel that votes
    channelid      INT NOT NULL,
    PRIMARY KEY (id),
    UNIQUE INDEX (reactionid, channelid),
    INDEX (channelid),
    FOREIGN KEY (reactionid) REFERENCES reactions (id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    FOREIGN KEY (channelid) REFERENCES channels (id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
);

DELIMITER //
CREATE TRIGGER on_insert_reactionvote AFTER INSERT ON reactionvotes FOR EACH ROW
BEGIN
    UPDATE channels SET score = score + 1 WHERE id = (SELECT channelid FROM reactions WHERE id=NEW.reactionid);
END//
DELIMITER ;

