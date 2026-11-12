<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

// 運営者コンソール（全セッション・全依頼の確認、危険ワード、通報、削除）
$self = url('console.php');

// ---------------------------------------------------------------- ログイン / ログアウト
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'console_login') {
    csrf_check();
    if (console_password_enabled() && hash_equals(console_password(), (string)($_POST['password'] ?? ''))) {
        session_regenerate_id(true);
        $_SESSION['console_ok'] = time();
        redirect($self);
    }
    sleep(2); // 総当たり対策
    flash('error', 'パスワードが違います。');
    redirect($self);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'console_logout') {
    csrf_check();
    unset($_SESSION['console_ok']);
    flash('success', 'コンソールからログアウトしました。');
    redirect($self);
}

if (!is_console_admin()) {
    $u = current_user();
    $emailsSet = is_array(moderation_conf('admin_emails')) && moderation_conf('admin_emails');
    render_header('運営者コンソール');
    ?>
    <section class="card">
      <h1>運営者コンソール</h1>
      <?php if (!console_password_enabled() && !(google_enabled() && $emailsSet)): ?>
        <p>コンソールが設定されていません。<code>lib/config.php</code> の <code>moderation</code> に、<code>console_password</code>（12文字以上）または <code>admin_emails</code>（Googleログイン用）を設定してください。</p>
      <?php else: ?>
        <?php if ($u): ?>
          <p class="muted small"><?= h($u['email']) ?> は運営者として登録されていません。</p>
        <?php endif; ?>
        <?php if (google_enabled() && $emailsSet && !$u): ?>
          <p><a class="btn btn-google" href="<?= h(login_url('console.php')) ?>">Googleでログイン</a></p>
        <?php endif; ?>
        <?php if (console_password_enabled()): ?>
          <form method="post" action="<?= h($self) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="console_login">
            <label>コンソール用パスワード
              <input type="password" name="password" required autocomplete="current-password">
            </label>
            <button class="btn btn-primary">ログイン</button>
          </form>
        <?php endif; ?>
      <?php endif; ?>
    </section>
    <?php
    render_footer();
    exit;
}

if (!moderation_ready()) {
    render_header('運営者コンソール');
    echo '<section class="card"><h1>運営者コンソール</h1><p>データベースの更新が必要です。phpMyAdmin で <code>sql/migrations/004_add_moderation.sql</code> を実行してください。</p></section>';
    render_footer();
    exit;
}

$pdo = db();

// ---------------------------------------------------------------- 操作
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $back = (string)($_POST['back'] ?? '');
    $back = preg_match('#^console\.php(\?[^\r\n]*)?$#', $back) ? url($back) : $self;
    $eid = (int)($_POST['entry_id'] ?? 0);
    $sid = (int)($_POST['session_id'] ?? 0);

    switch ($action) {
        case 'review_entry':
            $pdo->prepare('UPDATE shop_entries SET reviewed_at = NOW() WHERE id = ?')->execute([$eid]);
            flash('success', '依頼を確認済みにしました。');
            break;
        case 'review_session':
            $pdo->prepare('UPDATE shop_sessions SET reviewed_at = NOW() WHERE id = ?')->execute([$sid]);
            flash('success', 'セッションを確認済みにしました。');
            break;
        case 'delete_entry':
            $pdo->prepare('DELETE FROM shop_entries WHERE id = ?')->execute([$eid]);
            flash('success', '依頼を削除しました。');
            break;
        case 'close_session':
            $pdo->prepare('UPDATE shop_sessions SET is_closed = 1 WHERE id = ?')->execute([$sid]);
            flash('success', 'セッションの受付を停止しました。');
            break;
        case 'delete_session':
            $pdo->prepare('DELETE FROM shop_sessions WHERE id = ?')->execute([$sid]);
            flash('success', 'セッションを削除しました（依頼・通報も削除されました）。');
            $back = url('console.php', ['view' => 'sessions']);
            break;
        case 'resolve_report':
            $pdo->prepare("UPDATE shop_reports SET status = 'resolved', resolved_at = NOW() WHERE id = ?")->execute([(int)($_POST['report_id'] ?? 0)]);
            flash('success', '通報を対応済みにしました。');
            break;
        case 'rescan':
            $n = 0;
            foreach ($pdo->query('SELECT id, requester_name, item_name, color, variant, note, flag_words FROM shop_entries')->fetchAll() as $r) {
                $f = mb_substr(implode(',', scan_ng_words([$r['requester_name'], $r['item_name'], $r['color'], $r['variant'], $r['note']])), 0, 255);
                if ($f !== $r['flag_words']) {
                    $pdo->prepare('UPDATE shop_entries SET flag_words = ?, reviewed_at = NULL WHERE id = ?')->execute([$f, $r['id']]);
                    $n++;
                }
            }
            foreach ($pdo->query('SELECT id, title, description, event_url, flag_words FROM shop_sessions')->fetchAll() as $r) {
                $f = mb_substr(implode(',', scan_ng_words([$r['title'], $r['description'] ?? '', $r['event_url'] ?? ''])), 0, 255);
                if ($f !== $r['flag_words']) {
                    $pdo->prepare('UPDATE shop_sessions SET flag_words = ?, reviewed_at = NULL WHERE id = ?')->execute([$f, $r['id']]);
                    $n++;
                }
            }
            flash('success', "再スキャンしました（{$n}件の判定が変わりました）。");
            break;
    }
    redirect($back);
}

// ---------------------------------------------------------------- 表示
$view = in_array($_GET['view'] ?? '', ['dash', 'reports', 'sessions', 'session', 'settings'], true) ? $_GET['view'] : 'dash';
$counts = [
    'flag_s'  => (int)$pdo->query("SELECT COUNT(*) FROM shop_sessions WHERE flag_words <> '' AND reviewed_at IS NULL")->fetchColumn(),
    'flag_e'  => (int)$pdo->query("SELECT COUNT(*) FROM shop_entries WHERE flag_words <> '' AND reviewed_at IS NULL")->fetchColumn(),
    'reports' => (int)$pdo->query("SELECT COUNT(*) FROM shop_reports WHERE status = 'open'")->fetchColumn(),
];

/** 小さなPOSTボタン */
function cbtn(string $action, array $fields, string $label, string $class = 'btn-ghost', string $confirm = ''): string
{
    $back = 'console.php' . ($_SERVER['QUERY_STRING'] ?? '' ? '?' . $_SERVER['QUERY_STRING'] : '');
    $html = '<form method="post" action="' . h(url('console.php')) . '"' . ($confirm !== '' ? ' data-confirm="' . h($confirm) . '"' : '') . '>' . csrf_field()
        . '<input type="hidden" name="action" value="' . h($action) . '"><input type="hidden" name="back" value="' . h($back) . '">';
    foreach ($fields as $k => $v) {
        $html .= '<input type="hidden" name="' . h($k) . '" value="' . h($v) . '">';
    }
    return $html . '<button class="btn ' . h($class) . ' btn-sm">' . h($label) . '</button></form>';
}

function flags_html(string $f): string
{
    if ($f === '') {
        return '';
    }
    return '<span class="flag">⚠ ' . h(str_replace(',', '・', $f)) . '</span>';
}

function session_link(array $s): string
{
    return '<a href="' . h(url('console.php', ['view' => 'session', 'id' => $s['public_id']])) . '">' . h($s['title']) . '</a>';
}

$reasonLabels = [
    'illegal' => '違法な物品・サービス', 'fake' => '偽造品・海賊版', 'resale' => '転売・規約違反',
    'privacy' => '個人情報', 'abuse' => '誹謗中傷・不適切', 'other' => 'その他',
];

render_header('運営者コンソール', 'console');
?>
<section class="card console-head">
  <div class="person-head">
    <h1>運営者コンソール</h1>
    <?php if (!empty($_SESSION['console_ok'])): ?>
      <form method="post" action="<?= h($self) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="console_logout"><button class="linklike">コンソールからログアウト</button></form>
    <?php endif; ?>
  </div>
  <div class="stats">
    <div><span>要確認セッション</span><strong class="<?= $counts['flag_s'] ? 'alert' : '' ?>"><?= $counts['flag_s'] ?></strong></div>
    <div><span>要確認の依頼</span><strong class="<?= $counts['flag_e'] ? 'alert' : '' ?>"><?= $counts['flag_e'] ?></strong></div>
    <div><span>未対応の通報</span><strong class="<?= $counts['reports'] ? 'alert' : '' ?>"><?= $counts['reports'] ?></strong></div>
    <div><span>全セッション</span><strong><?= (int)$pdo->query('SELECT COUNT(*) FROM shop_sessions')->fetchColumn() ?></strong></div>
  </div>
</section>

<nav class="tabs">
  <a href="<?= h(url('console.php')) ?>" class="<?= $view === 'dash' ? 'active' : '' ?>">要確認</a>
  <a href="<?= h(url('console.php', ['view' => 'reports'])) ?>" class="<?= $view === 'reports' ? 'active' : '' ?>">通報</a>
  <a href="<?= h(url('console.php', ['view' => 'sessions'])) ?>" class="<?= in_array($view, ['sessions', 'session'], true) ? 'active' : '' ?>">セッション検索</a>
  <a href="<?= h(url('console.php', ['view' => 'settings'])) ?>" class="<?= $view === 'settings' ? 'active' : '' ?>">設定</a>
</nav>

<?php if ($view === 'dash'): ?>
  <?php
  $fs = $pdo->query("SELECT * FROM shop_sessions WHERE flag_words <> '' AND reviewed_at IS NULL ORDER BY created_at DESC LIMIT 100")->fetchAll();
  $fe = $pdo->query("SELECT e.*, s.title, s.public_id FROM shop_entries e JOIN shop_sessions s ON s.id = e.session_id
                     WHERE e.flag_words <> '' AND e.reviewed_at IS NULL ORDER BY e.created_at DESC LIMIT 200")->fetchAll();
  ?>
  <section class="card">
    <h2>危険ワードを含むセッション</h2>
    <?php if (!$fs): ?><p class="muted">ありません。</p><?php else: ?>
    <div class="table-wrap"><table class="table">
      <thead><tr><th>タイトル</th><th>検知</th><th>作成</th><th>IP</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($fs as $s): ?>
        <tr><td><?= session_link($s) ?></td><td><?= flags_html($s['flag_words']) ?></td><td class="small"><?= h($s['created_at']) ?></td><td class="small mono"><?= h($s['created_ip']) ?></td>
          <td class="row-actions"><?= cbtn('review_session', ['session_id' => $s['id']], '問題なし') ?></td></tr>
      <?php endforeach; ?>
      </tbody></table></div>
    <?php endif; ?>
  </section>

  <section class="card">
    <h2>危険ワードを含む依頼</h2>
    <?php if (!$fe): ?><p class="muted">ありません。</p><?php else: ?>
    <div class="table-wrap"><table class="table">
      <thead><tr><th>セッション</th><th>依頼者</th><th>商品 / メモ</th><th>検知</th><th>IP</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($fe as $e): ?>
        <tr><td><?= session_link($e) ?></td><td><?= h($e['requester_name']) ?></td>
          <td><?= h($e['item_name']) ?> <span class="muted small"><?= h(item_label($e)) ?> ×<?= (int)$e['quantity'] ?></span><?php if ($e['note'] !== ''): ?><div class="note"><?= h($e['note']) ?></div><?php endif; ?></td>
          <td><?= flags_html($e['flag_words']) ?></td><td class="small mono"><?= h($e['created_ip']) ?></td>
          <td class="row-actions"><?= cbtn('review_entry', ['entry_id' => $e['id']], '問題なし') ?><?= cbtn('delete_entry', ['entry_id' => $e['id']], '削除', 'btn-danger', 'この依頼を削除しますか？') ?></td></tr>
      <?php endforeach; ?>
      </tbody></table></div>
    <?php endif; ?>
    <p class="muted small">「問題なし」にすると一覧から消えます。内容が変更され、別の危険ワードが検知されると再び表示されます。</p>
  </section>

<?php elseif ($view === 'reports'): ?>
  <?php $reports = $pdo->query("SELECT r.*, s.title, s.public_id FROM shop_reports r JOIN shop_sessions s ON s.id = r.session_id
                                ORDER BY (r.status = 'open') DESC, r.created_at DESC LIMIT 200")->fetchAll(); ?>
  <section class="card">
    <h2>通報</h2>
    <?php if (!$reports): ?><p class="muted">通報はありません。</p><?php else: ?>
    <div class="table-wrap"><table class="table">
      <thead><tr><th>状態</th><th>日時</th><th>セッション</th><th>理由 / 詳細</th><th>通報者IP</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($reports as $r): ?>
        <tr class="<?= $r['status'] === 'resolved' ? 'done' : '' ?>">
          <td><?= $r['status'] === 'open' ? '<span class="badge badge-closed">未対応</span>' : '<span class="badge badge-open">対応済</span>' ?></td>
          <td class="small"><?= h($r['created_at']) ?></td>
          <td><?= session_link($r) ?></td>
          <td><strong><?= h($reasonLabels[$r['reason']] ?? $r['reason']) ?></strong><?php if ($r['detail']): ?><div class="note"><?= nl2br(h($r['detail'])) ?></div><?php endif; ?></td>
          <td class="small mono"><?= h($r['reporter_ip']) ?></td>
          <td class="row-actions"><?= $r['status'] === 'open' ? cbtn('resolve_report', ['report_id' => $r['id']], '対応済みにする') : '' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody></table></div>
    <?php endif; ?>
  </section>

<?php elseif ($view === 'sessions'): ?>
  <?php
  $q = input_str($_GET, 'q', 100);
  $sql = "SELECT s.*, u.email AS owner_email,
                 (SELECT COUNT(*) FROM shop_entries e WHERE e.session_id = s.id) AS entry_count,
                 (SELECT COUNT(*) FROM shop_entries e WHERE e.session_id = s.id AND e.flag_words <> '') AS flagged,
                 (SELECT COUNT(*) FROM shop_reports r WHERE r.session_id = s.id AND r.status = 'open') AS open_reports
            FROM shop_sessions s LEFT JOIN shop_users u ON u.id = s.owner_user_id";
  $args = [];
  if ($q !== '') {
      $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
      $sql .= " WHERE s.title LIKE ? OR s.description LIKE ? OR s.created_ip LIKE ? OR s.public_id = ? OR u.email LIKE ?
                 OR EXISTS (SELECT 1 FROM shop_entries e WHERE e.session_id = s.id
                            AND (e.requester_name LIKE ? OR e.item_name LIKE ? OR e.note LIKE ? OR e.created_ip LIKE ?))";
      $args = [$like, $like, $like, $q, $like, $like, $like, $like, $like];
  }
  $sql .= ' ORDER BY s.created_at DESC LIMIT 200';
  $st = $pdo->prepare($sql);
  $st->execute($args);
  $list = $st->fetchAll();
  ?>
  <section class="card">
    <form method="get" action="<?= h($self) ?>" class="search-row">
      <input type="hidden" name="view" value="sessions">
      <input type="text" name="q" value="<?= h($q) ?>" placeholder="タイトル・依頼者名・商品名・メモ・IP・メール・共有IDで検索">
      <button class="btn btn-primary">検索</button>
    </form>
    <p class="muted small"><?= count($list) ?>件<?= count($list) >= 200 ? '（新しい順に200件まで表示）' : '' ?></p>
    <div class="table-wrap"><table class="table">
      <thead><tr><th>タイトル</th><th>作成</th><th class="num">依頼</th><th>状態</th><th>作成IP</th><th>代行者ログイン</th></tr></thead>
      <tbody>
      <?php foreach ($list as $s): ?>
        <tr>
          <td><?= session_link($s) ?> <?= flags_html($s['flag_words']) ?><?= $s['flagged'] ? ' <span class="flag">⚠ 依頼' . (int)$s['flagged'] . '件</span>' : '' ?><?= $s['open_reports'] ? ' <span class="flag">通報' . (int)$s['open_reports'] . '</span>' : '' ?></td>
          <td class="small"><?= h(date('Y/m/d H:i', strtotime($s['created_at']))) ?></td>
          <td class="num"><?= (int)$s['entry_count'] ?></td>
          <td><?= session_is_open($s) ? '受付中' : '終了' ?></td>
          <td class="small mono"><?= h($s['created_ip']) ?></td>
          <td class="small"><?= h($s['owner_email'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody></table></div>
  </section>

<?php elseif ($view === 'session'): ?>
  <?php
  $s = find_session((string)($_GET['id'] ?? ''));
  if (!$s) {
      echo '<section class="card"><p>セッションが見つかりません（削除済みの可能性があります）。</p></section>';
  } else {
      $owner = null;
      if (!empty($s['owner_user_id'])) {
          $st = $pdo->prepare('SELECT email, name FROM shop_users WHERE id = ?');
          $st->execute([$s['owner_user_id']]);
          $owner = $st->fetch() ?: null;
      }
      $entries = fetch_entries((int)$s['id']);
      $st = $pdo->prepare('SELECT * FROM shop_reports WHERE session_id = ? ORDER BY created_at DESC');
      $st->execute([$s['id']]);
      $reps = $st->fetchAll();
      $sum = summarize($entries);
  ?>
  <section class="card">
    <h2><?= h($s['title']) ?> <?= flags_html($s['flag_words']) ?></h2>
    <table class="info-table">
      <tr><th>状態</th><td><?= session_is_open($s) ? '受付中' : '受付終了' ?>／依頼一覧<?= session_shows_all($s) ? '公開' : '非公開' ?></td></tr>
      <tr><th>説明</th><td><?= nl2br(h($s['description'] ?? '')) ?></td></tr>
      <?php if (!empty($s['event_url'])): ?><tr><th>イベントURL</th><td><?= h($s['event_url']) ?></td></tr><?php endif; ?>
      <tr><th>共有URL</th><td><a href="<?= h(url('s.php', ['id' => $s['public_id']])) ?>" target="_blank"><?= h(url('s.php', ['id' => $s['public_id']])) ?></a></td></tr>
      <tr><th>作成日時</th><td><?= h($s['created_at']) ?></td></tr>
      <tr><th>作成IP / ブラウザ</th><td class="small mono"><?= h($s['created_ip']) ?><br><?= h($s['created_ua']) ?></td></tr>
      <tr><th>代行者ログイン</th><td><?= $owner ? h($owner['name'] . ' <' . $owner['email'] . '>') : '—' ?></td></tr>
      <tr><th>集計</th><td><?= $sum['people'] ?>人・<?= $sum['count'] ?>件・<?= $sum['quantity'] ?>点・<?= yen($sum['total']) ?></td></tr>
    </table>
    <div class="actions">
      <?php if ($s['flag_words'] !== '' && $s['reviewed_at'] === null): ?><?= cbtn('review_session', ['session_id' => $s['id']], 'セッションは問題なし') ?><?php endif; ?>
      <?php if ((int)$s['is_closed'] === 0): ?><?= cbtn('close_session', ['session_id' => $s['id']], '受付を停止', 'btn-ghost', 'このセッションの受付を停止しますか？') ?><?php endif; ?>
      <?= cbtn('delete_session', ['session_id' => $s['id']], 'セッションを削除', 'btn-danger', '「' . $s['title'] . '」と全ての依頼・通報を削除します。元に戻せません。よろしいですか？') ?>
    </div>
  </section>

  <section class="card">
    <h2>依頼（<?= count($entries) ?>件）</h2>
    <?php if (!$entries): ?><p class="muted">依頼はありません。</p><?php else: ?>
    <div class="table-wrap"><table class="table">
      <thead><tr><th>依頼者</th><th>商品 / メモ</th><th class="num">個数</th><th class="num">単価</th><th>検知</th><th>登録日時 / IP</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($entries as $e): ?>
        <tr class="<?= $e['flag_words'] !== '' && $e['reviewed_at'] === null ? 'flagged' : '' ?>">
          <td><?= h($e['requester_name']) ?><?php if (($e['requester_sns'] ?? '') !== ''): ?><div class="note"><?= sns_html($e['requester_sns']) ?></div><?php endif; ?></td>
          <td><?= h($e['item_name']) ?> <span class="muted small"><?= h(item_label($e)) ?></span><?php if ($e['note'] !== ''): ?><div class="note"><?= h($e['note']) ?></div><?php endif; ?></td>
          <td class="num"><?= (int)$e['quantity'] ?></td>
          <td class="num"><?= price_html($e['unit_price']) ?></td>
          <td><?= flags_html($e['flag_words']) ?><?= $e['flag_words'] !== '' && $e['reviewed_at'] !== null ? '<div class="note">確認済</div>' : '' ?></td>
          <td class="small mono"><?= h($e['created_at']) ?><br><?= h($e['created_ip']) ?><?= $e['updated_ip'] !== '' && $e['updated_ip'] !== $e['created_ip'] ? '<br>更新: ' . h($e['updated_ip']) : '' ?></td>
          <td class="row-actions">
            <?php if ($e['flag_words'] !== '' && $e['reviewed_at'] === null): ?><?= cbtn('review_entry', ['entry_id' => $e['id']], '問題なし') ?><?php endif; ?>
            <?= cbtn('delete_entry', ['entry_id' => $e['id']], '削除', 'btn-danger', 'この依頼を削除しますか？') ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody></table></div>
    <?php endif; ?>
  </section>

  <?php if ($reps): ?>
  <section class="card">
    <h2>このセッションへの通報</h2>
    <ul class="plain-list">
      <?php foreach ($reps as $r): ?>
        <li><?= $r['status'] === 'open' ? '<span class="badge badge-closed">未対応</span>' : '<span class="badge badge-open">対応済</span>' ?>
          <strong><?= h($reasonLabels[$r['reason']] ?? $r['reason']) ?></strong> <span class="muted small"><?= h($r['created_at']) ?> / <?= h($r['reporter_ip']) ?></span>
          <?php if ($r['detail']): ?><div class="note"><?= nl2br(h($r['detail'])) ?></div><?php endif; ?>
          <?= $r['status'] === 'open' ? cbtn('resolve_report', ['report_id' => $r['id']], '対応済みにする') : '' ?>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>
  <?php endif; ?>
  <?php } ?>

<?php else: /* settings */ ?>
  <section class="card">
    <h2>危険ワード</h2>
    <p class="small">次の語を含む依頼・セッションに印をつけます（自動削除はしません）。カタカナ/ひらがな、全角/半角、大文字/小文字、空白の違いは区別しません。
      語を追加するには <code>lib/config.php</code> の <code>moderation</code> → <code>extra_words</code> に記入してください。</p>
    <p class="ng-list"><?php foreach (ng_words() as $w): ?><span><?= h($w) ?></span><?php endforeach; ?></p>
    <?= cbtn('rescan', [], '全データを再スキャン', 'btn-primary') ?>
    <p class="muted small">語を追加・変更したあとに実行すると、過去の依頼にも反映されます。</p>
  </section>
  <section class="card">
    <h2>通知</h2>
    <?php $to = trim((string)moderation_conf('notify_email')); ?>
    <p class="small"><?= $to !== '' ? '危険ワードの検知・通報があると <strong>' . h($to) . '</strong> にメールで通知します。' : 'メール通知は未設定です（<code>moderation</code> → <code>notify_email</code>）。' ?></p>
  </section>
<?php endif; ?>
<?php
render_footer();
