<?php /** @noinspection PhpUndefinedMethodInspection */

declare(strict_types=1);

namespace Zaplog {

    require_once BASE_PATH . '/Exception/ResourceNotFoundException.php';

    use Atrox\Haikunator;
    use ContentSyndication\Text;
    use Multiavatar;
    use SlimRestApi\Infra\Db;

    class ZaplogImport
    {
        protected function SR(string $text): string
        {
            str_replace("http://zaplog.nl/", "https://web.archive.org/web/*/http://zaplog.nl/", $text);
            str_replace("http://zapruder.nl/", "https://web.archive.org/web/*/http://zapruder.nl/", $text);
            return $text;
        }

        public function __invoke()
        {
            set_time_limit(0);

            // import users
            $counter = 0;
            foreach (Db::fetchAll("SELECT userid, join_date, last_visit_date FROM imported_users ORDER BY member_id ASC") as $user) {
                error_log("users: " . $counter++);
                $channelname = Haikunator::haikunate();
                $avatar = "data:image/svg+xml;base64," . base64_encode((new Multiavatar)($channelname, null, null));
                Db::execute("INSERT IGNORE INTO channels(name,userid,avatar,createdatetime, updatedatetime)
                VALUES(:name,:userid,:avatar,:createdatetime,:updatedatetime)", [
                    ":name" => $channelname,
                    ":userid" => $user->userid,
                    ":avatar" => $avatar,
                    ":createdatetime" => $user->join_date,
                    ":updatedatetime" => $user->last_visit_date,
                ]);
            }

            Db::execute("ALTER TABLE links ADD COLUMN entryid INT NULL AFTER id, ADD INDEX (entryid);");

            // import posts
            $offset = 0;
            do {
                $batchsize = 0;
                error_log("post: " . $offset);
                foreach (Db::fetchAll("SELECT channels.id AS channelid, entry_id, title, link, description, posts.createdatetime, viewscount
                    FROM imported_posts AS posts
                    JOIN channels ON posts.userid=channels.userid
                    ORDER BY entry_id ASC
                    LIMIT :offset, 1000", [":offset" => $offset]) as $post) {
                    $batchsize++;
                    $post->description = $this->SR($post->description);
                    Db::execute("INSERT INTO links(entryid,channelid,title,markdown,description,createdatetime,viewscount,url, image)
                        VALUES(:entryid,:channelid,:title,:markdown,:description,:createdatetime,:viewscount,:url, :image)", [
                        ":entryid" => $post->entry_id,
                        ":channelid" => $post->channelid,
                        ":title" => (string)(new Text($post->title)),
                        ":markdown" => (string)(new Text($post->description))->nl2br()->BBtoHTML()->purify()->parseUp(),
                        ":description" => (string)(new Text($post->description))->blurbify(),
                        ":createdatetime" => $post->createdatetime,
                        ":viewscount" => $post->viewscount * 3,
                        ":url" => $post->link,
                        ":image" => "https://cdn.pixabay.com/photo/2018/06/24/08/01/dark-background-3494082_1280.jpg",
                    ]);
                }
                $offset += 1000;
            } while ($batchsize > 0);

            // import tags
            $offset = 0;
            do {
                $batchsize = 0;
                error_log("tag: " . $offset);
                foreach (Db::fetchAll("SELECT DISTINCT channels.id AS channelid, tag_name, links.id AS linkid FROM imported_tags AS tags
                    JOIN channels ON channels.userid=tags.userid
                    JOIN links ON links.entryid=tags.entry_id
                    LIMIT :offset, 1000", [":offset" => $offset]) as $tag) {
                    $batchsize++;
                    Db::execute("INSERT IGNORE INTO tags(linkid,channelid,tag) VALUES(:linkid,:channelid,:tag)", [
                        ":channelid" => $tag->channelid,
                        ":linkid" => $tag->linkid,
                        ":tag" => (new Text($tag->tag_name))->convertToAscii()->hyphenize(),
                    ]);
                }
                $offset += 1000;
            } while ($batchsize > 0);

            // import votes
            $offset = 0;
            do {
                $batchsize = 0;
                error_log("vote: " . $offset);
                foreach (Db::fetchAll("SELECT DISTINCT channels.id AS channelid, links.id AS linkid FROM imported_votes AS votes
                    JOIN channels ON channels.userid=votes.userid
                    JOIN links ON links.entryid=votes.entry_id
                    LIMIT :offset, 1000", [":offset" => $offset]) as $vote) {
                    $batchsize++;
                    Db::execute("INSERT IGNORE INTO votes(linkid,channelid) VALUES(:linkid,:channelid)", [
                        ":channelid" => $vote->channelid,
                        ":linkid" => $vote->linkid,
                    ]);
                }
                $offset += 1000;
            } while ($batchsize > 0);

            // import comments
            $offset = 0;
            do {
                $batchsize = 0;
                error_log("comments: " . $offset);
                foreach (Db::fetchAll("SELECT channels.id AS channelid, comment_date, comment, links.id AS linkid FROM imported_comments AS comments
                    JOIN channels ON channels.userid=comments.userid
                    JOIN links ON links.entryid=comments.entry_id
                    ORDER BY comment_id ASC
                    LIMIT :offset, 1000", [":offset" => $offset]) as $comment) {
                    $batchsize++;
                    $xtext = (string)(new Text($comment->comment))->nl2br()->BBtoHTML()->purify();
                    Db::execute("INSERT INTO reactions(linkid,channelid,xtext,createdatetime,description) VALUES(:linkid,:channelid,:xtext,:datetime,:description)", [
                        ":channelid" => $comment->channelid,
                        ":linkid" => $comment->linkid,
                        ":xtext" => $xtext,
                        ":datetime" => $comment->comment_date,
                        ":description" => (new Text($xtext))->blurbify(),
                    ]);
                }
                $offset += 1000;
            } while ($batchsize > 0);

            // update the threadid's
            $counter = 0;
            foreach (Db::fetchAll("SELECT id, linkid FROM reactions") as $reaction)
            {
                error_log( "" . $counter++ );
                Db::execute("UPDATE reactions SET threadid=(SELECT MAX(id) FROM reactions WHERE linkid=:linkid) WHERE id=:id",
                    [":linkid" => $reaction->linkid, ":id" => $reaction->id]);
            }

            Db::execute("UPDATE IGNORE tags SET tag='amerika' WHERE tag='vs'");
            Db::execute("DELETE FROM tags WHERE tag='vs'");
            Db::execute("DELETE FROM tags WHERE tag IN ('informatie')");

            Db::execute("ALTER TABLE links DROP COLUMN entryid;");

            Db::execute("UPDATE links SET published=FALSE WHERE tagscount=0 OR (votescount=0 AND reactionscount=0)");

            Db::execute("DELETE FROM links WHERE (tagscount=0 OR votescount=0) AND reactionscount=0");

            Db::execute("CALL calculate_channel_reputations()");
            Db::execute("CALL calculate_frontpage()");

            Db::execute("OPTIMIZE TABLE channels");
            Db::execute("OPTIMIZE TABLE links");
            Db::execute("OPTIMIZE TABLE tags");
            Db::execute("OPTIMIZE TABLE votes");
            Db::execute("OPTIMIZE TABLE reactions");

        }
    }
}
