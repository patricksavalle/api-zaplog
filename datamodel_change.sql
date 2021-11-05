use zaplog;
ALTER TABLE reactions ADD COLUMN markdown TEXT DEFAULT NULL AFTER published;
