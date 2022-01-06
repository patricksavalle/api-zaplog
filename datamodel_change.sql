USE zaplog;
ALTER TABLE channels ADD COLUMN deeplusage INT DEFAULT 0 AFTER bitcoinaddress;
CREATE EVENT reset_deeplusage ON SCHEDULE EVERY 1 MONTH STARTS '2021-01-01 00:00:00' DO
UPDATE channels SET deeplusage=0;
