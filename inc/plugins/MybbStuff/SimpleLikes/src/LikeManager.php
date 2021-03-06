<?php
declare(use_strict=1);

namespace MybbStuff\SimpleLikes;

use DateTime;
use DB_Base;
use MyBB;
use MyLanguage;

class LikeManager
{
    const RESULT_LIKED = 1;

    const RESULT_UNLIKED = 0;

    /**
     * @var DB_Base $db
     */
    private $db;
    /**
     * @var MyBB $mybb
     */
    private $mybb;
    /**
     * @var MyLanguage $lang
     */
    private $lang;

    /**
     * Create a new Likes object.
     *
     * @param MyBB $mybb The MyBB object.
     * @param DB_Base $db A Database instance object of type DB_MySQL, DB_MySQLi, DB_PgSQL or DB_SQLite.
     * @param MyLanguage $lang The language class from MyBB used to manage language files and strings.
     */
    public function __construct(MyBB $mybb, DB_Base $db, MyLanguage $lang)
    {
        $this->mybb = $mybb;
        $this->db = $db;
        $this->lang = $lang;
    }

    /**
     * Add or remove a like for a specific post. Likes act as if toggled.
     *
     * @param int $postId The post id to (un)like.
     *
     * @return int The insert id or 0 if the action was a like deletion.
     *
     * @throws \Exception Thrown if the current date/time cannot be determined.
     */
    public function likePost(int $postId): int
    {
        $postId = (int)$postId;
        $userId = (int)$this->mybb->user['uid'];

        $query = $this->db->simple_select(
            'post_likes',
            '*',
            "post_id = {$postId} AND user_id = {$userId}",
            ['limit' => 1]
        );

        $createdAt = new DateTime();
        $timestamp = $createdAt->format('Y-m-d H:i:s');

        if ($this->db->num_rows($query) > 0) {
            $this->db->delete_query('post_likes', "post_id = {$postId} AND user_id = {$userId}", 1);

            return static::RESULT_UNLIKED;
        } else {
            $insertArray = [
                'post_id' => $postId,
                'user_id' => $userId,
                'created_at' => $this->db->escape_string($timestamp),
            ];

            $this->db->insert_query('post_likes', $insertArray);

            return static::RESULT_LIKED;
        }
    }

    /**
     * Get all likes for a specific post or set of posts.
     *
     * @param int|array $pid The post id(s) to fetch likes for.
     *
     * @return array The likes, along with the user details for the user that performed the like.
     */
    public function getLikes($pid): array
    {
        $likes = [];

        $tablePrefix = TABLE_PREFIX;

        if (is_string($pid)) {
            $inClause = str_replace('pid', 'l.post_id', $pid);

            $queryString = <<<SQL
SELECT l.*, u.username, u.avatar, u.usergroup, u.displaygroup 
FROM {$tablePrefix}post_likes l 
LEFT JOIN {$tablePrefix}users u ON (l.user_id = u.uid) 
WHERE {$inClause};
SQL;

            $query = $this->db->query($queryString);
            while ($like = $this->db->fetch_array($query)) {
                $likes[(int)$like['post_id']][(int)$like['user_id']] = $like;
            }
        } else if (is_int($pid)) {
            $queryString = <<<SQL
SELECT l.*, u.username, u.avatar, u.usergroup, u.displaygroup 
FROM {$tablePrefix}post_likes l 
INNER JOIN {$tablePrefix}users u ON (l.user_id = u.uid) 
WHERE l.post_id = {$pid};
SQL;

            $query = $this->db->query($queryString);
            while ($like = $this->db->fetch_array($query)) {
                $likes[(int)$like['user_id']] = $like;
            }
        }

        return $likes;
    }

    /**
     * Format likes into a string for output in the postbit.
     *
     * @param array $postLikes An array of likes for posts.
     * @param array $post The originator post's array.
     *
     * @return string The formatted likes.
     */
    public function formatLikes(array $postLikes, array $post): string
    {
        $goTo = (int)$this->mybb->settings['simplelikes_num_users'];
        $likeArray = [];
        $likeString = '';

        $this->lang->load('simplelikes');

        if ($goTo == 0) {
            return '';
        }

        if (array_key_exists($this->mybb->user['uid'], $postLikes[(int)$post['pid']])) {
            $likeArray[] = $this->lang->simplelikes_you;
            unset($postLikes[(int)$post['pid']][(int)$this->mybb->user['uid']]);
            $goTo--;
        }

        if ($goTo > 0) {
            for ($i = 0; $i < $goTo; $i++) {
                if (!empty($postLikes[(int)$post['pid']])) {
                    $random = $postLikes[$post['pid']][array_rand($postLikes[(int)$post['pid']])];
                    $likeArray[] = build_profile_link(htmlspecialchars_uni($random['username']), $random['user_id']);
                    unset($postLikes[(int)$post['pid']][$random['user_id']]);
                }
            }
        }

        if (!empty($likeArray)) {
            if (count($likeArray) == 1 AND $likeArray[0] != 'You') {
                $likePhrase = $this->lang->simplelikes_like_plural;
            } else {
                $likePhrase = $this->lang->simplelikes_like_singular;
            }
            if (!empty($postLikes[(int)$post['pid']])) {
                $likeList = implode($this->lang->comma, $likeArray);
                $count = my_number_format(count($postLikes[(int)$post['pid']]));
                $likeString = $this->lang->sprintf($this->lang->simplelikes_like_others, $likeList, $count, $likePhrase,
                    $post['pid']);
            } else {
                if (count($likeArray) > 1) {
                    $last = array_pop($likeArray);
                    $likeList = implode($this->lang->comma, $likeArray);
                    $likeList .= ' ' . $this->lang->simplelikes_and . ' ' . $last;
                } else {
                    $likeList = $likeArray[0];
                }
                $likeString = $this->lang->sprintf($this->lang->simplelikes_like_normal, $likeList, $likePhrase);
            }
        }

        return $likeString;
    }
}
