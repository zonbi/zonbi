<?php
declare(strict_types=1);

/*
 * 代行者の Google ログイン（OpenID Connect / 認可コードフロー + PKCE）
 * 外部ライブラリなしで動くように、HTTP 通信は curl（なければ file_get_contents）で行う。
 */

const LOGIN_COOKIE = 'zonbi_login';
const LOGIN_DAYS = 30;

function google_conf(string $key): string
{
    $g = config('google', []);
    return is_array($g) ? trim((string)($g[$key] ?? '')) : '';
}

function google_enabled(): bool
{
    return google_conf('client_id') !== '' && google_conf('client_secret') !== '';
}

function google_redirect_uri(): string
{
    return url('google_callback.php');
}

function base64url(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

/** ログイン後に戻るページ（このアプリ内の相対パスのみ許可） */
function safe_return_to(?string $r): string
{
    $r = (string)$r;
    if ($r === '' || !preg_match('#^[a-z_]+\.php(\?[^\r\n]*)?$#', $r)) {
        return 'mypage.php';
    }
    return $r;
}

/** Google の認可画面へのURLを作り、検証用の値をセッションに保存 */
function google_auth_url(string $returnTo): string
{
    $state = random_hex(16);
    $nonce = random_hex(16);
    $verifier = base64url(random_bytes(32));
    $_SESSION['oauth'] = [
        'state' => $state, 'nonce' => $nonce, 'verifier' => $verifier,
        'return_to' => safe_return_to($returnTo), 'at' => time(),
    ];
    $endpoint = google_conf('auth_url') !== '' ? google_conf('auth_url') : 'https://accounts.google.com/o/oauth2/v2/auth';
    return $endpoint . '?' . http_build_query([
        'client_id'             => google_conf('client_id'),
        'redirect_uri'          => google_redirect_uri(),
        'response_type'         => 'code',
        'scope'                 => 'openid email profile',
        'state'                 => $state,
        'nonce'                 => $nonce,
        'code_challenge'        => base64url(hash('sha256', $verifier, true)),
        'code_challenge_method' => 'S256',
        'prompt'                => 'select_account',
    ]);
}

function http_post_form(string $url, array $fields): array
{
    $body = http_build_query($fields);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $res = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => [
            'method' => 'POST', 'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $body, 'timeout' => 15, 'ignore_errors' => true,
        ]]);
        $res = @file_get_contents($url, false, $ctx);
        $status = 0;
        foreach ($http_response_header ?? [] as $hdr) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $hdr, $m)) {
                $status = (int)$m[1];
            }
        }
    }
    $json = is_string($res) ? json_decode($res, true) : null;
    return [$status, is_array($json) ? $json : []];
}

/**
 * コールバック処理。成功したらユーザー情報（sub, email, name）を返す。失敗時は例外。
 */
function google_handle_callback(array $query): array
{
    $saved = $_SESSION['oauth'] ?? null;
    unset($_SESSION['oauth']);
    if (!is_array($saved) || time() - (int)$saved['at'] > 600) {
        throw new RuntimeException('ログインの有効期限が切れました。もう一度お試しください。');
    }
    if (isset($query['error'])) {
        throw new RuntimeException('ログインがキャンセルされました。');
    }
    if (!hash_equals($saved['state'], (string)($query['state'] ?? ''))) {
        throw new RuntimeException('不正なログイン要求です。もう一度お試しください。');
    }
    $code = (string)($query['code'] ?? '');
    if ($code === '') {
        throw new RuntimeException('ログインに失敗しました。');
    }

    $tokenUrl = google_conf('token_url') !== '' ? google_conf('token_url') : 'https://oauth2.googleapis.com/token';
    [$status, $tok] = http_post_form($tokenUrl, [
        'code'          => $code,
        'client_id'     => google_conf('client_id'),
        'client_secret' => google_conf('client_secret'),
        'redirect_uri'  => google_redirect_uri(),
        'grant_type'    => 'authorization_code',
        'code_verifier' => $saved['verifier'],
    ]);
    if ($status !== 200 || empty($tok['id_token'])) {
        throw new RuntimeException('Googleとの通信に失敗しました。時間をおいてお試しください。');
    }

    // id_token は Google のトークンエンドポイントから TLS で直接受け取ったものなので、署名検証は省略し内容を検証する
    $parts = explode('.', (string)$tok['id_token']);
    $claims = count($parts) === 3 ? json_decode((string)base64_decode(strtr($parts[1], '-_', '+/')), true) : null;
    if (!is_array($claims)
        || !in_array($claims['iss'] ?? '', ['https://accounts.google.com', 'accounts.google.com'], true)
        || ($claims['aud'] ?? '') !== google_conf('client_id')
        || (int)($claims['exp'] ?? 0) < time()
        || !hash_equals($saved['nonce'], (string)($claims['nonce'] ?? ''))
        || empty($claims['sub'])) {
        throw new RuntimeException('ログイン情報を確認できませんでした。');
    }
    return [
        'sub'       => (string)$claims['sub'],
        'email'     => !empty($claims['email_verified']) ? (string)($claims['email'] ?? '') : '',
        'name'      => mb_substr((string)($claims['name'] ?? ''), 0, 100),
        'return_to' => $saved['return_to'],
    ];
}

/** ユーザーを登録/更新してログイン状態にする */
function login_user(array $info): void
{
    $pdo = db();
    $pdo->prepare('INSERT INTO shop_users (google_sub, email, name, last_login_at) VALUES (?, ?, ?, NOW())
                   ON DUPLICATE KEY UPDATE email = VALUES(email), name = VALUES(name), last_login_at = NOW()')
        ->execute([$info['sub'], $info['email'], $info['name']]);
    $st = $pdo->prepare('SELECT id FROM shop_users WHERE google_sub = ?');
    $st->execute([$info['sub']]);
    $userId = (int)$st->fetchColumn();

    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;

    // 30日間ログインを保持
    $token = random_hex(32);
    $pdo->prepare('INSERT INTO shop_login_tokens (token_hash, user_id, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ' . LOGIN_DAYS . ' DAY))')
        ->execute([hash('sha256', $token), $userId]);
    $pdo->exec('DELETE FROM shop_login_tokens WHERE expires_at < NOW()');
    set_login_cookie($token, time() + 86400 * LOGIN_DAYS);
}

function set_login_cookie(string $value, int $expires): void
{
    setcookie(LOGIN_COOKIE, $value, [
        'expires' => $expires, 'path' => '/', 'httponly' => true, 'secure' => is_https(), 'samesite' => 'Lax',
    ]);
}

function logout_user(): void
{
    $token = (string)($_COOKIE[LOGIN_COOKIE] ?? '');
    if ($token !== '') {
        try {
            db()->prepare('DELETE FROM shop_login_tokens WHERE token_hash = ?')->execute([hash('sha256', $token)]);
        } catch (PDOException $e) {
            // テーブル未作成でもログアウトは続行
        }
    }
    set_login_cookie('', time() - 3600);
    unset($_SESSION['user_id']);
    session_regenerate_id(true);
}

/** ログイン中のユーザー（未ログイン・機能未設定なら null） */
function current_user(): ?array
{
    static $cache = false;
    if ($cache !== false) {
        return $cache;
    }
    $cache = null;
    if (!google_enabled()) {
        return null;
    }
    try {
        $id = (int)($_SESSION['user_id'] ?? 0);
        if ($id === 0) {
            $token = (string)($_COOKIE[LOGIN_COOKIE] ?? '');
            if (preg_match('/^[0-9a-f]{64}$/', $token)) {
                $st = db()->prepare('SELECT user_id FROM shop_login_tokens WHERE token_hash = ? AND expires_at > NOW()');
                $st->execute([hash('sha256', $token)]);
                $id = (int)$st->fetchColumn();
                if ($id > 0) {
                    $_SESSION['user_id'] = $id;
                }
            }
        }
        if ($id > 0) {
            $st = db()->prepare('SELECT * FROM shop_users WHERE id = ?');
            $st->execute([$id]);
            $cache = $st->fetch() ?: null;
            if ($cache === null) {
                unset($_SESSION['user_id']);
            }
        }
    } catch (PDOException $e) {
        $cache = null; // 003 のSQL未実行など
    }
    return $cache;
}

function login_tables_ready(): bool
{
    try {
        db()->query('SELECT owner_user_id FROM shop_sessions LIMIT 0');
        db()->query('SELECT 1 FROM shop_users LIMIT 0');
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

/** ログイン中ユーザーがこのセッションの持ち主か */
function is_session_owner(array $session): bool
{
    $u = current_user();
    return $u !== null && !empty($session['owner_user_id']) && (int)$session['owner_user_id'] === (int)$u['id'];
}

function login_url(string $returnTo = ''): string
{
    return url('login.php', $returnTo !== '' ? ['return_to' => $returnTo] : []);
}
