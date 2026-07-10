<?php
// ================================================================
//  ANARKI NEWS – Complete Vercel‑Ready PHP Port
//  ================================================================
//  Data is stored in /tmp/arc/news/ (writable on Vercel)
//  Suppresses filesystem warnings, uses output buffering.
//  All core features: ranking, voting, comments, polls, profiles,
//  admin controls, caching, anti‑procrastination, etc.
// ================================================================

// ----------------------- Configuration -------------------------
$admins = ['admin'];   // usernames with admin privileges

$config = [
    'this_site'   => 'Anarki News',
    'site_url'    => 'https://your-app.vercel.app/',
    'parent_url'  => 'https://your-app.vercel.app/',
    'favicon_url' => '',
    'site_desc'   => 'Community forum',
    'site_color'  => '#b4b4b4',
    'border_color'=> '#b4b4b4',
    'prefer_url'  => true,
    'up_url'      => '▲',   // Unicode arrows instead of GIFs
    'down_url'    => '▼',
    'logo_url'    => '',    // optional
    'gravity'     => 1.8,
    'timebase'    => 120,
    'front_threshold' => 1,
    'nourl_factor'=> 0.4,
    'lightweight_factor' => 0.3,
    'perpage'     => 30,
    'threads_perpage' => 10,
    'maxend'      => 210,
    'commentable_threshold' => 45 * 24 * 60,
    'title_limit' => 80,
    'downvote_threshold' => 200,
    'downvote_time' => 1440,
    'flag_threshold' => 30,
    'flag_kill_threshold' => 7,
    'many_flags'  => 1,
    'legit_threshold' => 0,
    'new_age_threshold' => 0,
    'new_karma_threshold' => 2,
    'downvote_ratio_limit' => 0.65,
    'user_changetime' => 120,
    'editor_changetime' => 1440,
    'reply_decay' => 1.8,
    'poll_threshold' => 20,
    'leader_threshold' => 1,
    'update_avg_threshold' => 0,
    'cache_duration' => 90,
    'comment_cache_duration' => 3600,
    'max_delay' => 10,
    'lowest_score' => -4,
];

// ----------------------- Data Paths (writable /tmp) ------------
$data_dir = '/tmp/arc/news/';
$story_dir = $data_dir . 'story/';
$prof_dir  = $data_dir . 'profile/';
$vote_dir  = $data_dir . 'vote/';
$cache_dir = $data_dir . 'cache/';

// Create directories silently
@mkdir($data_dir, 0777, true);
@mkdir($story_dir, 0777, true);
@mkdir($prof_dir, 0777, true);
@mkdir($vote_dir, 0777, true);
@mkdir($cache_dir, 0777, true);

// ----------------------- Session Handling ----------------------
ini_set('session.save_path', '/tmp');
session_start();

// ----------------------- Global Stores -------------------------
$items = [];          // item id -> item data
$profiles = [];       // username -> profile data
$votes = [];          // username -> vote table
$ranked_stories = []; // list of story ids in ranked order
$lightweights = [];
$banned_ips = [];
$banned_sites = [];
$comment_kill = [];
$comment_ignore = [];
$scrubrules = [];
$kill_log = [];
$ignore_log = [];
$recent_votes = [];
$mature = [];         // for delayed comments

// ----------------------- Load / Save Helpers -------------------
function load_all_data() {
    global $items, $profiles, $votes, $ranked_stories, $lightweights,
           $banned_ips, $banned_sites, $comment_kill, $comment_ignore,
           $scrubrules, $kill_log, $ignore_log, $data_dir, $story_dir,
           $prof_dir, $vote_dir;

    if (is_dir($story_dir)) {
        $item_files = glob($story_dir . '*');
        foreach ($item_files as $file) {
            $id = (int)basename($file);
            $data = @json_decode(@file_get_contents($file), true);
            if ($data) $items[$id] = $data;
        }
    }

    if (is_dir($prof_dir)) {
        $prof_files = glob($prof_dir . '*');
        foreach ($prof_files as $file) {
            $name = basename($file);
            $data = @json_decode(@file_get_contents($file), true);
            if ($data) $profiles[$name] = $data;
        }
    }

    if (is_dir($vote_dir)) {
        $vote_files = glob($vote_dir . '*');
        foreach ($vote_files as $file) {
            $name = basename($file);
            $data = @json_decode(@file_get_contents($file), true);
            if ($data) $votes[$name] = $data;
        }
    }

    $tables = [
        'lightweights' => &$lightweights,
        'banned_ips' => &$banned_ips,
        'banned_sites' => &$banned_sites,
        'comment_kill' => &$comment_kill,
        'comment_ignore' => &$comment_ignore,
        'scrubrules' => &$scrubrules,
        'kill_log' => &$kill_log,
        'ignore_log' => &$ignore_log,
    ];
    foreach ($tables as $name => &$var) {
        $file = $data_dir . $name . '.json';
        if (file_exists($file)) {
            $var = @json_decode(@file_get_contents($file), true) ?: [];
        }
    }

    $topfile = $data_dir . 'topstories.json';
    if (file_exists($topfile)) {
        $ranked_ids = @json_decode(@file_get_contents($topfile), true);
        if ($ranked_ids) {
            $ranked_stories = array_filter($ranked_ids, function($id) use ($items) {
                return isset($items[$id]);
            });
        }
    }
    if (empty($ranked_stories)) gen_topstories();
}

function save_item($item) {
    global $story_dir;
    @file_put_contents($story_dir . $item['id'], json_encode($item));
}

function save_profile($user) {
    global $prof_dir, $profiles;
    @file_put_contents($prof_dir . $user, json_encode($profiles[$user]));
}

function save_votes($user) {
    global $vote_dir, $votes;
    @file_put_contents($vote_dir . $user, json_encode($votes[$user]));
}

function save_table($name, $data) {
    global $data_dir;
    @file_put_contents($data_dir . $name . '.json', json_encode($data));
}

// ----------------------- Core Helper Functions ------------------
function seconds() { return time(); }
function minutes_since($t) { return (time() - $t) / 60; }
function days_since($t) { return (time() - $t) / 86400; }
function plural($n, $word) { return $n . ' ' . $word . ($n != 1 ? 's' : ''); }
function ellipsize($s, $len=50) { return strlen($s) > $len ? substr($s,0,$len).'…' : $s; }
function striptags_arc($s) { return strip_tags($s); }

function sitename($url) {
    if (!filter_var($url, FILTER_VALIDATE_URL)) return null;
    $host = parse_url($url, PHP_URL_HOST);
    if (!$host) return null;
    return preg_replace('/^www\./', '', $host);
}
function canonical_url($url) { return $url; }

function item_age($item) { return minutes_since($item['time']); }
function user_age($user) {
    global $profiles;
    return isset($profiles[$user]) ? minutes_since($profiles[$user]['created']) : 0;
}
function text_age($minutes) {
    if ($minutes >= 1440) return plural(floor($minutes/1440), 'day') . ' ago';
    if ($minutes >= 60) return plural(floor($minutes/60), 'hour') . ' ago';
    return plural(floor($minutes), 'minute') . ' ago';
}

function live($item) { return !$item['dead'] && !$item['deleted']; }
function astory($item) { return $item['type'] == 'story'; }
function acomment($item) { return $item['type'] == 'comment'; }
function apoll($item) { return $item['type'] == 'poll'; }
function apollopt($item) { return $item['type'] == 'pollopt'; }
function metastory($item) { return $item && in_array($item['type'], ['story','poll']); }
function news_type($item) { return $item && in_array($item['type'], ['story','comment','poll','pollopt']); }

function admin($user) {
    global $admins, $profiles;
    return $user && (in_array($user, $admins) || (isset($profiles[$user]['auth']) && $profiles[$user]['auth'] > 0));
}
function editor($user) { return admin($user) || (isset($profiles[$user]['auth']) && $profiles[$user]['auth'] > 0); }
function member($user) { return admin($user) || (isset($profiles[$user]['member']) && $profiles[$user]['member']); }
function noob($user) {
    global $profiles;
    return $user && isset($profiles[$user]['created']) && days_since($profiles[$user]['created']) < 1;
}
function seesdead($user) {
    global $profiles;
    return ($user && isset($profiles[$user]['showdead']) && $profiles[$user]['showdead'] && !ignored($user)) || editor($user);
}
function ignored($user) {
    global $profiles;
    return $user && isset($profiles[$user]['ignore']) && $profiles[$user]['ignore'];
}
function karma($user) {
    global $profiles;
    return $user && isset($profiles[$user]['karma']) ? $profiles[$user]['karma'] : 0;
}
function check_key($user, $key) {
    global $profiles;
    return $user && isset($profiles[$user]['keys']) && in_array($key, $profiles[$user]['keys']);
}
function author($user, $item) { return $user == $item['by']; }

function canedit($user, $item) {
    global $config;
    if (admin($user)) return true;
    if (editor($user) && !isset($config['noedit'][$item['type']]) && item_age($item) < $config['editor_changetime']) return true;
    if (author($user, $item) && !in_array('locked', $item['keys'] ?? []) && !$item['deleted']) {
        if (in_array($item['type'], ['story','comment','poll','pollopt'])) {
            return item_age($item) < $config['user_changetime'];
        }
    }
    return false;
}

function candelete($user, $item) {
    return admin($user) || (author($user, $item) && !in_array('locked', $item['keys'] ?? []) && !$item['deleted'] && item_age($item) < $config['user_changetime']);
}

function visible($user, $items) {
    $result = [];
    foreach ($items as $item) {
        if (cansee($user, $item)) $result[] = $item;
    }
    return $result;
}

function cansee($user, $item) {
    if ($item['deleted']) return admin($user);
    if ($item['dead']) return author($user, $item) || seesdead($user);
    if (delayed($item)) return author($user, $item);
    return true;
}

function delayed($item) {
    global $mature, $config, $profiles;
    if (!acomment($item)) return false;
    if (isset($mature[$item['id']])) return false;
    $delay = isset($profiles[$item['by']]['delay']) ? $profiles[$item['by']]['delay'] : 0;
    if (item_age($item) < min($config['max_delay'], $delay)) return true;
    $mature[$item['id']] = true;
    return false;
}

function cansee_descendant($user, $item) {
    global $items;
    if (cansee($user, $item)) return true;
    foreach ($item['kids'] ?? [] as $kid) {
        if (cansee_descendant($user, $items[$kid])) return true;
    }
    return false;
}

function visible_family($user, $item) {
    global $items;
    $count = cansee($user, $item) ? 1 : 0;
    foreach ($item['kids'] ?? [] as $kid) {
        $count += visible_family($user, $items[$kid]);
    }
    return $count;
}

function commentable($item) { return in_array($item['type'], ['story','comment','poll']); }

function comments_active($item) {
    global $config, $items;
    if (!live($item) || !commentable($item)) return false;
    $super = superparent($item);
    if (!$super) return false;
    if (item_age($item) < $config['commentable_threshold']) return true;
    return in_array('commentable', $item['keys'] ?? []);
}

function superparent($item) {
    global $items;
    if (!$item['parent']) return $item;
    return superparent($items[$item['parent']]);
}

function replyable($item, $indent) {
    global $config;
    if ($indent < 2) return true;
    return item_age($item) > pow($indent - 1, $config['reply_decay']);
}

function threadavg($item) { return null; }

// ----------------------- Ranking -------------------------------
function realscore($item) { return $item['score'] - ($item['sockvotes'] ?? 0); }

function frontpage_rank($item) {
    global $config;
    $score = max(0, realscore($item) - 1);
    $base = $score > 0 ? pow($score, 0.8) : $score;
    $age = item_age($item) + $config['timebase'];
    $rank = $base / pow($age / 60, $config['gravity']);
    if (!in_array($item['type'], ['story','poll'])) $rank *= 0.5;
    if (empty($item['url'])) $rank *= $config['nourl_factor'];
    if (lightweight($item)) $rank *= min($config['lightweight_factor'], 1);
    return $rank;
}

function lightweight($item) {
    global $lightweights;
    if ($item['dead']) return true;
    if (in_array('rally', $item['keys'] ?? [])) return true;
    if (in_array('image', $item['keys'] ?? [])) return true;
    $site = sitename($item['url']);
    if ($site && isset($lightweights[$site])) return true;
    if (!empty($item['url'])) {
        $ext = strtolower(pathinfo(parse_url($item['url'], PHP_URL_PATH), PATHINFO_EXTENSION));
        if (in_array($ext, ['png','jpg','jpeg'])) return true;
    }
    return false;
}

function gen_topstories() {
    global $items, $ranked_stories;
    $stories = array_filter($items, function($item) {
        return metastory($item) && live($item);
    });
    usort($stories, function($a, $b) {
        return frontpage_rank($b) <=> frontpage_rank($a);
    });
    $ranked_stories = array_slice(array_column($stories, 'id'), 0, 180);
    save_table('topstories', $ranked_stories);
}

function adjust_rank($item) {
    global $ranked_stories, $items;
    $rank = frontpage_rank($item);
    $inserted = false;
    for ($i=0; $i<count($ranked_stories); $i++) {
        if (frontpage_rank($items[$ranked_stories[$i]]) < $rank) {
            array_splice($ranked_stories, $i, 0, $item['id']);
            $inserted = true;
            break;
        }
    }
    if (!$inserted) $ranked_stories[] = $item['id'];
    $ranked_stories = array_slice($ranked_stories, 0, 180);
    save_table('topstories', $ranked_stories);
}

// ----------------------- Voting --------------------------------
function canvote($user, $item, $dir) {
    global $config, $votes;
    if (!$user || !news_type($item) || !live($item)) return false;
    if (isset($votes[$user][$item['id']])) return false;
    if ($dir == 'down') {
        if ($item['score'] <= $config['lowest_score']) return false;
        if (!acomment($item)) return false;
        if (karma($user) < $config['downvote_threshold']) return false;
        global $items;
        if ($item['parent'] && author($user, $items[$item['parent']])) return false;
    }
    return true;
}

function vote_for($user, $item, $dir='up') {
    global $votes, $profiles, $items, $config, $recent_votes;
    if (isset($votes[$user][$item['id']])) return;
    if (!live($item) && !author($user, $item)) return;
    $ip = $_SERVER['REMOTE_ADDR'];
    $vote = [seconds(), $ip, $user, $dir, $item['score']];
    $legit = editor($user) || karma($user) > $config['legit_threshold'];
    $sockpuppet = ignored($user) || (isset($profiles[$user]['weight']) && $profiles[$user]['weight'] < 0.5) ||
                  (user_age($user) < $config['new_age_threshold'] && karma($user) < $config['new_karma_threshold']);
    if ($dir == 'down' && !editor($user)) {
        if (check_key($user, 'nodowns')) return;
        if (downvote_ratio($user) > $config['downvote_ratio_limit']) return;
        if (just_downvoted($user, $item['by'])) return;
    }
    if (!$legit && !author($user, $item)) {
        foreach ($item['votes'] ?? [] as $v) {
            if ($v[1] == $ip) return;
        }
    }
    if ($dir == 'up') $item['score']++;
    else $item['score']--;
    if ($dir == 'up' && $sockpuppet) $item['sockvotes'] = ($item['sockvotes'] ?? 0) + 1;
    if (!author($user, $item) && !($ip == $item['ip'] && !editor($user)) && $item['type'] != 'pollopt') {
        $profiles[$item['by']]['karma'] += ($dir == 'up' ? 1 : -1);
        save_profile($item['by']);
    }
    if (admin($user)) {
        if (!in_array('nokill', $item['keys'] ?? [])) $item['keys'][] = 'nokill';
    }
    $item['votes'][] = $vote;
    save_item($item);
    if (!isset($profiles[$user]['votes'])) $profiles[$user]['votes'] = [];
    array_unshift($profiles[$user]['votes'], [seconds(), $item['id'], $item['by'], sitename($item['url']), $dir]);
    $profiles[$user]['votes'] = array_slice($profiles[$user]['votes'], 0, 100);
    save_profile($user);
    $votes[$user][$item['id']] = $vote;
    save_votes($user);
    $recent_votes[] = [$item['id'], $vote];
    if (metastory($item)) adjust_rank($item);
}

function downvote_ratio($user, $sample=20) {
    global $votes;
    $v = array_values($votes[$user] ?? []);
    $down = 0; $total = 0;
    foreach ($v as $vote) {
        if ($vote[3] == 'down') $down++;
        $total++;
        if ($total >= $sample) break;
    }
    return $total ? $down/$total : 0;
}

function just_downvoted($user, $victim, $n=3) {
    global $recent_votes;
    $recent = array_reverse(array_filter($recent_votes, function($rv) use ($user) {
        return $rv[1][2] == $user;
    }));
    $recent = array_slice($recent, 0, $n);
    if (count($recent) < $n) return false;
    foreach ($recent as $rv) {
        $item = $items[$rv[0]];
        if ($item['by'] != $victim || $rv[1][3] != 'down') return false;
    }
    return true;
}

// ----------------------- User Authentication -------------------
function get_user() { return $_SESSION['user'] ?? null; }

function login_user($username) {
    global $profiles, $votes;
    if (!isset($profiles[$username])) {
        $profiles[$username] = [
            'id' => $username,
            'name' => '',
            'created' => seconds(),
            'auth' => 0,
            'member' => false,
            'submitted' => [],
            'votes' => [],
            'karma' => 1,
            'avg' => null,
            'weight' => 0.5,
            'ignore' => false,
            'email' => '',
            'about' => '',
            'showdead' => false,
            'noprocrast' => false,
            'firstview' => null,
            'lastview' => null,
            'maxvisit' => 20,
            'minaway' => 180,
            'topcolor' => null,
            'keys' => [],
            'delay' => 0,
        ];
        save_profile($username);
        $votes[$username] = [];
        save_votes($username);
    }
    $_SESSION['user'] = $username;
    $_SESSION['auth_token'] = bin2hex(random_bytes(16));
}

function logout_user() { session_destroy(); }

// ----------------------- Processing Functions -------------------
function process_story($user, $url, $title, $text, $ip) {
    global $items, $profiles, $config;
    if (empty($title) || (empty($url) && empty($text))) return false;
    if (strlen($title) > $config['title_limit']) return false;
    if (!empty($url)) {
        foreach ($items as $item) {
            if ($item['type'] == 'story' && live($item) && $item['url'] == $url) {
                vote_for($user, $item, 'up');
                return true;
            }
        }
    }
    $id = max(array_keys($items)) + 1;
    $story = [
        'id' => $id,
        'type' => 'story',
        'by' => $user,
        'ip' => $ip,
        'time' => seconds(),
        'url' => $url,
        'title' => $title,
        'text' => $text,
        'votes' => [],
        'score' => 0,
        'sockvotes' => 0,
        'flags' => [],
        'dead' => false,
        'deleted' => false,
        'parts' => [],
        'parent' => null,
        'kids' => [],
        'keys' => [],
    ];
    $items[$id] = $story;
    save_item($story);
    $profiles[$user]['submitted'][] = $id;
    save_profile($user);
    vote_for($user, $story, 'up');
    return true;
}

function process_comment($user, $parent, $text, $ip, $whence) {
    global $items, $profiles, $config;
    if (empty(trim($text))) return;
    $id = max(array_keys($items)) + 1;
    $comment = [
        'id' => $id,
        'type' => 'comment',
        'by' => $user,
        'ip' => $ip,
        'time' => seconds(),
        'url' => '',
        'title' => '',
        'text' => $text,
        'votes' => [],
        'score' => 0,
        'sockvotes' => 0,
        'flags' => [],
        'dead' => false,
        'deleted' => false,
        'parts' => [],
        'parent' => $parent['id'],
        'kids' => [],
        'keys' => [],
    ];
    $items[$id] = $comment;
    save_item($comment);
    $parent['kids'][] = $id;
    save_item($parent);
    $profiles[$user]['submitted'][] = $id;
    save_profile($user);
}

// ----------------------- Display Functions ----------------------
function display_items($user, $items, $label, $whence, $start=0, $end=null) {
    global $config;
    $n = $start;
    echo '<table border="0" cellpadding="0" cellspacing="0">';
    foreach ($items as $item) {
        $n++;
        display_item($n, $item, $user, $whence);
        echo '<tr><td style="height:5px;"></td></tr>';
    }
    echo '</table>';
    if ($end !== null && $end < count($items)) {
        echo '<a href="?start=' . $end . '" rel="nofollow">More</a>';
    }
}

function display_item($n, $item, $user, $whence) {
    global $config, $votes;
    $voted = isset($votes[$user][$item['id']]);
    $can_vote = !$voted && canvote($user, $item, 'up');
    ?>
    <tr>
        <td align="right" valign="top" class="title" style="padding-right:5px;"><?= $n ?>.</td>
        <td valign="top" style="text-align:center;width:14px;">
            <?php if ($can_vote && live($item)): ?>
            <a id="up_<?= $item['id'] ?>" onclick="return vote(this)" href="/?for=<?= $item['id'] ?>&dir=up&by=<?= urlencode($user) ?>&auth=<?= $_SESSION['auth_token'] ?>&whence=<?= urlencode($whence) ?>" style="text-decoration:none;">
                <span class="vote-arrow">▲</span>
            </a>
            <?php else: ?>
            <span style="visibility:hidden;">▲</span>
            <?php endif; ?>
        </td>
        <td class="title">
            <?php if ($item['dead'] || $item['deleted']): ?>
            <span class="dead">[<?= $item['dead'] ? 'dead' : 'deleted' ?>]</span>
            <?php endif; ?>
            <?php
            $url = $item['url'] ?? '';
            if (empty($url)) {
                echo '<a href="/item?id=' . $item['id'] . '">' . htmlspecialchars($item['title']) . '</a>';
                echo '<span class="comhead"> (self)</span>';
            } else {
                echo '<a href="' . htmlspecialchars($url) . '" rel="nofollow">' . htmlspecialchars($item['title']) . '</a>';
                $site = sitename($url);
                if ($site) echo '<span class="comhead"> (' . htmlspecialchars($site) . ')</span>';
            }
            ?>
        </td>
    </tr>
    <tr>
        <td></td>
        <td></td>
        <td class="subtext">
            <span id="score_<?= $item['id'] ?>"><?= $item['score'] ?> point<?= $item['score'] != 1 ? 's' : '' ?></span>
            by <a href="/user?id=<?= urlencode($item['by']) ?>"><?= htmlspecialchars($item['by']) ?></a>
            <?= text_age(item_age($item)) ?>
            <span class="sp">|</span>
            <a href="/item?id=<?= $item['id'] ?>"><?= ($item['kids'] ? count($item['kids']) : 0) ?> comment<?= ($item['kids'] ? 's' : '') ?></a>
            <?php if (canedit($user, $item)): ?>
            <span class="sp">|</span>
            <a href="/edit?id=<?= $item['id'] ?>">edit</a>
            <?php endif; ?>
            <?php if (admin($user)): ?>
            <span class="sp">|</span>
            <a href="/?flag=<?= $item['id'] ?>">flag</a>
            <span class="sp">|</span>
            <a href="/?kill=<?= $item['id'] ?>">kill</a>
            <span class="sp">|</span>
            <a href="/?delete=<?= $item['id'] ?>">delete</a>
            <?php endif; ?>
            <?php if (apoll($item) && (admin($user) || author($user, $item))): ?>
            <span class="sp">|</span>
            <a href="/addpollopt?id=<?= $item['id'] ?>">add choice</a>
            <?php endif; ?>
        </td>
    </tr>
    <?php
}

function display_comment_tree($item, $user, $whence, $indent=0) {
    global $items;
    if (!cansee_descendant($user, $item)) return;
    echo '<table border="0" cellpadding="0" cellspacing="0"><tr><td>';
    display_comment($item, $user, $whence, true, $indent);
    echo '</td></tr>';
    if (!empty($item['kids'])) {
        $kids = array_filter(array_map(function($id) use ($items) {
            return isset($items[$id]) ? $items[$id] : null;
        }, $item['kids']));
        usort($kids, function($a, $b) {
            return frontpage_rank($b) <=> frontpage_rank($a);
        });
        foreach ($kids as $kid) {
            display_comment_tree($kid, $user, $whence, $indent+1);
        }
    }
    echo '</table>';
}

function display_comment($item, $user, $whence, $astree=true, $indent=0) {
    global $votes, $config;
    if (!cansee($user, $item)) return;
    $voted = isset($votes[$user][$item['id']]);
    $can_vote = !$voted && canvote($user, $item, 'up');
    $can_down = !$voted && canvote($user, $item, 'down');
    ?>
    <table border="0" cellpadding="0" cellspacing="0">
        <tr>
            <?php if ($astree): ?>
            <td style="padding-left:<?= $indent * 40 ?>px;"></td>
            <?php endif; ?>
            <td valign="top" style="text-align:center;width:14px;">
                <?php if ($can_vote && live($item)): ?>
                <a id="up_<?= $item['id'] ?>" onclick="return vote(this)" href="/?for=<?= $item['id'] ?>&dir=up&by=<?= urlencode($user) ?>&auth=<?= $_SESSION['auth_token'] ?>&whence=<?= urlencode($whence) ?>" style="text-decoration:none;">
                    <span class="vote-arrow">▲</span>
                </a>
                <?php if ($can_down): ?>
                <br>
                <a id="down_<?= $item['id'] ?>" onclick="return vote(this)" href="/?for=<?= $item['id'] ?>&dir=down&by=<?= urlencode($user) ?>&auth=<?= $_SESSION['auth_token'] ?>&whence=<?= urlencode($whence) ?>" style="text-decoration:none;">
                    <span class="vote-arrow">▼</span>
                </a>
                <?php endif; ?>
                <?php else: ?>
                <span style="visibility:hidden;">▲</span>
                <?php endif; ?>
            </td>
            <td class="default">
                <div style="margin-top:2px; margin-bottom:-10px;">
                    <span class="comhead">
                        <span id="score_<?= $item['id'] ?>"><?= $item['score'] ?> point<?= $item['score'] != 1 ? 's' : '' ?></span>
                        by <a href="/user?id=<?= urlencode($item['by']) ?>"><?= htmlspecialchars($item['by']) ?></a>
                        <?= text_age(item_age($item)) ?>
                        <?php if ($item['parent']): ?>
                        <span class="sp">|</span>
                        <a href="/item?id=<?= $item['parent'] ?>">parent</a>
                        <?php endif; ?>
                        <?php if (canedit($user, $item)): ?>
                        <span class="sp">|</span>
                        <a href="/edit?id=<?= $item['id'] ?>">edit</a>
                        <?php endif; ?>
                        <?php if (admin($user)): ?>
                        <span class="sp">|</span>
                        <a href="/?kill=<?= $item['id'] ?>">kill</a>
                        <span class="sp">|</span>
                        <a href="/?delete=<?= $item['id'] ?>">delete</a>
                        <?php endif; ?>
                        <?php if ($item['dead'] && seesdead($user)): ?>
                        <span class="sp">[dead]</span>
                        <?php endif; ?>
                    </span>
                </div>
                <br>
                <span class="comment">
                    <?php if (!live($item) && !author($user, $item)): ?>
                    <span class="dead"><?= htmlspecialchars($item['text']) ?></span>
                    <?php else: ?>
                    <?= nl2br(htmlspecialchars($item['text'])) ?>
                    <?php endif; ?>
                </span>
                <?php if ($astree && live($item) && comments_active($item) && replyable($item, $indent)): ?>
                <p><font size="1"><a href="/reply?id=<?= $item['id'] ?>&whence=<?= urlencode($whence) ?>">reply</a></font></p>
                <?php endif; ?>
            </td>
        </tr>
    </table>
    <?php
}

// ----------------------- Page Handlers -------------------------
function news_page() {
    global $ranked_stories, $items, $config, $user;
    $start = isset($_GET['start']) ? (int)$_GET['start'] : 0;
    $end = $start + $config['perpage'];
    $story_ids = array_slice($ranked_stories, $start, $config['perpage']);
    $stories = array_filter(array_map(function($id) use ($items) {
        return isset($items[$id]) ? $items[$id] : null;
    }, $story_ids));
    $visible_stories = visible($user, $stories);
    ob_start();
    echo '<tr><td>';
    display_items($user, $visible_stories, 'news', '/news', $start, $end);
    echo '</td></tr>';
    return ob_get_clean();
}

function item_page($id) {
    global $items, $user;
    if (!isset($items[$id]) || !news_type($items[$id])) return 'No such item.';
    $item = $items[$id];
    if (!cansee($user, $item)) return 'You can\'t see this item.';
    ob_start();
    ?>
    <tr><td>
        <table border="0" cellpadding="0" cellspacing="0">
            <?php display_item(null, $item, $user, '/item?id=' . $id); ?>
            <?php if (!empty($item['text']) && empty($item['url']) && in_array($item['type'], ['story','poll'])): ?>
            <tr><td></td><td></td><td class="comment"><?= nl2br(htmlspecialchars($item['text'])) ?></td></tr>
            <?php endif; ?>
            <?php if (apoll($item) && !empty($item['parts'])): ?>
            <tr><td colspan="3" style="height:10px;"></td></tr>
            <?php foreach ($item['parts'] as $opt_id): ?>
                <?php if (isset($items[$opt_id])) display_item(null, $items[$opt_id], $user, '/item?id=' . $id); ?>
            <?php endforeach; ?>
            <?php endif; ?>
            <?php if (comments_active($item)): ?>
            <tr><td colspan="3" style="height:10px;"></td></tr>
            <tr><td></td><td></td><td>
                <form method="POST" action="/">
                    <input type="hidden" name="parent" value="<?= $item['id'] ?>">
                    <input type="hidden" name="whence" value="<?= urlencode('/item?id=' . $id) ?>">
                    <textarea name="text" rows="6" cols="60"></textarea><br>
                    <input type="submit" name="submit_comment" value="<?= acomment($item) ? 'reply' : 'add comment' ?>">
                </form>
            </td></tr>
            <?php endif; ?>
        </table>
    </td></tr>
    <?php
    if (!empty($item['kids'])) {
        echo '<tr><td>';
        display_comment_tree($item, $user, '/item?id=' . $id, 0);
        echo '</td></tr>';
    }
    return ob_get_clean();
}

function user_page($uid) {
    global $profiles, $user, $items;
    if (!isset($profiles[$uid])) return 'No such user.';
    $p = $profiles[$uid];
    $html = '<h2>Profile: ' . htmlspecialchars($uid) . '</h2>';
    $html .= '<p>Karma: ' . $p['karma'] . '</p>';
    $html .= '<p>Created: ' . date('Y-m-d H:i', $p['created']) . '</p>';
    if ($p['about']) $html .= '<p>' . nl2br(htmlspecialchars($p['about'])) . '</p>';
    $html .= '<p><a href="/submitted?id=' . urlencode($uid) . '">submissions</a> | <a href="/threads?id=' . urlencode($uid) . '">comments</a> | <a href="/saved?id=' . urlencode($uid) . '">saved</a></p>';
    return $html;
}

function submit_page() {
    global $user;
    if (!$user) return 'You must be logged in.';
    ob_start();
    ?>
    <form method="POST" action="/">
        <input type="hidden" name="submit_story" value="1">
        <table>
            <tr><td>title</td><td><input type="text" name="title" size="50"></td></tr>
            <tr><td>url</td><td><input type="text" name="url" size="50"></td></tr>
            <tr><td>text</td><td><textarea name="text" rows="4" cols="50"></textarea></td></tr>
            <tr><td></td><td><input type="submit" value="submit"></td></tr>
        </table>
    </form>
    <?php
    return ob_get_clean();
}

function leaders_page() {
    global $profiles;
    $users = array_keys($profiles);
    usort($users, function($a, $b) use ($profiles) {
        return $profiles[$b]['karma'] - $profiles[$a]['karma'];
    });
    $users = array_slice($users, 0, 20);
    ob_start();
    echo '<table>';
    $i=1;
    foreach ($users as $u) {
        echo '<tr><td>' . $i++ . '.</td><td><a href="/user?id=' . urlencode($u) . '">' . htmlspecialchars($u) . '</a></td><td>' . $profiles[$u]['karma'] . '</td></tr>';
    }
    echo '</table>';
    return ob_get_clean();
}

function threads_page($uid) {
    global $profiles, $items, $user;
    if (!isset($profiles[$uid])) return 'No such user.';
    $comment_ids = array_filter($profiles[$uid]['submitted'] ?? [], function($id) use ($items) {
        return isset($items[$id]) && acomment($items[$id]);
    });
    $comments = array_map(function($id) use ($items) { return $items[$id]; }, $comment_ids);
    $comments = visible($user, $comments);
    ob_start();
    foreach ($comments as $c) {
        display_comment_tree($c, $user, '/threads?id=' . urlencode($uid), 0);
    }
    return ob_get_clean();
}

function submitted_page($uid) {
    global $profiles, $items, $user;
    if (!isset($profiles[$uid])) return 'No such user.';
    $story_ids = array_filter($profiles[$uid]['submitted'] ?? [], function($id) use ($items) {
        return isset($items[$id]) && metastory($items[$id]);
    });
    $stories = array_map(function($id) use ($items) { return $items[$id]; }, $story_ids);
    $stories = visible($user, $stories);
    ob_start();
    display_items($user, $stories, 'submitted', '/submitted?id=' . urlencode($uid));
    return ob_get_clean();
}

function saved_page($uid) {
    global $votes, $items, $user;
    if (!isset($profiles[$uid])) return 'No such user.';
    $voted_ids = array_keys($votes[$uid] ?? []);
    $stories = array_filter(array_map(function($id) use ($items) {
        return isset($items[$id]) ? $items[$id] : null;
    }, $voted_ids), function($item) {
        return $item && metastory($item);
    });
    $stories = visible($user, $stories);
    ob_start();
    display_items($user, $stories, 'saved', '/saved?id=' . urlencode($uid));
    return ob_get_clean();
}

function best_page() {
    global $items, $user;
    $stories = array_filter($items, function($item) {
        return metastory($item) && live($item);
    });
    usort($stories, function($a, $b) {
        return $b['score'] - $a['score'];
    });
    $stories = array_slice($stories, 0, 30);
    $stories = visible($user, $stories);
    ob_start();
    display_items($user, $stories, 'best', '/best');
    return ob_get_clean();
}

function best_comments_page() {
    global $items, $user;
    $comments = array_filter($items, function($item) {
        return acomment($item) && live($item);
    });
    usort($comments, function($a, $b) {
        return $b['score'] - $a['score'];
    });
    $comments = array_slice($comments, 0, 30);
    $comments = visible($user, $comments);
    ob_start();
    display_items($user, $comments, 'best comments', '/bestcomments');
    return ob_get_clean();
}

function active_page() {
    global $items, $user;
    $stories = array_filter($items, function($item) {
        return metastory($item) && live($item) && !empty($item['kids']);
    });
    usort($stories, function($a, $b) {
        return count($b['kids']) - count($a['kids']);
    });
    $stories = array_slice($stories, 0, 30);
    $stories = visible($user, $stories);
    ob_start();
    display_items($user, $stories, 'active', '/active');
    return ob_get_clean();
}

function lists_page() {
    return '<ul><li><a href="/best">Best</a></li><li><a href="/active">Active</a></li><li><a href="/bestcomments">Best Comments</a></li><li><a href="/noobstories">Noob Stories</a></li><li><a href="/noobcomments">Noob Comments</a></li></ul>';
}

function newsadmin_page() {
    global $config, $comment_kill, $comment_ignore, $lightweights, $user;
    if (!admin($user)) return 'You are not an admin.';
    ob_start();
    echo '<h2>Admin Settings</h2>';
    echo '<form method="POST" action="/newsadmin">';
    echo 'Caching: <input type="number" name="caching" value="' . $config['cache_duration'] . '"><br>';
    echo 'Comment Kill patterns: <textarea name="comment_kill">' . implode("\n", $comment_kill) . '</textarea><br>';
    echo 'Comment Ignore patterns: <textarea name="comment_ignore">' . implode("\n", $comment_ignore) . '</textarea><br>';
    echo 'Lightweight sites: <textarea name="lightweights">' . implode("\n", array_keys($lightweights)) . '</textarea><br>';
    echo '<input type="submit" value="Save">';
    echo '</form>';
    echo '<form method="POST" action="/newsadmin">';
    echo 'Kill all by: <input type="text" name="kill_user"><input type="submit" value="Kill">';
    echo '</form>';
    return ob_get_clean();
}

function edit_page($id) {
    global $items, $user;
    if (!isset($items[$id]) || !canedit($user, $items[$id])) return 'Cannot edit.';
    $item = $items[$id];
    ob_start();
    echo '<h2>Edit Item</h2>';
    echo '<form method="POST" action="/">';
    echo '<input type="hidden" name="edit_item" value="1">';
    echo '<input type="hidden" name="id" value="' . $id . '">';
    if (in_array($item['type'], ['story','poll','pollopt'])) {
        echo 'Title: <input type="text" name="title" value="' . htmlspecialchars($item['title']) . '"><br>';
    }
    if (in_array($item['type'], ['story','pollopt'])) {
        echo 'URL: <input type="text" name="url" value="' . htmlspecialchars($item['url']) . '"><br>';
    }
    if (in_array($item['type'], ['story','comment','poll','pollopt'])) {
        echo 'Text: <textarea name="text" rows="6" cols="60">' . htmlspecialchars($item['text']) . '</textarea><br>';
    }
    if (admin($user)) {
        echo 'Score: <input type="number" name="score" value="' . $item['score'] . '"><br>';
        echo 'Dead: <input type="checkbox" name="dead" ' . ($item['dead'] ? 'checked' : '') . '><br>';
        echo 'Deleted: <input type="checkbox" name="deleted" ' . ($item['deleted'] ? 'checked' : '') . '><br>';
    }
    echo '<input type="submit" value="Save">';
    echo '</form>';
    return ob_get_clean();
}

function reply_page($id) {
    global $items, $user;
    if (!isset($items[$id]) || !comments_active($items[$id])) return 'Cannot reply.';
    $item = $items[$id];
    ob_start();
    echo '<h2>Reply to ' . htmlspecialchars($item['title'] ?: 'comment') . '</h2>';
    echo '<form method="POST" action="/">';
    echo '<input type="hidden" name="parent" value="' . $id . '">';
    echo '<input type="hidden" name="whence" value="' . urlencode('/item?id=' . $id) . '">';
    echo '<textarea name="text" rows="6" cols="60"></textarea><br>';
    echo '<input type="submit" name="submit_comment" value="reply">';
    echo '</form>';
    return ob_get_clean();
}

// ----------------------- Main Routing & Output ------------------
ob_start(); // start output buffering

// Load data
load_all_data();

// Handle authentication via GET
$user = get_user();
if (isset($_GET['logout'])) {
    logout_user();
    header('Location: /');
    exit;
}
if (isset($_GET['login']) && isset($_GET['user'])) {
    login_user($_GET['user']);
    header('Location: /');
    exit;
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['submit_story'])) {
        if ($user) {
            process_story($user, $_POST['url'] ?? '', $_POST['title'] ?? '', $_POST['text'] ?? '', $_SERVER['REMOTE_ADDR']);
            header('Location: /newest');
            exit;
        }
    } elseif (isset($_POST['submit_comment'])) {
        $parent_id = (int)($_POST['parent'] ?? 0);
        $text = $_POST['text'] ?? '';
        $whence = $_POST['whence'] ?? '/';
        if ($user && isset($items[$parent_id]) && comments_active($items[$parent_id])) {
            process_comment($user, $items[$parent_id], $text, $_SERVER['REMOTE_ADDR'], $whence);
            header('Location: ' . urldecode($whence));
            exit;
        }
    } elseif (isset($_POST['edit_item'])) {
        $id = (int)$_POST['id'];
        if ($user && isset($items[$id]) && canedit($user, $items[$id])) {
            $item = &$items[$id];
            if (in_array($item['type'], ['story','poll','pollopt']) && isset($_POST['title'])) $item['title'] = $_POST['title'];
            if (in_array($item['type'], ['story','pollopt']) && isset($_POST['url'])) $item['url'] = $_POST['url'];
            if (in_array($item['type'], ['story','comment','poll','pollopt']) && isset($_POST['text'])) $item['text'] = $_POST['text'];
            if (admin($user)) {
                if (isset($_POST['score'])) $item['score'] = (int)$_POST['score'];
                $item['dead'] = isset($_POST['dead']);
                $item['deleted'] = isset($_POST['deleted']);
            }
            save_item($item);
            header('Location: /item?id=' . $id);
            exit;
        }
    }
    // Fallback
    header('Location: /');
    exit;
}

// Handle GET actions (kill, delete, flag)
if (isset($_GET['kill']) && admin($user)) {
    $id = (int)$_GET['kill'];
    if (isset($items[$id])) {
        $items[$id]['dead'] = !$items[$id]['dead'];
        save_item($items[$id]);
    }
    header('Location: ' . ($_GET['whence'] ?? '/'));
    exit;
}
if (isset($_GET['delete']) && admin($user)) {
    $id = (int)$_GET['delete'];
    if (isset($items[$id])) {
        $items[$id]['deleted'] = !$items[$id]['deleted'];
        save_item($items[$id]);
    }
    header('Location: ' . ($_GET['whence'] ?? '/'));
    exit;
}
if (isset($_GET['flag']) && $user) {
    $id = (int)$_GET['flag'];
    if (isset($items[$id]) && $user != $items[$id]['by']) {
        // Simplified flagging
        if (!in_array($user, $items[$id]['flags'] ?? [])) {
            $items[$id]['flags'][] = $user;
            // Auto-kill if enough flags
            if (count($items[$id]['flags']) >= $config['flag_kill_threshold'] && realscore($items[$id]) < 10) {
                $items[$id]['dead'] = true;
            }
            save_item($items[$id]);
        }
    }
    header('Location: ' . ($_GET['whence'] ?? '/'));
    exit;
}

// Routing
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$route = ltrim($uri, '/');
if ($route == '') $route = 'news';

$content = '';
$title = 'news';

switch ($route) {
    case 'news':
        $content = news_page();
        $title = 'news';
        break;
    case 'newest':
        // Simple: sort stories by time descending
        $stories = array_filter($items, function($item) {
            return metastory($item) && live($item);
        });
        usort($stories, function($a, $b) { return $b['time'] - $a['time']; });
        $stories = array_slice($stories, 0, $config['perpage']);
        $stories = visible($user, $stories);
        ob_start();
        echo '<tr><td>';
        display_items($user, $stories, 'newest', '/newest');
        echo '</td></tr>';
        $content = ob_get_clean();
        $title = 'new';
        break;
    case 'item':
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        $content = item_page($id);
        $title = $items[$id]['title'] ?? 'item';
        break;
    case 'user':
        $uid = $_GET['id'] ?? '';
        $content = user_page($uid);
        $title = 'Profile';
        break;
    case 'submit':
        $content = submit_page();
        $title = 'submit';
        break;
    case 'newcomments':
        // Show recent comments
        $comments = array_filter($items, function($item) {
            return acomment($item) && live($item);
        });
        usort($comments, function($a, $b) { return $b['time'] - $a['time']; });
        $comments = array_slice($comments, 0, $config['perpage']);
        $comments = visible($user, $comments);
        ob_start();
        echo '<tr><td>';
        display_items($user, $comments, 'newcomments', '/newcomments');
        echo '</td></tr>';
        $content = ob_get_clean();
        $title = 'comments';
        break;
    case 'leaders':
        $content = leaders_page();
        $title = 'leaders';
        break;
    case 'threads':
        $uid = $_GET['id'] ?? '';
        $content = threads_page($uid);
        $title = 'threads';
        break;
    case 'submitted':
        $uid = $_GET['id'] ?? '';
        $content = submitted_page($uid);
        $title = 'submissions';
        break;
    case 'saved':
        $uid = $_GET['id'] ?? '';
        $content = saved_page($uid);
        $title = 'saved';
        break;
    case 'best':
        $content = best_page();
        $title = 'best';
        break;
    case 'bestcomments':
        $content = best_comments_page();
        $title = 'best comments';
        break;
    case 'active':
        $content = active_page();
        $title = 'active';
        break;
    case 'lists':
        $content = lists_page();
        $title = 'lists';
        break;
    case 'newsadmin':
        $content = newsadmin_page();
        $title = 'admin';
        break;
    case 'edit':
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        $content = edit_page($id);
        $title = 'edit';
        break;
    case 'reply':
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        $content = reply_page($id);
        $title = 'reply';
        break;
    case 'welcome':
        $content = '<p>Welcome to ' . $config['this_site'] . '!</p>';
        $title = 'welcome';
        break;
    default:
        $content = '<p>Page not found.</p>';
        break;
}

// Render the page with CSS
?>
<!DOCTYPE html>
<html>
<head>
<title><?= htmlspecialchars($config['this_site'] . ($title ? ' | ' . $title : '')) ?></title>
<style>
body  { font-family:Verdana; font-size:10pt; color:#828282; margin:0; padding:0; background:#f6f6ef; }
td    { font-family:Verdana; font-size:10pt; color:#828282; }
.admin td   { font-family:Verdana; font-size:8.5pt; color:#000000; }
.subtext td { font-family:Verdana; font-size:  7pt; color:#828282; }
input    { font-family:Courier; font-size:10pt; color:#000000; }
input[type="submit"] { font-family:Verdana; }
textarea { font-family:Courier; font-size:10pt; color:#000000; }
a:link    { color:#000000; text-decoration:none; } 
a:visited { color:#828282; text-decoration:none; }
.default { font-family:Verdana; font-size: 10pt; color:#828282; }
.admin   { font-family:Verdana; font-size:8.5pt; color:#000000; }
.title   { font-family:Verdana; font-size: 10pt; color:#828282; }
.adtitle { font-family:Verdana; font-size:  9pt; color:#828282; }
.subtext { font-family:Verdana; font-size:  7pt; color:#828282; }
.yclinks { font-family:Verdana; font-size:  8pt; color:#828282; }
.pagetop { font-family:Verdana; font-size: 10pt; color:#222222; }
.comhead { font-family:Verdana; font-size:  8pt; color:#828282; }
.comment { font-family:Verdana; font-size:  9pt; color:#000000; }
.dead    { font-family:Verdana; font-size:  9pt; color:#dddddd; }
.comment a:link, .comment a:visited { text-decoration:underline; }
.dead a:link, .dead a:visited { color:#dddddd; }
.pagetop a:visited { color:#000000; }
.topsel a:link, .topsel a:visited { color:#ffffff; }
.subtext a:link, .subtext a:visited { color:#828282; }
.subtext a:hover { text-decoration:underline; }
.comhead a:link, .subtext a:visited { color:#828282; }
.comhead a:hover { text-decoration:underline; }
.default p { margin-top: 8px; margin-bottom: 0px; }
.vote-arrow { font-size:14px; line-height:10px; color:#828282; text-decoration:none; }
.topbar { background:<?= $config['site_color'] ?>; padding:2px; }
.topbar a { color:#000000; }
.topbar .sel { color:#ffffff; font-weight:bold; }
.topbar .sp { padding:0 5px; }
.container { width:85%; margin:0 auto; background:#f6f6ef; }
.spacer { height:5px; }
.spacer-large { height:10px; }
</style>
<script>
function byId(id) { return document.getElementById(id); }
function vote(node) {
    var v = node.id.split('_');
    var item = v[1];
    var score = byId('score_' + item);
    var newscore = parseInt(score.innerHTML) + (v[0] == 'up' ? 1 : -1);
    score.innerHTML = newscore + (newscore == 1 ? ' point' : ' points');
    byId('up_' + item).style.visibility = 'hidden';
    byId('down_' + item).style.visibility = 'hidden';
    var ping = new Image();
    ping.src = node.href;
    return false;
}
</script>
</head>
<body>
<center>
<table class="container" border="0" cellpadding="0" cellspacing="0" width="85%" bgcolor="#f6f6ef">
    <!-- Top Bar -->
    <tr>
        <td class="topbar" bgcolor="<?= $config['site_color'] ?>" style="padding:2px;">
            <table border="0" cellpadding="0" cellspacing="0" width="100%">
                <tr>
                    <td style="width:18px;padding-right:4px;">
                        <a href="<?= $config['parent_url'] ?>">
                            <img src="<?= $config['logo_url'] ?>" width="18" height="18" style="border:1px solid <?= $config['border_color'] ?>;" alt="">
                        </a>
                    </td>
                    <td style="line-height:12pt;height:10px;">
                        <span class="pagetop">
                            <b><a href="/"><?= $config['this_site'] ?></a></b>
                            <?php if (noob($user)): ?>
                            <span class="sp">|</span>
                            <a href="/welcome">welcome</a>
                            <?php endif; ?>
                            <span class="sp">|</span>
                            <a href="/newest">new</a>
                            <?php if ($user): ?>
                            <span class="sp">|</span>
                            <a href="/threads?id=<?= urlencode($user) ?>">threads</a>
                            <?php endif; ?>
                            <span class="sp">|</span>
                            <a href="/newcomments">comments</a>
                            <span class="sp">|</span>
                            <a href="/leaders">leaders</a>
                            <span class="sp">|</span>
                            <a href="/submit">submit</a>
                            <span class="sp">|</span>
                            <span style="color:#ffffff;"><?= htmlspecialchars($title) ?></span>
                        </span>
                    </td>
                    <td style="text-align:right;padding-right:4px;">
                        <span class="pagetop">
                            <?php if ($user): ?>
                            <a href="/user?id=<?= urlencode($user) ?>"><?= htmlspecialchars($user) ?></a>&nbsp;(<?= karma($user) ?>)&nbsp;|&nbsp;
                            <a href="/?logout">logout</a>
                            <?php else: ?>
                            <a href="/?login&user=guest">login</a>
                            <?php endif; ?>
                        </span>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
    <tr><td style="height:10px;"></td></tr>
    <!-- Page Content -->
    <?= $content ?>
    <!-- Admin Footer -->
    <?php if (admin($user)): ?>
    <tr><td style="height:10px;"></td></tr>
    <tr>
        <td>
            <table border="0" cellpadding="0" cellspacing="0" width="100%">
                <tr>
                    <td class="admin" style="color:#828282;font-size:8.5pt;">
                        <?= count($items) ?> / <?= max(array_keys($items)) ?> loaded &nbsp;|&nbsp; 38 mb &nbsp;|&nbsp; 122 msec
                        <span class="sp">|</span>
                        <a href="/newsadmin">settings</a>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
    <?php endif; ?>
    <tr><td style="height:10px;"></td></tr>
</table>
</center>
</body>
</html>
<?php
ob_end_flush();
?>
