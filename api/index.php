<?php
/**
 * 作业清单 · 班级作业共享接口（PHP 版）
 *
 * 给虚拟主机 / 共享主机用，不需要 Node、不需要数据库。
 *
 * 部署三步：
 *   1. 把 api/ 和 index.html 等文件一起传到网站根目录
 *   2. 设置管理员密码：环境变量 CLASS_PASSWORD，或 data/secret.php（见下方说明）
 *   3. 确认 data/ 目录可写（755 或 777，程序会自动建）
 *
 * 接口：
 *   GET  api/?action=ping      自检，返回 {"ok":true,"backend":"php"}
 *   GET  api/?action=list      读取班级作业
 *   POST api/?action=verify    校验密码 {"password":"..."}
 *   POST api/?action=add       发布作业 {"password":"...","item":{...}}
 *   POST api/?action=delete    删除作业 {"password":"...","id":"..."}
 *   POST api/?action=feedback      提交反馈 {"name":"...","text":"...","token":"..."}（免密码，带 token 会记归属）
 *   POST api/?action=feedback-list 读取反馈列表（管理员看全部，登录用户只看自己的）
 *   POST api/?action=feedback-reply 回复反馈 {"id":"...","text":"..."}（管理员任意，用户只能自己的）
 *   POST api/?action=register      注册 {"name":"...","password":"..."}
 *   POST api/?action=login         登录 {"name":"...","password":"..."}
 *   POST api/?action=me            当前账号 {"token":"..."}
 *   POST api/?action=my-data       读取自己的作业 {"token":"..."}
 *   POST api/?action=my-data-save  保存自己的作业 {"token":"...","items":[...]}
 *   POST api/?action=logout        退出登录 {"token":"..."}
 */

/* ====== 管理员密码 ======
 * 仓库里只放占位符，真实密码不要写进代码，否则公开后谁都能看到。
 * 读取顺序（前面的优先）：
 *   1. 环境变量 CLASS_PASSWORD
 *   2. data/secret.php  内容是：<?php return '你的密码';
 *   3. 下面的占位符 —— 没配置时密码校验一律不通过，这是有意的
 */
$PASSWORD = 'CHANGE_ME';

$SECRET_FILE = dirname(__DIR__) . '/data/secret.php';
if (is_file($SECRET_FILE)) {
    $secret = include $SECRET_FILE;
    if (is_string($secret) && $secret !== '') {
        $PASSWORD = $secret;
    } elseif (is_array($secret) && !empty($secret['password'])) {
        $PASSWORD = (string) $secret['password'];
    }
}
$env_password = getenv('CLASS_PASSWORD');
if (is_string($env_password) && $env_password !== '') $PASSWORD = $env_password;

// 还是占位符，说明站长没设置密码，后台相关接口全部拒绝
$ADMIN_READY = ($PASSWORD !== '' && $PASSWORD !== 'CHANGE_ME');
/* ========================== */

$DATA_DIR  = dirname(__DIR__) . '/data';
$DATA_FILE = $DATA_DIR . '/class-homework.json';
$NOTICE_FILE = $DATA_DIR . '/notice.json';
$FEEDBACK_FILE = $DATA_DIR . '/feedback.json';
$USERS_FILE = $DATA_DIR . '/users.json';
// 登录态单独存一个文件：保存作业时不会再把别人刚登录的 token 覆盖掉
$SESSIONS_FILE = $DATA_DIR . '/sessions.json';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

/* ---------- 小工具 ---------- */

function send_json($code, $payload) {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

// 按字符截断，避免把中文切坏
function clip($value, $max) {
    if (!is_string($value)) return '';
    $value = trim($value);
    if ($value === '') return '';
    if (function_exists('mb_substr')) return mb_substr($value, 0, $max, 'UTF-8');
    // 没有 mbstring 时也不能用 substr，会把汉字切成半个，导致 json_encode 直接失败
    if (preg_match_all('/./us', $value, $chars)) {
        return implode('', array_slice($chars[0], 0, $max));
    }
    return '';
}

function now_ms() {
    return (int) round(microtime(true) * 1000);
}

// 反馈 id：优先用随机数，老 PHP 用 uniqid 兜底
function new_feedback_id() {
    if (function_exists('random_bytes')) {
        return 'fb-' . bin2hex(random_bytes(6));
    }
    return 'fb-' . str_replace('.', '', uniqid('', true));
}

/* ---------- 数据读写 ---------- */

// 班级作业、反馈、账号、登录态都是 {"v":1,"items":[...]} 的形状，
// 读写的差别只有文件名，收敛成一组函数，避免四份复制粘贴
function read_list($file) {
    if (!is_file($file)) return array();
    $raw = @file_get_contents($file);
    if ($raw === false || $raw === '') return array();
    $data = json_decode($raw, true);
    if (!is_array($data)) return array();
    return isset($data['items']) && is_array($data['items']) ? array_values($data['items']) : array();
}

function data_dir_ready($dir) {
    static $ready = false;
    if ($ready) return;
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    // 顺手放一份 .htaccess，Apache 下别人就不能直接翻 data 目录
    $htaccess = $dir . '/.htaccess';
    if (!is_file($htaccess)) {
        @file_put_contents(
            $htaccess,
            "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n" .
            "<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n"
        );
    }
    $ready = true;
}

function write_json_file($file, $dir, $payload) {
    data_dir_ready($dir);
    $flags = JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    $body = json_encode($payload, $flags);
    if ($body === false) return false;

    $tmp = $file . '.tmp';
    if (@file_put_contents($tmp, $body, LOCK_EX) === false) return false;
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

function write_list($file, $dir, $items) {
    return write_json_file($file, $dir, array(
        'v'         => 1,
        'updatedAt' => now_ms(),
        'items'     => array_values($items)
    ));
}

function new_token() {
    if (function_exists('random_bytes')) return bin2hex(random_bytes(16));
    return md5(uniqid('tok', true));
}

/* ---------- 登录态（独立文件，带文件锁） ---------- */

// 同一个文件的「读 → 改 → 写」必须串行，否则并发请求会互相覆盖
function with_lock($file, $callback) {
    $handle = @fopen($file . '.lock', 'c');
    if (!$handle) return $callback();          // 锁文件建不了也不能让功能整个挂掉
    @flock($handle, LOCK_EX);
    try {
        $result = $callback();
    } finally {
        @flock($handle, LOCK_UN);
        @fclose($handle);
    }
    return $result;
}

// 一个账号最多保留多少个设备的登录态。之前是 5，多设备轮流登录会把旧设备踢掉
function max_sessions_per_user() {
    return 20;
}

function save_session($file, $dir, $token, $userId) {
    return with_lock($file, function () use ($file, $dir, $token, $userId) {
        $list = read_list($file);
        $clean = array();
        foreach ($list as $item) {
            if (!isset($item['token']) || $item['token'] === $token) continue;
            $clean[] = $item;
        }
        $clean[] = array('token' => $token, 'userId' => $userId, 'at' => now_ms());

        // 只对这个账号的登录态做数量限制，别的账号原样保留
        $mine = array();
        $others = array();
        foreach ($clean as $item) {
            if (isset($item['userId']) && $item['userId'] === $userId) $mine[] = $item;
            else $others[] = $item;
        }
        usort($mine, function ($a, $b) {
            return (isset($b['at']) ? (int) $b['at'] : 0) - (isset($a['at']) ? (int) $a['at'] : 0);
        });
        $mine = array_slice($mine, 0, max_sessions_per_user());
        return write_list($file, $dir, array_merge($others, $mine));
    });
}

function drop_session($file, $dir, $token) {
    return with_lock($file, function () use ($file, $dir, $token) {
        $list = read_list($file);
        $keep = array();
        foreach ($list as $item) {
            if (isset($item['token']) && $item['token'] === $token) continue;
            $keep[] = $item;
        }
        return write_list($file, $dir, $keep);
    });
}

// 老版本把 tokens 存在 users.json 里，这里顺手迁移到 sessions.json
function find_user_by_token($users, $token) {
    global $SESSIONS_FILE, $DATA_DIR;
    if (!is_string($token) || $token === '') return null;

    foreach (read_list($SESSIONS_FILE) as $item) {
        if (!isset($item['token'], $item['userId']) || $item['token'] !== $token) continue;
        foreach ($users as $user) {
            if (isset($user['id']) && $user['id'] === $item['userId']) return $user;
        }
    }
    // 兼容旧数据
    foreach ($users as $user) {
        if (!isset($user['tokens']) || !is_array($user['tokens'])) continue;
        if (in_array($token, $user['tokens'], true)) {
            if (isset($user['id'])) save_session($SESSIONS_FILE, $DATA_DIR, $token, $user['id']);
            return $user;
        }
    }
    return null;
}

function user_public($user) {
    return array(
        'id'        => isset($user['id']) ? $user['id'] : '',
        'name'      => isset($user['name']) ? $user['name'] : '',
        'createdAt' => isset($user['createdAt']) ? (int) $user['createdAt'] : 0
    );
}

/* ---------- 通知 ---------- */

function read_notice($file) {
    if (!is_file($file)) return '';
    $raw = @file_get_contents($file);
    if ($raw === false || $raw === '') return '';
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['text'])) return '';
    return is_string($data['text']) ? $data['text'] : '';
}

function write_notice($file, $dir, $text) {
    return write_json_file($file, $dir, array('v' => 1, 'text' => $text, 'updatedAt' => now_ms()));
}

/* ---------- 字段清洗 ---------- */

// 个人作业比班级作业多一个勾选状态，单独补上
function sanitize_my_item($raw) {
    if (!is_array($raw)) return null;
    $item = sanitize_item($raw);
    if (!$item) return null;
    $done = array();
    for ($i = 0; $i < $item['parts']; $i++) {
        $done[] = isset($raw['done'][$i]) ? (bool) $raw['done'][$i] : false;
    }
    $item['done'] = $done;
    return $item;
}

function sanitize_item($raw) {
    if (!is_array($raw)) return null;

    $id      = clip(isset($raw['id']) ? $raw['id'] : '', 64);
    $subject = clip(isset($raw['subject']) ? $raw['subject'] : '', 8);
    $type    = clip(isset($raw['type']) ? $raw['type'] : '', 12);
    $text    = clip(isset($raw['text']) ? $raw['text'] : '', 40);
    $date    = clip(isset($raw['date']) ? $raw['date'] : '', 10);
    $parts   = isset($raw['parts']) ? (int) $raw['parts'] : 1;

    if ($parts < 1)  $parts = 1;
    if ($parts > 20) $parts = 20;
    if ($id === '' || $subject === '' || $type === '') return null;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return null;

    $createdAt = isset($raw['createdAt']) ? (float) $raw['createdAt'] : 0;
    if ($createdAt <= 0) $createdAt = now_ms();

    return array(
        'id'        => $id,
        'subject'   => $subject,
        'type'      => $type,
        'text'      => $text,
        'date'      => $date,
        'parts'     => $parts,
        'createdAt' => $createdAt
    );
}

/* ---------- 反馈归属 ---------- */

// 这条反馈是不是这个账号提交的
function feedback_belongs_to($fb, $user) {
    if (!$user) return false;
    $uid = isset($user['id']) ? $user['id'] : '';
    if (isset($fb['ownerId']) && $fb['ownerId'] !== '') {
        return $uid !== '' && $fb['ownerId'] === $uid;
    }
    // 老反馈没有 ownerId，只能按提交时留的名字兜底
    $uname = isset($user['name']) ? $user['name'] : '';
    return isset($fb['ownerName']) && $fb['ownerName'] !== '' && $fb['ownerName'] === $uname;
}

/* ---------- 路由 ---------- */

$action = isset($_GET['action']) ? $_GET['action'] : '';
$method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';

$body = array();
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $parsed = json_decode($raw, true);
    if (is_array($parsed)) $body = $parsed;
}

if ($action === '') {
    send_json(400, array('ok' => false, 'error' => '缺少 action 参数'));
}

// 自检
if ($action === 'ping') {
    send_json(200, array(
        'ok'      => true,
        'backend' => 'php',
        'version' => PHP_VERSION,
        'writable' => is_dir($DATA_DIR) ? is_writable($DATA_DIR) : is_writable(dirname(__DIR__))
    ));
}

// 读取（公开，谁都能看）
if ($action === 'list') {
    if ($method !== 'GET' && $method !== 'HEAD') {
        send_json(405, array('ok' => false, 'error' => '只支持 GET'));
    }
    send_json(200, array('ok' => true, 'items' => read_list($DATA_FILE)));
}

// 通知：所有人可读
if ($action === 'notice' && ($method === 'GET' || $method === 'HEAD')) {
    send_json(200, array('ok' => true, 'text' => read_notice($NOTICE_FILE)));
}

// 意见反馈：任何人都能提交，不用密码；登录用户会记下归属
if ($action === 'feedback') {
    if ($method !== 'POST') send_json(405, array('ok' => false, 'error' => '只支持 POST'));
    $name = clip(isset($body['name']) ? $body['name'] : '', 20);
    $text = clip(isset($body['text']) ? $body['text'] : '', 500);
    if ($text === '') send_json(400, array('ok' => false, 'error' => '反馈内容不能为空'));

    $fbToken = isset($body['token']) && is_string($body['token']) ? $body['token'] : '';
    $fbUser = $fbToken !== '' ? find_user_by_token(read_list($USERS_FILE), $fbToken) : null;
    if ($fbUser && $name === '') $name = isset($fbUser['name']) ? $fbUser['name'] : '';

    $entry = array(
        'id'   => new_feedback_id(),
        'name' => $name,
        'text' => $text,
        'at'   => now_ms()
    );
    if ($fbUser) {
        $entry['ownerId'] = isset($fbUser['id']) ? $fbUser['id'] : '';
        $entry['ownerName'] = isset($fbUser['name']) ? $fbUser['name'] : '';
    }

    $list = with_lock($FEEDBACK_FILE, function () use ($FEEDBACK_FILE, $DATA_DIR, $entry) {
        $list = read_list($FEEDBACK_FILE);
        $list[] = $entry;
        // 只留最近 300 条，避免文件无限变大
        if (count($list) > 300) $list = array_slice($list, -300);
        return write_list($FEEDBACK_FILE, $DATA_DIR, $list) ? $list : null;
    });
    if ($list === null) {
        send_json(500, array('ok' => false, 'error' => '保存失败，请确认 data 目录可写'));
    }
    send_json(200, array('ok' => true));
}

// 下面是需要密码的接口
$given  = isset($body['password']) ? $body['password'] : '';
$passed = $ADMIN_READY && is_string($given) && $given !== '' && hash_equals($PASSWORD, $given);

if ($action === 'verify') {
    if (!$ADMIN_READY) send_json(503, array('ok' => false, 'error' => '服务器还没设置管理员密码，请在 data/secret.php 里设置'));
    if (!$passed) send_json(403, array('ok' => false, 'error' => '密码不对'));
    send_json(200, array('ok' => true));
}

/* ---------- 注册 / 登录（公开） ---------- */

if ($action === 'register' || $action === 'login') {
    if ($method !== 'POST') send_json(405, array('ok' => false, 'error' => '只支持 POST'));

    $name = clip(isset($body['name']) ? $body['name'] : '', 16);
    $password = isset($body['password']) && is_string($body['password']) ? $body['password'] : '';
    if (strlen($name) < 2) send_json(400, array('ok' => false, 'error' => '用户名至少 2 个字'));
    if (preg_match('/^[\x{4e00}-\x{9fa5}A-Za-z0-9_]+$/u', $name) !== 1) {
        send_json(400, array('ok' => false, 'error' => '用户名只能用中文、字母、数字、下划线'));
    }
    if (strlen($password) < 4 || strlen($password) > 32) {
        send_json(400, array('ok' => false, 'error' => '密码需要 4–32 位'));
    }

    $users = read_list($USERS_FILE);
    $idx = -1;
    foreach ($users as $i => $user) {
        if (isset($user['name']) && $user['name'] !== '' && strcasecmp($user['name'], $name) === 0) {
            $idx = $i;
            break;
        }
    }

    if ($action === 'register') {
        if ($idx >= 0) send_json(409, array('ok' => false, 'error' => '这个用户名已经被注册了'));
        $token = new_token();
        $newId = 'u-' . substr(new_token(), 0, 12);
        $createdAt = now_ms();
        $users[] = array(
            'id'        => $newId,
            'name'      => $name,
            'hash'      => password_hash($password, PASSWORD_DEFAULT),
            'createdAt' => $createdAt,
            'data'      => array('updatedAt' => 0, 'items' => array())
        );
        if (!write_list($USERS_FILE, $DATA_DIR, $users)) {
            send_json(500, array('ok' => false, 'error' => '保存失败，请确认 data 目录可写'));
        }
        save_session($SESSIONS_FILE, $DATA_DIR, $token, $newId);
        send_json(200, array(
            'ok'    => true,
            'token' => $token,
            'user'  => array('id' => $newId, 'name' => $name, 'createdAt' => $createdAt)
        ));
    }

    if ($idx < 0 || !password_verify($password, isset($users[$idx]['hash']) ? $users[$idx]['hash'] : '')) {
        send_json(403, array('ok' => false, 'error' => '用户名或密码不对'));
    }
    // 登录只写 sessions.json，不再重写 users.json，
    // 免得和另一台设备的「保存作业」撞车，把刚发出的 token 覆盖掉
    $token = new_token();
    save_session($SESSIONS_FILE, $DATA_DIR, $token, $users[$idx]['id']);
    send_json(200, array('ok' => true, 'token' => $token, 'user' => user_public($users[$idx])));
}

/* ---------- 登录用户：个人数据 ---------- */

$token = isset($body['token']) && is_string($body['token']) ? $body['token'] : '';

// 只有登录态相关的接口才需要读账号表；发布/删除班级作业、改通知这些不用白读一遍
$userActions = array('me', 'my-data', 'my-data-save', 'logout', 'feedback-list', 'feedback-reply');
$currentUser = in_array($action, $userActions, true)
    ? find_user_by_token(read_list($USERS_FILE), $token)
    : null;

if ($action === 'me' || $action === 'my-data' || $action === 'my-data-save' || $action === 'logout') {
    if ($method !== 'POST') send_json(405, array('ok' => false, 'error' => '只支持 POST'));
    if (!$currentUser) send_json(401, array('ok' => false, 'error' => '登录已过期，请重新登录'));

    if ($action === 'me') {
        send_json(200, array('ok' => true, 'user' => user_public($currentUser)));
    }

    if ($action === 'logout') {
        drop_session($SESSIONS_FILE, $DATA_DIR, $token);
        send_json(200, array('ok' => true));
    }

    if ($action === 'my-data') {
        $data = isset($currentUser['data']) && is_array($currentUser['data']) ? $currentUser['data'] : array();
        $items = isset($data['items']) && is_array($data['items']) ? array_values($data['items']) : array();
        send_json(200, array('ok' => true, 'items' => $items));
    }

    // my-data-save
    $rawItems = isset($body['items']) && is_array($body['items']) ? $body['items'] : array();
    $clean = array();
    foreach ($rawItems as $raw) {
        $item = sanitize_my_item($raw);
        if ($item) $clean[] = $item;
    }
    if (count($clean) > 500) $clean = array_slice($clean, -500);
    // 锁内重新读一次再写，避免和另一台设备的登录/保存互相覆盖
    $saved = with_lock($USERS_FILE, function () use ($USERS_FILE, $DATA_DIR, $currentUser, $clean) {
        $fresh = read_list($USERS_FILE);
        $hit = false;
        foreach ($fresh as $i => $user) {
            if (isset($user['id']) && $user['id'] === $currentUser['id']) {
                $fresh[$i]['data'] = array('updatedAt' => now_ms(), 'items' => $clean);
                $hit = true;
                break;
            }
        }
        if (!$hit) return false;
        return write_list($USERS_FILE, $DATA_DIR, $fresh);
    });
    if (!$saved) {
        send_json(500, array('ok' => false, 'error' => '保存失败，请确认 data 目录可写'));
    }
    send_json(200, array('ok' => true, 'items' => $clean));
}

/* ---------- 反馈列表 / 回复：管理员看全部，登录用户只看自己的 ---------- */

if ($action === 'feedback-list' || $action === 'feedback-reply') {
    if ($method !== 'POST') send_json(405, array('ok' => false, 'error' => '只支持 POST'));
    if (!$passed && !$currentUser) {
        send_json(403, array('ok' => false, 'error' => '请先登录，或输入管理员密码'));
    }

    if ($action === 'feedback-list') {
        $items = read_list($FEEDBACK_FILE);
        // 管理员看全部；普通账号只看自己提交的（归属规则和回复权限共用同一个函数）
        if (!$passed) {
            $items = array_values(array_filter($items, function ($fb) use ($currentUser) {
                return feedback_belongs_to($fb, $currentUser);
            }));
        }
        usort($items, function ($a, $b) {
            $left  = isset($a['at']) ? (int) $a['at'] : 0;
            $right = isset($b['at']) ? (int) $b['at'] : 0;
            return $right - $left;
        });
        send_json(200, array('ok' => true, 'scope' => $passed ? 'all' : 'mine', 'items' => $items));
    }

    $id = isset($body['id']) && is_string($body['id']) ? $body['id'] : '';
    $text = clip(isset($body['text']) ? $body['text'] : '', 300);
    if ($id === '' || $text === '') send_json(400, array('ok' => false, 'error' => '回复内容不能为空'));
    $by = $passed ? '管理员' : $currentUser['name'];

    $result = with_lock($FEEDBACK_FILE, function () use ($FEEDBACK_FILE, $DATA_DIR, $id, $text, $by, $passed, $currentUser) {
        $list = read_list($FEEDBACK_FILE);
        $found = false;
        foreach ($list as $i => $fb) {
            if (!isset($fb['id']) || $fb['id'] !== $id) continue;
            // 普通账号只能回复自己的反馈；管理员不受限制
            if (!$passed && !feedback_belongs_to($fb, $currentUser)) {
                return array('code' => 403, 'error' => '只能回复自己提交的反馈');
            }
            $replies = isset($fb['replies']) && is_array($fb['replies']) ? $fb['replies'] : array();
            $replies[] = array(
                'id'   => 'r-' . substr(new_token(), 0, 10),
                'text' => $text,
                'by'   => $by,
                'at'   => now_ms()
            );
            $list[$i]['replies'] = $replies;
            $found = true;
            break;
        }
        if (!$found) return array('code' => 404, 'error' => '这条反馈不存在');
        if (!write_list($FEEDBACK_FILE, $DATA_DIR, $list)) {
            return array('code' => 500, 'error' => '保存失败，请确认 data 目录可写');
        }
        return array('code' => 200, 'items' => $list);
    });

    if ($result['code'] !== 200) {
        send_json($result['code'], array('ok' => false, 'error' => $result['error']));
    }
    send_json(200, array('ok' => true, 'items' => $result['items']));
}

if (!$passed) {
    send_json(403, array('ok' => false, 'error' => '密码不对'));
}

if ($action === 'notice') {
    if ($method !== 'POST') send_json(405, array('ok' => false, 'error' => '只支持 POST'));
    $text = clip(isset($body['text']) ? $body['text'] : '', 200);
    if (!write_notice($NOTICE_FILE, $DATA_DIR, $text)) {
        send_json(500, array('ok' => false, 'error' => '保存失败，请确认 data 目录可写'));
    }
    send_json(200, array('ok' => true, 'text' => $text));
}

if ($action === 'add') {
    if ($method !== 'POST') send_json(405, array('ok' => false, 'error' => '只支持 POST'));
    $item = sanitize_item(isset($body['item']) ? $body['item'] : null);
    if (!$item) send_json(400, array('ok' => false, 'error' => '作业内容不完整'));

    $items = read_list($DATA_FILE);
    foreach ($items as $it) {
        if (isset($it['id']) && $it['id'] === $item['id']) {
            send_json(409, array('ok' => false, 'error' => '这份作业已经存在'));
        }
    }
    $items[] = $item;
    if (!write_list($DATA_FILE, $DATA_DIR, $items)) {
        send_json(500, array('ok' => false, 'error' => '保存失败，请确认 data 目录可写'));
    }
    send_json(200, array('ok' => true, 'items' => $items));
}

if ($action === 'delete') {
    if ($method !== 'POST') send_json(405, array('ok' => false, 'error' => '只支持 POST'));
    $id = isset($body['id']) && is_string($body['id']) ? $body['id'] : '';
    $items = array();
    foreach (read_list($DATA_FILE) as $it) {
        if (!isset($it['id']) || $it['id'] !== $id) $items[] = $it;
    }
    if (!write_list($DATA_FILE, $DATA_DIR, $items)) {
        send_json(500, array('ok' => false, 'error' => '保存失败，请确认 data 目录可写'));
    }
    send_json(200, array('ok' => true, 'items' => $items));
}

send_json(404, array('ok' => false, 'error' => '接口不存在'));
