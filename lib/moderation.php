<?php
declare(strict_types=1);

/*
 * 犯罪利用対策
 *  - 危険ワード検知（自動削除はせず、運営者コンソールで目立たせ・通知する）
 *  - 登録時のIPアドレス・ブラウザ情報の記録
 *  - 通報
 *  - 運営者コンソールへのアクセス判定
 */

/** 標準の危険ワード（誤検知を避けるため、日常語と重なりにくい語に限定） */
const DEFAULT_NG_WORDS = [
    // 規制薬物（「シャブ」は「しゃぶしゃぶ」と誤検知するため除外）
    '大麻', 'マリファナ', 'ガンジャ', '覚醒剤', '覚せい剤', 'MDMA', 'LSD', 'コカイン', 'ヘロイン',
    'ケタミン', '危険ドラッグ', '脱法ドラッグ', '脱法ハーブ', '合法ハーブ', '向精神薬', '睡眠薬', '処方薬',
    // 武器・危険物
    '拳銃', '銃弾', '実弾', '改造銃', 'トカレフ', '爆薬', '爆弾', 'ダイナマイト', '火薬',
    // 偽造品・盗品
    '偽ブランド', 'スーパーコピー', 'N級', '偽造', 'コピー品', '盗品',
    // 性的搾取
    '児童ポルノ', '児ポ',
    // その他
    '口座売買', '名義貸し', '架空口座', 'マネーロンダリング',
];

function moderation_conf(string $key)
{
    $m = config('moderation', []);
    return is_array($m) ? ($m[$key] ?? null) : null;
}

function ng_words(): array
{
    $extra = moderation_conf('extra_words');
    $words = array_merge(DEFAULT_NG_WORDS, is_array($extra) ? $extra : []);
    return array_values(array_unique(array_filter(array_map('trim', $words), fn($w) => $w !== '')));
}

/** 比較用に正規化（全角英数→半角、半角カナ→全角、カタカナ→ひらがな、小文字化、空白除去） */
function normalize_for_scan(string $s): string
{
    $s = mb_convert_kana($s, 'KVas');
    $s = mb_convert_kana($s, 'c');
    $s = mb_strtolower($s);
    return preg_replace('/[\s　・\.\-_\/]+/u', '', $s) ?? $s;
}

/** テキスト群から危険ワードを探す。見つかった語の配列を返す */
function scan_ng_words(array $texts): array
{
    $hay = normalize_for_scan(implode("\n", array_map('strval', $texts)));
    $hits = [];
    foreach (ng_words() as $w) {
        if (mb_strpos($hay, normalize_for_scan($w)) !== false) {
            $hits[] = $w;
        }
    }
    return $hits;
}

function client_ip(): string
{
    return mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

function client_ua(): string
{
    return mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
}

/**
 * 依頼の登録・更新後に呼ぶ。IP記録と危険ワード検知を行う。
 * （004 のSQL未実行の環境では何もしない）
 */
function moderate_entry(int $entryId, bool $isNew): void
{
    try {
        $e = db()->prepare('SELECT e.*, s.title, s.public_id FROM shop_entries e JOIN shop_sessions s ON s.id = e.session_id WHERE e.id = ?');
        $e->execute([$entryId]);
        $row = $e->fetch();
        if (!$row) {
            return;
        }
        $hits = scan_ng_words([$row['requester_name'], $row['item_name'], $row['color'], $row['variant'], $row['note']]);
        $flag = mb_substr(implode(',', $hits), 0, 255);
        if ($isNew) {
            db()->prepare('UPDATE shop_entries SET created_ip = ?, created_ua = ?, flag_words = ?, reviewed_at = NULL WHERE id = ?')
                ->execute([client_ip(), client_ua(), $flag, $entryId]);
        } else {
            db()->prepare('UPDATE shop_entries SET reviewed_at = IF(? = flag_words, reviewed_at, NULL), updated_ip = ?, flag_words = ? WHERE id = ?')
                ->execute([$flag, client_ip(), $flag, $entryId]);
        }
        if ($hits && $flag !== $row['flag_words']) {
            notify_operator(
                '【要確認】危険ワードを含む依頼',
                "セッション: {$row['title']}\n依頼者: {$row['requester_name']}\n商品: {$row['item_name']} {$row['color']} {$row['variant']}\nメモ: {$row['note']}\n検知ワード: {$flag}\n\n確認: " . url('console.php', ['view' => 'session', 'id' => $row['public_id']])
            );
        }
    } catch (PDOException $e) {
        // 004 未適用
    }
}

/** セッションの作成・更新後に呼ぶ */
function moderate_session(int $sessionId, bool $isNew): void
{
    try {
        $st = db()->prepare('SELECT * FROM shop_sessions WHERE id = ?');
        $st->execute([$sessionId]);
        $s = $st->fetch();
        if (!$s) {
            return;
        }
        $hits = scan_ng_words([$s['title'], $s['description'] ?? '', $s['event_url'] ?? '']);
        $flag = mb_substr(implode(',', $hits), 0, 255);
        if ($isNew) {
            db()->prepare('UPDATE shop_sessions SET created_ip = ?, created_ua = ?, flag_words = ?, reviewed_at = NULL WHERE id = ?')
                ->execute([client_ip(), client_ua(), $flag, $sessionId]);
        } else {
            db()->prepare('UPDATE shop_sessions SET reviewed_at = IF(? = flag_words, reviewed_at, NULL), flag_words = ? WHERE id = ?')
                ->execute([$flag, $flag, $sessionId]);
        }
        if ($hits && $flag !== $s['flag_words']) {
            notify_operator(
                '【要確認】危険ワードを含むセッション',
                "タイトル: {$s['title']}\n説明: {$s['description']}\n検知ワード: {$flag}\n\n確認: " . url('console.php', ['view' => 'session', 'id' => $s['public_id']])
            );
        }
    } catch (PDOException $e) {
        // 004 未適用
    }
}

function moderation_ready(): bool
{
    try {
        db()->query('SELECT flag_words FROM shop_entries LIMIT 0');
        db()->query('SELECT 1 FROM shop_reports LIMIT 0');
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

/** 運営者へメール通知（notify_email 未設定なら送らない） */
function notify_operator(string $subject, string $body): void
{
    $to = trim((string)moderation_conf('notify_email'));
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return;
    }
    $from = trim((string)moderation_conf('notify_from'));
    $headers = $from !== '' && filter_var($from, FILTER_VALIDATE_EMAIL) ? 'From: ' . $from : '';
    $app = config('app_name', '代行さん');
    @mb_send_mail($to, "[{$app}] {$subject}", $body . "\n\n-- \n{$app} 運営者通知", $headers);
}

// ---------------------------------------------------------------- 運営者コンソールの認証

/** 運営者として認められているか（Googleログインのメール or コンソール用パスワード） */
function is_console_admin(): bool
{
    if (!empty($_SESSION['console_ok'])) {
        return true;
    }
    $emails = moderation_conf('admin_emails');
    $u = current_user();
    if ($u && is_array($emails) && $u['email'] !== '') {
        foreach ($emails as $e) {
            if (strcasecmp(trim((string)$e), $u['email']) === 0) {
                return true;
            }
        }
    }
    return false;
}

function console_password(): string
{
    return (string)moderation_conf('console_password');
}

function console_password_enabled(): bool
{
    return mb_strlen(console_password()) >= 12;
}
