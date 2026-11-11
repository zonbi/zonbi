<?php
declare(strict_types=1);

$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    exit("lib/config.php がありません。lib/config.sample.php をコピーして DB 情報を設定してください。\n");
}
$GLOBALS['config'] = require $configFile;

date_default_timezone_set(config('timezone', 'Asia/Tokyo'));
mb_internal_encoding('UTF-8');

session_name('zonbi_sid');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'httponly' => true,
    'secure'   => is_https(),
    'samesite' => 'Lax',
]);
session_start();

require __DIR__ . '/views.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/moderation.php';
require __DIR__ . '/related.php';

// ---------------------------------------------------------------- 基本

function config(string $key, $default = null)
{
    return $GLOBALS['config'][$key] ?? $default;
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = config('db');
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $c['host'],
            (int)($c['port'] ?? 3306),
            $c['name']
        );
        $pdo = new PDO($dsn, $c['user'], $c['password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $pdo->exec("SET time_zone = '" . date('P') . "'");
    }
    return $pdo;
}

function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function yen($n): string
{
    return '¥' . number_format((int)$n);
}

function base_url(): string
{
    $configured = rtrim((string)config('base_url', ''), '/');
    if ($configured !== '') {
        return $configured;
    }
    $scheme = is_https() ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return $scheme . '://' . $host . $dir;
}

function url(string $path, array $params = []): string
{
    $q = $params ? '?' . http_build_query($params) : '';
    return base_url() . '/' . ltrim($path, '/') . $q;
}

function redirect(string $url): void
{
    header('Location: ' . $url, true, 303);
    exit;
}

function not_found(string $message = 'ページが見つかりません。'): void
{
    http_response_code(404);
    render_header('見つかりません');
    echo '<div class="card"><p>' . h($message) . '</p><p><a href="' . h(url('index.php')) . '">トップへ</a></p></div>';
    render_footer();
    exit;
}

function random_hex(int $bytes): string
{
    return bin2hex(random_bytes($bytes));
}

// ---------------------------------------------------------------- フラッシュ / CSRF

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = random_hex(32);
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

function csrf_check(): void
{
    $sent = (string)($_POST['csrf'] ?? '');
    if ($sent === '' || !hash_equals(csrf_token(), $sent)) {
        http_response_code(400);
        render_header('エラー');
        echo '<div class="card"><p>フォームの有効期限が切れました。ページを再読み込みしてやり直してください。</p></div>';
        render_footer();
        exit;
    }
}

// ---------------------------------------------------------------- 依頼者の識別

/*
 * 依頼者の「編集キー」はセッションごとにランダム発行し、Cookie（zonbi_o_<共有ID>）に保存する。
 * DB にはハッシュのみ保存。キーを含む「あなた専用の編集URL」を開けば、別の端末でも同じ依頼を編集できる。
 */

function is_valid_token(string $t): bool
{
    return (bool)preg_match('/^[0-9a-f]{64}$/', $t);
}

function owner_cookie_name(array $session): string
{
    return 'zonbi_o_' . $session['public_id'];
}

function set_owner_cookie(array $session, string $token): void
{
    $_COOKIE[owner_cookie_name($session)] = $token;
    setcookie(owner_cookie_name($session), $token, [
        'expires'  => time() + 60 * 60 * 24 * 365,
        'path'     => '/',
        'httponly' => true,
        'secure'   => is_https(),
        'samesite' => 'Lax',
    ]);
}

/** このブラウザの、このセッション用の編集キー（なければ発行） */
function owner_token(array $session): string
{
    $token = (string)($_COOKIE[owner_cookie_name($session)] ?? '');
    if (is_valid_token($token)) {
        set_owner_cookie($session, $token); // 期限延長
        return $token;
    }
    $token = random_hex(32);
    // 旧方式（全セッション共通Cookie）で登録した依頼を新しいキーに引き継ぐ
    $legacy = (string)($_COOKIE['zonbi_owner'] ?? '');
    if (is_valid_token($legacy)) {
        db()->prepare('UPDATE shop_entries SET owner_key = ? WHERE session_id = ? AND owner_key = ?')
            ->execute([hash('sha256', $token), $session['id'], hash('sha256', $legacy)]);
    }
    set_owner_cookie($session, $token);
    return $token;
}

/**
 * 編集URLのキーをこのブラウザに取り込む。
 * このブラウザで既に登録していた依頼があれば、編集URL側のキーにまとめる。
 */
function adopt_owner_token(array $session, string $newToken): void
{
    $current = (string)($_COOKIE[owner_cookie_name($session)] ?? '');
    if (is_valid_token($current) && !hash_equals($current, $newToken)) {
        db()->prepare('UPDATE shop_entries SET owner_key = ? WHERE session_id = ? AND owner_key = ?')
            ->execute([hash('sha256', $newToken), $session['id'], hash('sha256', $current)]);
    }
    set_owner_cookie($session, $newToken);
}

// ---------------------------------------------------------------- 入力

function input_str(array $src, string $key, int $max): string
{
    $v = $src[$key] ?? '';
    if (!is_string($v)) {
        return '';
    }
    $v = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? '');
    return mb_substr($v, 0, $max);
}

function to_int_or_null($v): ?int
{
    if (!is_string($v) && !is_int($v)) {
        return null;
    }
    // 全角数字・カンマ・円記号を許容
    $v = mb_convert_kana((string)$v, 'n');
    $v = str_replace([',', '，', '¥', '￥', '円', ' '], '', $v);
    if ($v === '' || !preg_match('/^\d{1,9}$/', $v)) {
        return null;
    }
    return (int)$v;
}

/**
 * 1件分の依頼入力を検証。エラーがあれば文字列配列を返す。
 */
function validate_entry(array $row, array &$errors, string $label = ''): ?array
{
    $e = [
        'item_name'  => input_str($row, 'item_name', 200),
        'color'      => input_str($row, 'color', 100),
        'variant'    => input_str($row, 'variant', 100),
        'quantity'   => to_int_or_null($row['quantity'] ?? ''),
        'unit_price' => to_int_or_null($row['unit_price'] ?? ''),
        'note'       => input_str($row, 'note', 500),
    ];
    $before = count($errors);
    if ($e['item_name'] === '') {
        $errors[] = $label . '商品名を入力してください。';
    }
    if ($e['quantity'] === null || $e['quantity'] < 1 || $e['quantity'] > 9999) {
        $errors[] = $label . '個数は 1〜9999 の数字で入力してください。';
    }
    if ($e['unit_price'] === null || $e['unit_price'] > 10000000) {
        $errors[] = $label . '単価は 0〜10,000,000 の数字（円）で入力してください。';
    }
    return count($errors) === $before ? $e : null;
}

function row_is_blank(array $row): bool
{
    foreach (['item_name', 'color', 'variant', 'quantity', 'unit_price', 'note'] as $k) {
        if (is_string($row[$k] ?? null) && trim($row[$k]) !== '') {
            return false;
        }
    }
    return true;
}

// ---------------------------------------------------------------- データ取得

function find_session(string $publicId): ?array
{
    if (!preg_match('/^[0-9a-f]{16}$/', $publicId)) {
        return null;
    }
    $st = db()->prepare('SELECT * FROM shop_sessions WHERE public_id = ?');
    $st->execute([$publicId]);
    return $st->fetch() ?: null;
}

function is_admin_key_valid(array $session, string $key): bool
{
    return $key !== '' && hash_equals($session['admin_key'], hash('sha256', $key));
}

function session_is_open(array $s): bool
{
    if ((int)$s['is_closed'] === 1) {
        return false;
    }
    if (!empty($s['deadline']) && strtotime($s['deadline']) < time()) {
        return false;
    }
    return true;
}

function find_entry(int $sessionId, int $entryId): ?array
{
    $st = db()->prepare('SELECT * FROM shop_entries WHERE id = ? AND session_id = ?');
    $st->execute([$entryId, $sessionId]);
    return $st->fetch() ?: null;
}

function fetch_entries(int $sessionId, ?string $ownerHash = null): array
{
    $sql = 'SELECT * FROM shop_entries WHERE session_id = ?';
    $args = [$sessionId];
    if ($ownerHash !== null) {
        $sql .= ' AND owner_key = ?';
        $args[] = $ownerHash;
    }
    $sql .= ' ORDER BY requester_name, created_at, id';
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

/** 依頼者ごとにまとめる */
function group_by_person(array $entries): array
{
    $out = [];
    foreach ($entries as $e) {
        $name = $e['requester_name'];
        $out[$name] ??= ['name' => $name, 'entries' => [], 'quantity' => 0, 'total' => 0];
        $out[$name]['entries'][] = $e;
        $out[$name]['quantity'] += (int)$e['quantity'];
        $out[$name]['total'] += (int)$e['quantity'] * (int)$e['unit_price'];
    }
    return array_values($out);
}

/** 商品（名前・色・型）ごとにまとめる＝買い物リスト */
function group_by_item(array $entries): array
{
    $out = [];
    foreach ($entries as $e) {
        $k = mb_strtolower($e['item_name'] . "\0" . $e['color'] . "\0" . $e['variant'] . "\0" . $e['unit_price']);
        $out[$k] ??= [
            'item_name' => $e['item_name'], 'color' => $e['color'], 'variant' => $e['variant'],
            'unit_price' => (int)$e['unit_price'], 'quantity' => 0, 'purchased' => 0, 'total' => 0, 'people' => [],
        ];
        $q = (int)$e['quantity'];
        $out[$k]['quantity'] += $q;
        if ((int)$e['is_purchased'] === 1) {
            $out[$k]['purchased'] += $q;
        }
        $out[$k]['total'] += $q * (int)$e['unit_price'];
        $out[$k]['people'][$e['requester_name']] = ($out[$k]['people'][$e['requester_name']] ?? 0) + $q;
    }
    $out = array_values($out);
    usort($out, fn($a, $b) => [$a['item_name'], $a['color'], $a['variant']] <=> [$b['item_name'], $b['color'], $b['variant']]);
    return $out;
}

function summarize(array $entries): array
{
    $people = [];
    $qty = 0;
    $total = 0;
    foreach ($entries as $e) {
        $people[$e['requester_name']] = true;
        $qty += (int)$e['quantity'];
        $total += (int)$e['quantity'] * (int)$e['unit_price'];
    }
    return ['people' => count($people), 'count' => count($entries), 'quantity' => $qty, 'total' => $total];
}

/** 依頼一覧を記入者全員に公開しているか */
function session_shows_all(array $s): bool
{
    return !empty($s['show_all']);
}

/** イベントURLの検証（http/https のみ許可）。問題なければ null、エラーなら文言を返す */
function event_url_error(string $u): ?string
{
    if ($u === '') {
        return null;
    }
    if (!preg_match('#^https?://#i', $u) || filter_var($u, FILTER_VALIDATE_URL) === false) {
        return 'イベントURLは https:// から始まるURLを入力してください。';
    }
    return null;
}

/** event_url 列を保存（列がない古いDBではエラー文言を返す） */
function save_event_url(int $sessionId, string $u): ?string
{
    try {
        db()->prepare('UPDATE shop_sessions SET event_url = ? WHERE id = ?')->execute([$u === '' ? null : $u, $sessionId]);
        return null;
    } catch (PDOException $e) {
        return 'イベントURLを保存できませんでした。phpMyAdmin で sql/migrations/002_add_event_url.sql を実行してください。';
    }
}

/** 運営者情報（config.php の operator） */
function operator(string $key): string
{
    $op = config('operator', []);
    return is_array($op) ? trim((string)($op[$key] ?? '')) : '';
}

// ---------------------------------------------------------------- アフィリエイト

function affiliate_conf(string $key): string
{
    $a = config('affiliate', []);
    return is_array($a) ? trim((string)($a[$key] ?? '')) : '';
}

function amazon_enabled(): bool
{
    return (bool)preg_match('/^[A-Za-z0-9_-]{1,64}$/', affiliate_conf('amazon_tag'));
}

function rakuten_enabled(): bool
{
    return (bool)preg_match('/^[0-9a-f]{8}(\.[0-9a-f]{8}){3}$/i', affiliate_conf('rakuten_id'));
}

function affiliate_enabled(): bool
{
    return amazon_enabled() || rakuten_enabled();
}

/** 検索キーワード（商品名＋色＋型） */
function affiliate_keyword(array $e): string
{
    $parts = array_filter([$e['item_name'] ?? '', $e['color'] ?? '', $e['variant'] ?? ''], fn($s) => trim((string)$s) !== '');
    return mb_substr(implode(' ', $parts), 0, 100);
}

function amazon_search_url(string $kw): string
{
    return 'https://www.amazon.co.jp/s?' . http_build_query(['k' => $kw, 'tag' => affiliate_conf('amazon_tag')]);
}

function rakuten_search_url(string $kw): string
{
    $target = 'https://search.rakuten.co.jp/search/mall/' . rawurlencode($kw) . '/';
    return 'https://hb.afl.rakuten.co.jp/hgc/' . affiliate_conf('rakuten_id') . '/?pc=' . rawurlencode($target) . '&m=' . rawurlencode($target);
}
