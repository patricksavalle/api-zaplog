<?php /** @noinspection PhpUndefinedMethodInspection */

declare(strict_types=1);

namespace Zaplog {

    require_once BASE_PATH . '/Exception/ResourceNotFoundException.php';

    use Atrox\Haikunator;
    use ContentSyndication\Text;
    use SlimRestApi\Infra\Db;

    class ZaplogImport
    {
        public function __invoke()
        {

//      [image][/image]
//      [qoute][/quote]

            set_time_limit(0);

            // import users
            $counter = 0;
            foreach (Db::fetchAll("SELECT userid, join_date, last_visit_date FROM imported_users ORDER BY member_id ASC") as $user) {
                error_log("users: " . $counter++);
                $channelname = Haikunator::haikunate();
                Db::execute("INSERT IGNORE INTO channels(name,userid,avatar,createdatetime, updatedatetime)
                VALUES(:name,:userid,:avatar,:createdatetime,:updatedatetime)", [
                    ":name" => $channelname,
                    ":userid" => $user->userid,
                    ":avatar" => "https://api.multiavatar.com/$channelname.svg",
                    ":createdatetime" => $user->join_date,
                    ":updatedatetime" => $user->last_visit_date,
                ]);
            }

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
                    Db::execute("INSERT INTO links(entryid,channelid,title,markdown,description,createdatetime,viewscount,url, image)
                        VALUES(:entryid,:channelid,:title,:markdown,:description,:createdatetime,:viewscount,:url, :image)", [
                        ":entryid" => $post->entry_id,
                        ":channelid" => $post->channelid,
                        ":title" => (string)(new Text($post->title)),
                        ":markdown" => (string)(new Text($post->description))->purify()->parseUp(),
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
                        ":tag" => (new Text($tag->tag_name))->convertToAscii()->hyphenizeForPath(),
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
                    Db::execute("INSERT INTO reactions(linkid,channelid,xtext,createdatetime) VALUES(:linkid,:channelid,:xtext,:datetime)", [
                        ":channelid" => $comment->channelid,
                        ":linkid" => $comment->linkid,
                        ":xtext" => (new Text($comment->comment))->purify(),
                        ":datetime" => $comment->comment_date,
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

        }
    }
}
