-- -----------------------------------------------------
-- RSS-feeds
-- -----------------------------------------------------
USE zaplog;

INSERT INTO channels (name, avatar, userid) VALUES
    ("cafe-weltschmerz", "https://api.multiavatar.com/cafeweltschmerz.svg", "redactie@cafeweltschmerz.nl"),
    ("blckbx", "https://api.multiavatar.com/blckbx.svg", "redactie@blckbx.tv"),
    ("gezond-verstand", "https://api.multiavatar.com/gezondverstand.svg", "redactie@gezondverstand.eu");

INSERT INTO channels(name, feedurl, avatar, userid) VALUES
    ("viruswaarheid", "https://viruswaarheid.nl/feed/", "https://api.multiavatar.com/viruswaarheid.svg", "info@viruswaarheid.nl" ),
    ("ninefornews", "https://www.ninefornews.nl/feed/", "https://api.multiavatar.com/ninefornews.svg", "info@ninefornews.nl"),
    ("lnnmedia", "https://www.lnnmedia.nl/feed/", "https://api.multiavatar.com/lnnmedia.svg", "info@lnnmedia.nl"),
    ("herstel-de-republiek", "https://herstelderepubliek.wordpress.com/feed/", "https://api.multiavatar.com/herstelderepubliek.svg", "redactie@herstelderepubliek.wordpress.com"),
    ("het-andere-nieuws", "https://hetanderenieuws.nl/feed/", "https://api.multiavatar.com/devrijeomroep.svg", "redactie@hetanderenieuws.nl"),
    ("de-vrije-omroep", "https://odysee.com/$/rss/@devrijeomroep:6", "https://api.multiavatar.com/devrijeomroep.svg", "redactie@devrijeomroep.nl"),
    ("frontnieuws", "https://www.frontnieuws.com/feed/", "https://api.multiavatar.com/frontnieuws.svg", "redactie@frontnieuws.nl"),
    ("hnmda", "https://www.hetnieuwsmaardananders.nl/feed/", "https://api.multiavatar.com/hetnieuwsmaardananders.svg", "redactie@hetnieuwsmaardananders.nl");





