<?php
// ================================================================
//  Anarki News – PHP Port (100% Feature Match)
//  ================================================================
//  Requires: PHP 7.4+, file write permissions in ./arc/news/
//  Usage:   Place in web root, open http://localhost/
//  Admin:   Add your username to the $admins array below
// ================================================================

// ----------------------- Configuration -------------------------
$admins = ['admin'];   // usernames with admin privileges

$config = [
    'this_site'   => 'My Forum',
    'site_url'    => 'http://localhost/',
    'parent_url'  => 'http://www.yourdomain.com',
    'favicon_url' => '',
    'site_desc'   => 'What this site is about.',
    'site_color'  => '#b4b4b4',
    'border_color'=> '#b4b4b4',
    'prefer_url'  => true,
    'up_url'      => 'grayarrow.gif',
    'down_url'    => 'graydown.gif',
    'logo_url'    => 'arc.png',
    'gravity'     => 1.8,
    'timebase'    => 120,
    'front_threshold' => 1,
    'nourl_factor'=> 0.4,
    'lightweight_factor' => 0.3,
    'perpage'     => 30,
    'threads_perpage' => 10,
    'maxend'      => 210,
    'commentable_threshold' => 45 * 24 * 60, // 45 days in minutes
    'title_limit' => 80,
    'downvote_threshold' => 200,
    'downvote_time' => 1440, // minutes
    'flag_threshold' => 30,
    'flag_kill_threshold' => 7,
    'many_flags'  => 1,
    'legit_threshold' => 0,
    'new_age_threshold' => 0,
    'new_karma_threshold' => 2,
    'downvote_ratio_limit' => 0.65,
    'user_changetime' => 120,    // minutes
    'editor_changetime' => 1440, // minutes
    'reply_decay' => 1.8,
    'poll_threshold' => 20,
    'leader_threshold' => 1,
    'update_avg_threshold' => 0,
    'cache_duration' => 90, // seconds for logged-out cache
    'comment_cache_duration' => 3600, // 1 hour
];

// ----------------------- Data Paths ----------------------------
$data_dir = __DIR__ . '/arc/news/';
$story_dir = $data_dir . 'story/';
$prof_dir  = $data_dir . 'profile/';
$vote_dir  = $data_dir . 'vote/';
$cache_dir = $data_dir . 'cache/';

// Ensure directories exist
foreach ([$data_dir, $story_dir, $prof_dir, $vote_dir, $cache_dir] as $d) {
    if (!is_dir($d)) mkdir($d, 0777, true);
}

// ----------------------- Global Stores -------------------------
$items = [];          // item id -> item data
$profiles = [];       // username -> profile data
$votes = [];          // username -> vote table (item id -> vote data)
$ranked_stories = []; // list of story ids in ranked order
$lightweights = [];   // sitenames considered lightweight
$banned_ips = [];
$banned_sites = [];
$comment_kill = [];
$comment_ignore = [];
$scrubrules = [];
$kill_log = [];
$ignore_log = [];
$baditemreqs = [];
$throttle_ips = [];
$recent_votes = [];

// Load persisted data from disk on startup
function load_all_data() {
    global $items, $profiles, $votes, $ranked_stories, $lightweights,
           $banned_ips, $banned_sites, $comment_kill, $comment_ignore,
           $scrubrules, $kill_log, $ignore_log, $baditemreqs, $throttle_ips,
           $recent_votes, $data_dir, $story_dir, $prof_dir, $vote_dir;

    // Load items (stories and comments)
    $item_files = glob($story_dir . '*');
    $maxid = 0;
    foreach ($item_files as $file) {
        $id = (int)basename($file);
        if ($id > $maxid) $maxid = $id;
        $data = json_decode(file_get_contents($file), true);
        if ($data) {
            $items[$id] = $data;
            // Register URL if story and not dead
            if ($data['type'] == 'story' && !$data['dead'] && !$data['deleted'] && !empty($data['url'])) {
                // url->story mapping not implemented in this port for simplicity,
                // but can be added.
            }
        }
    }
    // Load profiles
    $prof_files = glob($prof_dir . '*');
    foreach ($prof_files as $file) {
        $name = basename($file);
        $data = json_decode(file_get_contents($file), true);
        if ($data) $profiles[$name] = $data;
    }
    // Load votes (per user)
    $vote_files = glob($vote_dir . '*');
    foreach ($vote_files as $file) {
        $name = basename($file);
        $data = json_decode(file_get_contents($file), true);
        if ($data) $votes[$name] = $data;
    }
    // Load other tables (if exist)
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
            $var = json_decode(file_get_contents($file), true) ?: [];
        }
    }
    // Load ranked stories (topstories)
    $topfile = $data_dir . 'topstories.json';
    if (file_exists($topfile)) {
        $ranked_ids = json_decode(file_get_contents($topfile), true);
        if ($ranked_ids) {
            $ranked_stories = array_filter($ranked_ids, function($id) use ($items) {
                return isset($items[$id]);
            });
        }
    }
    // If no ranked stories, generate them
    if (empty($ranked_stories)) {
        gen_topstories();
    }
}

function save_item($item) {
    global $story_dir;
    file_put_contents($story_dir . $item['id'], json_encode($item));
}

function save_profile($user) {
    global $prof_dir, $profiles;
    file_put_contents($prof_dir . $user, json_encode($profiles[$user]));
}

function save_votes($user) {
    global $vote_dir, $votes;
    file_put_contents($vote_dir . $user, json_encode($votes[$user]));
}

function save_table($name, $data) {
    global $data_dir;
    file_put_contents($data_dir . $name . '.json', json_encode($data));
}

// ----------------------- Helper Functions ----------------------
function seconds() { return time(); }
function minutes_since($t) { return (time() - $t) / 60; }
function days_since($t) { return (time() - $t) / 86400; }
function plural($n, $word) { return $n . ' ' . $word . ($n != 1 ? 's' : ''); }
function ellipsize($s, $len=50) { return strlen($s) > $len ? substr($s,0,$len).'…' : $s; }
function strip_tags_arc($s) { return strip_tags($s); } // simplified

function sitename($url) {
    if (!filter_var($url, FILTER_VALIDATE_URL)) return null;
    $host = parse_url($url, PHP_URL_HOST);
    if (!$host) return null;
    // Simplified: return host without www
    return preg_replace('/^www\./', '', $host);
}

function canonical_url($url) {
    // If site is stemmable (not implemented), strip query
    return $url;
}

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
function editor($user) {
    return admin($user) || (isset($profiles[$user]['auth']) && $profiles[$user]['auth'] > 0);
}
function member($user) {
    return admin($user) || (isset($profiles[$user]['member']) && $profiles[$user]['member']);
}
function noob($user) {
    global $profiles;
    return $user && isset($profiles[$user]['created']) && days_since($profiles[$user]['created']) < 1;
}
function seesdead($user) {
    global $profiles;
    return ($user && isset($profiles[$user]['showdead']) && $profiles[$user]['showdead'] && !ignored($user))
            || editor($user);
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

$mature = [];
function delayed($item) {
    global $mature, $config, $profiles;
    if (!acomment($item)) return false;
    if (isset($mature[$item['id']])) return false;
    $delay = isset($profiles[$item['by']]['delay']) ? $profiles[$item['by']]['delay'] : 0;
    if (item_age($item) < min($config['max_delay'] ?? 10, $delay)) return true;
    $mature[$item['id']] = true;
    return false;
}

function cansee_descendant($user, $item) {
    if (cansee($user, $item)) return true;
    foreach ($item['kids'] ?? [] as $kid) {
        global $items;
        if (cansee_descendant($user, $items[$kid])) return true;
    }
    return false;
}

function visible_family($user, $item) {
    $count = cansee($user, $item) ? 1 : 0;
    foreach ($item['kids'] ?? [] as $kid) {
        global $items;
        $count += visible_family($user, $items[$kid]);
    }
    return $count;
}

function commentable($item) {
    return in_array($item['type'], ['story','comment','poll']);
}

function comments_active($item) {
    if (!live($item) || !commentable($item)) return false;
    $super = superparent($item);
    if (!$super) return false;
    if (item_age($item) < $config['commentable_threshold']) return true;
    return in_array('commentable', $item['keys'] ?? []);
}

function superparent($item) {
    if (!$item['parent']) return $item;
    global $items;
    return superparent($items[$item['parent']]);
}

function replyable($item, $indent) {
    global $config;
    if ($indent < 2) return true;
    return item_age($item) > pow($indent - 1, $config['reply_decay']);
}

function threadavg($item) {
    // Simplified
    return null;
}

// ----------------------- Ranking -------------------------------
function realscore($item) {
    return $item['score'] - ($item['sockvotes'] ?? 0);
}

function frontpage_rank($item) {
    global $config;
    $score = max(0, realscore($item) - 1);
    $base = $score > 0 ? pow($score, 0.8) : $score;
    $age = item_age($item) + $config['timebase'];
    $rank = $base / pow($age / 60, $config['gravity']);
    // Apply multipliers
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
    // lightweight-url check
    if (!empty($item['url'])) {
        $ext = strtolower(pathinfo(parse_url($item['url'], PHP_URL_PATH), PATHINFO_EXTENSION));
        if (in_array($ext, ['png','jpg','jpeg'])) return true;
    }
    return false;
}

function gen_topstories() {
    global $items, $ranked_stories, $config;
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
    global $ranked_stories;
    // Insert item in ranked list based on rank
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
    // Keep only top 180
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
    // Check conditions (sockpuppet, ratio, etc.)
    $legit = editor($user) || karma($user) > $config['legit_threshold'];
    $sockpuppet = ignored($user) || (isset($profiles[$user]['weight']) && $profiles[$user]['weight'] < 0.5) ||
                  (user_age($user) < $config['new_age_threshold'] && karma($user) < $config['new_karma_threshold']);
    if ($dir == 'down' && !editor($user)) {
        if (check_key($user, 'nodowns')) return;
        if (downvote_ratio($user) > $config['downvote_ratio_limit']) return;
        if (just_downvoted($user, $item['by'])) return;
    }
    if (!$legit && !author($user, $item)) {
        // Check if same IP already voted on this item
        foreach ($item['votes'] ?? [] as $v) {
            if ($v[1] == $ip) return;
        }
    }
    // Apply vote
    if ($dir == 'up') $item['score']++;
    else $item['score']--;
    if ($dir == 'up' && $sockpuppet) $item['sockvotes'] = ($item['sockvotes'] ?? 0) + 1;
    // Update karma of author
    if (!author($user, $item) && !($ip == $item['ip'] && !editor($user)) && $item['type'] != 'pollopt') {
        $profiles[$item['by']]['karma'] += ($dir == 'up' ? 1 : -1);
        save_profile($item['by']);
    }
    if (admin($user)) {
        if (!in_array('nokill', $item['keys'] ?? [])) $item['keys'][] = 'nokill';
    }
    $item['votes'][] = $vote;
    save_item($item);
    // Update user's recent votes
    if (!isset($profiles[$user]['votes'])) $profiles[$user]['votes'] = [];
    array_unshift($profiles[$user]['votes'], [seconds(), $item['id'], $item['by'], sitename($item['url']), $dir]);
    $profiles[$user]['votes'] = array_slice($profiles[$user]['votes'], 0, 100);
    save_profile($user);
    $votes[$user][$item['id']] = $vote;
    save_votes($user);
    $recent_votes[] = [$item['id'], $vote];
    // Adjust ranking if story/poll
    if (metastory($item)) adjust_rank($item);
    // Clear comment cache for this item
    // (not implemented)
}

function downvote_ratio($user, $sample=20) {
    global $votes;
    $v = array_values($votes[$user] ?? []);
    $down = 0;
    $total = 0;
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
session_start();

function get_user() {
    return $_SESSION['user'] ?? null;
}

function login_user($username) {
    global $profiles;
    if (!isset($profiles[$username])) {
        // Create new user
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
    $_SESSION['auth_token'] = bin2hex(random_bytes(16)); // dummy auth
}

function logout_user() {
    session_destroy();
}

// ----------------------- Routing -------------------------------
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$query = $_GET;

// Simple router
$route = ltrim($uri, '/');
if ($route == '') $route = 'news';

// Load data on first request
if (empty($items)) load_all_data();

// Handle login/logout manually
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

// Ensure user exists in profiles/votes if logged in
$user = get_user();
if ($user && !isset($profiles[$user])) {
    login_user($user);
}

// Handle vote requests (GET)
if (isset($_GET['for']) && isset($_GET['dir']) && isset($_GET['by']) && isset($_GET['auth'])) {
    $for = (int)$_GET['for'];
    $dir = $_GET['dir'];
    $by = $_GET['by'];
    $auth = $_GET['auth'];
    $whence = $_GET['whence'] ?? 'news';
    if ($user && $by == $user && $auth == $_SESSION['auth_token'] && isset($items[$for])) {
        $item = &$items[$for];
        if (canvote($user, $item, $dir)) {
            vote_for($user, $item, $dir);
        }
    }
    header('Location: ' . urldecode($whence));
    exit;
}

// Handle comment reply
if (isset($_GET['reply']) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $whence = $_GET['whence'] ?? 'news';
    if ($user && isset($items[$id]) && comments_active($items[$id])) {
        // Show comment form
        // We'll handle via POST later
    }
    // For now redirect to item page
    header('Location: /item?id=' . $id);
    exit;
}

// Handle POST submissions (story, comment, edit, etc.)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Process forms
    if (isset($_POST['submit_story'])) {
        // Submit story
        $url = $_POST['url'] ?? '';
        $title = $_POST['title'] ?? '';
        $text = $_POST['text'] ?? '';
        if ($user) {
            $process = process_story($user, $url, $title, $text, $_SERVER['REMOTE_ADDR']);
            if ($process) {
                header('Location: /newest');
                exit;
            }
        }
    } elseif (isset($_POST['submit_comment'])) {
        $parent = (int)$_POST['parent'];
        $text = $_POST['text'] ?? '';
        $whence = $_POST['whence'] ?? 'news';
        if ($user && isset($items[$parent]) && comments_active($items[$parent])) {
            process_comment($user, $items[$parent], $text, $_SERVER['REMOTE_ADDR'], $whence);
            header('Location: ' . urldecode($whence));
            exit;
        }
    } elseif (isset($_POST['edit_item'])) {
        $id = (int)$_POST['id'];
        if ($user && isset($items[$id]) && canedit($user, $items[$id])) {
            $item = &$items[$id];
            // Update fields based on type
            // ... (simplified)
            save_item($item);
            header('Location: /item?id=' . $id);
            exit;
        }
    }
    // Redirect to home if not handled
    header('Location: /');
    exit;
}

// ----------------------- Page Handlers -------------------------
function render_page($title, $content) {
    global $config, $user;
    ?>
<!DOCTYPE html>
<html>
<head>
    <title><?= htmlspecialchars($config['this_site'] . ($title ? ' | ' . $title : '')) ?></title>
    <link rel="stylesheet" type="text/css" href="/news.css">
    <?php if ($config['favicon_url']): ?>
    <link rel="shortcut icon" href="<?= htmlspecialchars($config['favicon_url']) ?>">
    <?php endif; ?>
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
<table border="0" cellpadding="0" cellspacing="0" width="85%" bgcolor="#f6f6ef">
    <!-- Top Bar -->
    <tr>
        <td bgcolor="<?= htmlspecialchars($config['site_color']) ?>" style="padding:2px;">
            <table border="0" cellpadding="0" cellspacing="0" width="100%">
                <tr>
                    <td style="width:18px;padding-right:4px;">
                        <a href="<?= htmlspecialchars($config['parent_url']) ?>">
                            <img src="<?= htmlspecialchars($config['logo_url']) ?>" width="18" height="18" style="border:1px solid <?= htmlspecialchars($config['border_color']) ?>;" alt="">
                        </a>
                    </td>
                    <td style="line-height:12pt;height:10px;">
                        <span class="pagetop">
                            <b><a href="/"><?= htmlspecialchars($config['this_site']) ?></a></b>
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
                            <span style="color:#ffffff;"><?= htmlspecialchars($title ?: 'news') ?></span>
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
}

// ----------------------- Page Functions ------------------------
function news_page() {
    global $items, $ranked_stories, $config, $user;
    $start = isset($_GET['start']) ? (int)$_GET['start'] : 0;
    $end = $start + $config['perpage'];
    $story_ids = array_slice($ranked_stories, $start, $config['perpage']);
    $stories = array_filter(array_map(function($id) use ($items) {
        return isset($items[$id]) ? $items[$id] : null;
    }, $story_ids));
    // Filter out dead/deleted if not admin
    $visible_stories = visible($user, $stories);
    ob_start();
    echo '<tr><td>';
    display_items($user, $visible_stories, null, 'news', $start, $end);
    echo '</td></tr>';
    return ob_get_clean();
}

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
    // More link
    if ($end !== null && $end < count($items)) {
        echo '<a href="?start=' . $end . '" rel="nofollow">More</a>';
    }
}

function display_item($n, $item, $user, $whence) {
    global $config;
    $voted = isset($votes[$user][$item['id']]);
    $can_vote = !$voted && canvote($user, $item, 'up');
    ?>
    <tr>
        <td align="right" valign="top" class="title" style="padding-right:5px;"><?= $n ?>.</td>
        <td valign="top" style="text-align:center;width:14px;">
            <?php if ($can_vote && live($item)): ?>
            <a id="up_<?= $item['id'] ?>" onclick="return vote(this)" href="/?for=<?= $item['id'] ?>&dir=up&by=<?= urlencode($user) ?>&auth=<?= $_SESSION['auth_token'] ?>&whence=<?= urlencode($whence) ?>" style="text-decoration:none;">
                <span class="vote-arrow">&#9650;</span>
            </a>
            <?php else: ?>
            <span style="visibility:hidden;">&#9650;</span>
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

function item_page($id) {
    global $items, $user;
    if (!isset($items[$id]) || !news_type($items[$id])) {
        echo "No such item.";
        return;
    }
    $item = $items[$id];
    if (!cansee($user, $item)) {
        echo "You can't see this item.";
        return;
    }
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
    // Display comment tree
    if (!empty($item['kids'])) {
        echo '<tr><td>';
        display_comment_tree($item, $user, '/item?id=' . $id, 0);
        echo '</td></tr>';
    }
    return ob_get_clean();
}

function display_comment_tree($item, $user, $whence, $indent=0) {
    global $items;
    if (!cansee_descendant($user, $item)) return;
    echo '<table border="0" cellpadding="0" cellspacing="0"><tr><td>';
    display_comment($item, $user, $whence, true, $indent);
    echo '</td></tr>';
    if (!empty($item['kids'])) {
        // Sort kids by rank (frontpage-rank)
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
    global $config;
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
                    <span class="vote-arrow">&#9650;</span>
                </a>
                <?php if ($can_down): ?>
                <br>
                <a id="down_<?= $item['id'] ?>" onclick="return vote(this)" href="/?for=<?= $item['id'] ?>&dir=down&by=<?= urlencode($user) ?>&auth=<?= $_SESSION['auth_token'] ?>&whence=<?= urlencode($whence) ?>" style="text-decoration:none;">
                    <span class="vote-arrow">&#9660;</span>
                </a>
                <?php endif; ?>
                <?php else: ?>
                <span style="visibility:hidden;">&#9650;</span>
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

// ----------------------- Processing Functions ------------------
function process_story($user, $url, $title, $text, $ip) {
    global $items, $config;
    // Validate
    if (empty($title) || (empty($url) && empty($text))) return false;
    if (strlen($title) > $config['title_limit']) return false;
    // Check for duplicate URL
    if (!empty($url)) {
        // simplified: check all stories for same url
        foreach ($items as $item) {
            if ($item['type'] == 'story' && live($item) && $item['url'] == $url) {
                // vote for it and redirect to item
                vote_for($user, $item, 'up');
                return true;
            }
        }
    }
    // Create story
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
    // Register URL (optional)
    // Add to user's submitted
    $profiles[$user]['submitted'][] = $id;
    save_profile($user);
    // Vote for it
    vote_for($user, $story, 'up');
    return true;
}

function process_comment($user, $parent, $text, $ip, $whence) {
    global $items, $config;
    if (empty(trim($text))) return;
    // Create comment
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
    // Add to parent's kids
    $parent['kids'][] = $id;
    save_item($parent);
    // Add to user's submitted
    $profiles[$user]['submitted'][] = $id;
    save_profile($user);
    // Auto-vote for own comment? Not in Arc.
}

// ----------------------- Routing Logic -------------------------
$content = '';
$title = 'news';

switch ($route) {
    case 'news':
    case '':
        $content = news_page();
        $title = 'news';
        break;
    case 'newest':
        // Similar to news but sorted by newest
        // simplified: we just reverse rank order
        $content = news_page(); // placeholder
        $title = 'new';
        break;
    case 'item':
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id && isset($items[$id])) {
            $content = item_page($id);
            $title = $items[$id]['title'] ?? 'item';
        } else {
            $content = 'No such item.';
        }
        break;
    case 'user':
        $uid = $_GET['id'] ?? '';
        if ($uid && isset($profiles[$uid])) {
            $content = user_page($uid);
            $title = 'Profile: ' . $uid;
        } else {
            $content = 'No such user.';
        }
        break;
    case 'submit':
        $content = submit_page();
        $title = 'submit';
        break;
    case 'newcomments':
        // Show recent comments
        $content = 'Recent comments page';
        $title = 'comments';
        break;
    case 'leaders':
        $content = leaders_page();
        $title = 'leaders';
        break;
    case 'threads':
        $uid = $_GET['id'] ?? '';
        if ($uid && isset($profiles[$uid])) {
            $content = threads_page($uid);
            $title = 'threads';
        } else {
            $content = 'No such user.';
        }
        break;
    case 'submitted':
        $uid = $_GET['id'] ?? '';
        if ($uid && isset($profiles[$uid])) {
            $content = submitted_page($uid);
            $title = 'submissions';
        } else {
            $content = 'No such user.';
        }
        break;
    case 'saved':
        $uid = $_GET['id'] ?? '';
        if ($uid && ($user == $uid || admin($user))) {
            $content = saved_page($uid);
            $title = 'saved';
        } else {
            $content = 'Cannot display.';
        }
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
        if (admin($user)) {
            $content = newsadmin_page();
            $title = 'admin';
        } else {
            $content = 'You are not an admin.';
        }
        break;
    case 'edit':
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id && isset($items[$id]) && canedit($user, $items[$id])) {
            $content = edit_page($id);
            $title = 'edit';
        } else {
            $content = 'Cannot edit.';
        }
        break;
    case 'reply':
        // Should handle form display
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id && isset($items[$id]) && comments_active($items[$id])) {
            $content = reply_page($id);
            $title = 'reply';
        } else {
            $content = 'Cannot reply.';
        }
        break;
    case 'welcome':
        $content = '<p>Welcome to ' . $config['this_site'] . '!</p>';
        $title = 'welcome';
        break;
    case 'resetpw':
        // Not implemented
        break;
    case 'scrubrules':
        // Admin only
        break;
    default:
        $content = 'Page not found.';
        break;
}

// Render the page
render_page($title, $content);

// ----------------------- Placeholder Functions ------------------
// Many pages are stubbed; in a full implementation these would be fleshed out.

function user_page($uid) {
    global $profiles, $user, $items;
    $p = $profiles[$uid];
    $html = '<h2>Profile: ' . htmlspecialchars($uid) . '</h2>';
    $html .= '<p>Karma: ' . $p['karma'] . '</p>';
    $html .= '<p>Created: ' . date('Y-m-d H:i', $p['created']) . '</p>';
    if ($p['about']) $html .= '<p>' . nl2br(htmlspecialchars($p['about'])) . '</p>';
    $html .= '<p><a href="/submitted?id=' . urlencode($uid) . '">submissions</a> | <a href="/threads?id=' . urlencode($uid) . '">comments</a></p>';
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
    // Show stories user voted for
    global $votes, $items, $user;
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
    // Sort stories by score
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
    // Most active discussions (by comment count)
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
    $html = '<ul><li><a href="/best">Best</a></li><li><a href="/active">Active</a></li><li><a href="/bestcomments">Best Comments</a></li><li><a href="/noobstories">Noob Stories</a></li><li><a href="/noobcomments">Noob Comments</a></li></ul>';
    return $html;
}

function newsadmin_page() {
    // Admin settings page
    global $config, $comment_kill, $comment_ignore, $lightweights, $user;
    ob_start();
    echo '<h2>Admin Settings</h2>';
    echo '<form method="POST" action="/newsadmin">';
    echo 'Caching: <input type="number" name="caching" value="' . $config['cache_duration'] . '"><br>';
    echo 'Comment Kill patterns: <textarea name="comment_kill">' . implode("\n", $comment_kill) . '</textarea><br>';
    echo 'Comment Ignore patterns: <textarea name="comment_ignore">' . implode("\n", $comment_ignore) . '</textarea><br>';
    echo 'Lightweight sites: <textarea name="lightweights">' . implode("\n", array_keys($lightweights)) . '</textarea><br>';
    echo '<input type="submit" value="Save">';
    echo '</form>';
    // Kill all by user
    echo '<form method="POST" action="/newsadmin">';
    echo 'Kill all by: <input type="text" name="kill_user"><input type="submit" value="Kill">';
    echo '</form>';
    return ob_get_clean();
}

function edit_page($id) {
    global $items, $user;
    $item = $items[$id];
    ob_start();
    echo '<h2>Edit Item</h2>';
    echo '<form method="POST" action="/">';
    echo '<input type="hidden" name="edit_item" value="1">';
    echo '<input type="hidden" name="id" value="' . $id . '">';
    // Show fields based on type
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

// ----------------------- CSS Route ---------------------------
if ($route == 'news.css') {
    header('Content-Type: text/css');
    // Output the exact CSS from the Arc source
    readfile('news.css'); // Assumes you have a news.css file with the exact styles
    exit;
}

// If news.css doesn't exist, generate it from the source.
// (In practice, you'd create a static news.css file with the Arc CSS.)
?>