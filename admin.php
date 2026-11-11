<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

// 代行者（管理者）用ページ
//   admin.php?id=共有ID&key=管理キー  … 管理用URLで開く
//   admin.php?id=共有ID               … Googleログインした持ち主が開く
$session = find_session((string)($_GET['id'] ?? ''));
if (!$session) {
    not_found('管理用URLが正しくありません。');
}
$key = (string)($_GET['key'] ?? '');
$byKey = is_admin_key_valid($session, $key);
$byOwner = is_session_owner($session);
if (!$byKey && !$byOwner) {
    if (google_enabled() && !current_user() && !empty($session['owner_user_id'])) {
        redirect(login_url('admin.php?id=' . $session['public_id']));
    }
    not_found('管理用URLが正しくありません。');
}
$sid = (int)$session['id'];
$publicId = $session['public_id'];
$view = in_array($_GET['view'] ?? '', ['list', 'person', 'item'], true) ? $_GET['view'] : 'person';
$base = $byKey ? ['id' => $publicId, 'key' => $key] : ['id' => $publicId];
$user = current_user();
$self = url('admin.php', $base + ['view' => $view]);
$shareUrl = url('s.php', ['id' => $publicId]);

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    switch ($action) {
        case 'update_session':
            $title = input_str($_POST, 'title', 100);
            $desc = input_str($_POST, 'description', 2000);
            $eventUrl = input_str($_POST, 'event_url', 500);
            $dl = input_str($_POST, 'deadline', 20);
            $deadline = null;
            if ($title === '') {
                $errors[] = 'タイトルを入力してください。';
            }
            if ($err = event_url_error($eventUrl)) {
                $errors[] = $err;
            }
            if ($dl !== '') {
                $ts = strtotime($dl);
                if ($ts === false) {
                    $errors[] = '締切日時の形式が正しくありません。';
                } else {
                    $deadline = date('Y-m-d H:i:s', $ts);
                }
            }
            if (!$errors) {
                db()->prepare('UPDATE shop_sessions SET title=?, description=?, deadline=? WHERE id=?')
                    ->execute([$title, $desc, $deadline, $sid]);
                $eventErr = ($eventUrl !== '' || !empty($session['event_url'])) ? save_event_url($sid, $eventUrl) : null;
                moderate_session($sid, false);
                if ($eventErr) {
                    flash('error', $eventErr);
                } else {
                    flash('success', 'セッション情報を更新しました。');
                }
                redirect($self);
            }
            break;

        case 'toggle_closed':
            db()->prepare('UPDATE shop_sessions SET is_closed = 1 - is_closed WHERE id=?')->execute([$sid]);
            flash('success', (int)$session['is_closed'] === 1 ? '受付を再開しました。' : '受付を締め切りました。');
            redirect($self);

        case 'toggle_show_all':
            try {
                db()->prepare('UPDATE shop_sessions SET show_all = 1 - show_all WHERE id=?')->execute([$sid]);
            } catch (PDOException $e) {
                flash('error', 'データベースの更新が必要です。phpMyAdmin で sql/migrations/001_add_show_all.sql を実行してください。');
                redirect($self);
            }
            flash('success', session_shows_all($session)
                ? 'みんなの依頼を非公開にしました（各自、自分の依頼だけ表示）。'
                : 'みんなの依頼を記入者全員に公開しました。');
            redirect($self);

        case 'toggle_purchased':
            db()->prepare('UPDATE shop_entries SET is_purchased = 1 - is_purchased WHERE id=? AND session_id=?')
                ->execute([(int)($_POST['entry_id'] ?? 0), $sid]);
            redirect($self . '#e' . (int)($_POST['entry_id'] ?? 0));

        case 'update_entry':
            $entry = find_entry($sid, (int)($_POST['entry_id'] ?? 0));
            if (!$entry) {
                redirect($self);
            }
            $name = input_str($_POST, 'requester_name', 50);
            if ($name === '') {
                $errors[] = 'お名前を入力してください。';
            }
            $v = validate_entry($_POST, $errors);
            if (!$errors) {
                db()->prepare('UPDATE shop_entries SET requester_name=?, item_name=?, color=?, variant=?, quantity=?, unit_price=?, note=? WHERE id=?')
                    ->execute([$name, $v['item_name'], $v['color'], $v['variant'], $v['quantity'], $v['unit_price'], $v['note'], $entry['id']]);
                moderate_entry((int)$entry['id'], false);
                flash('success', '依頼を更新しました。');
                redirect($self . '#e' . $entry['id']);
            }
            $_GET['edit'] = (string)$entry['id'];
            break;

        case 'delete_entry':
            db()->prepare('DELETE FROM shop_entries WHERE id=? AND session_id=?')->execute([(int)($_POST['entry_id'] ?? 0), $sid]);
            flash('success', '依頼を削除しました。');
            redirect($self);

        case 'claim':
            // 管理用URLで開いたセッションを、ログイン中のアカウントのマイページに登録
            if ($byKey && $user && empty($session['owner_user_id'])) {
                db()->prepare('UPDATE shop_sessions SET owner_user_id = ? WHERE id = ? AND owner_user_id IS NULL')
                    ->execute([(int)$user['id'], $sid]);
                flash('success', 'マイページに登録しました。今後はログインすれば、どの端末からでも管理できます。');
            }
            redirect($self);

        case 'regen_key':
            // 管理用URLを作り直す（古い管理用URLは使えなくなる）
            $newKey = random_hex(16);
            db()->prepare('UPDATE shop_sessions SET admin_key = ? WHERE id = ?')->execute([hash('sha256', $newKey), $sid]);
            flash('success', '新しい管理用URLを発行しました。以前の管理用URLは使えなくなりました。');
            redirect(url('admin.php', ['id' => $publicId, 'key' => $newKey]));

        case 'delete_session':
            if (input_str($_POST, 'confirm_title', 100) !== $session['title']) {
                flash('error', 'タイトルが一致しないため削除を中止しました。');
                redirect($self . '#danger');
            }
            db()->prepare('DELETE FROM shop_sessions WHERE id=?')->execute([$sid]);
            flash('success', 'セッション「' . $session['title'] . '」を削除しました。');
            redirect(url('index.php', ['deleted' => $publicId]));
    }
}

$editing = null;
if (isset($_GET['edit'])) {
    $e = find_entry($sid, (int)$_GET['edit']);
    if ($e) {
        $editing = $_SERVER['REQUEST_METHOD'] === 'POST' ? array_merge($e, $_POST) : $e;
    }
}

$entries = fetch_entries($sid);
$sum = summarize($entries);
$open = session_is_open($session);
$adminUrl = url('admin.php', $base);

render_header('管理: ' . $session['title'], 'admin');
?>
<div data-admin-meta data-title="<?= h($session['title']) ?>" data-url="<?= h($adminUrl) ?>" data-id="<?= h($publicId) ?>" hidden></div>

<section class="card session-head">
  <p class="eyebrow">代行者用 管理ページ</p>
  <h1><?= h($session['title']) ?>
    <?php if ($open): ?><span class="badge badge-open">受付中</span><?php else: ?><span class="badge badge-closed">受付終了</span><?php endif; ?>
  </h1>

  <div class="url-box">
    <label>依頼者に送る <strong>共有URL</strong></label>
    <div class="copy-row">
      <input type="text" readonly value="<?= h($shareUrl) ?>" data-select-on-focus>
      <button type="button" class="btn btn-primary btn-sm" data-copy="<?= h($shareUrl) ?>">コピー</button>
    </div>
  </div>
  <?php if ($byKey): ?>
  <div class="url-box url-box-admin">
    <label>この管理用URL（<strong>他人に教えないでください</strong>・ブックマーク推奨）</label>
    <div class="copy-row">
      <input type="text" readonly value="<?= h($adminUrl) ?>" data-select-on-focus>
      <button type="button" class="btn btn-ghost btn-sm" data-copy="<?= h($adminUrl) ?>">コピー</button>
    </div>
  </div>
  <?php endif; ?>

  <?php if (google_enabled()): ?>
  <div class="login-box">
    <?php if ($byOwner): ?>
      <p>✓ <strong>マイページに登録済み</strong>（<?= h($user['name'] !== '' ? $user['name'] : $user['email']) ?>）。ログインすれば、どの端末からでも<a href="<?= h(url('mypage.php')) ?>">マイページ</a>から管理できます。</p>
    <?php elseif (!empty($session['owner_user_id'])): ?>
      <p class="muted small">このセッションは別のアカウントのマイページに登録されています。</p>
    <?php elseif ($user): ?>
      <form method="post" action="<?= h($self) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="claim">
        <p class="small">このセッションを <?= h($user['name'] !== '' ? $user['name'] : $user['email']) ?> さんのマイページに登録すると、管理用URLがなくてもログインで管理できます。</p>
        <button class="btn btn-primary btn-sm">マイページに登録</button>
      </form>
    <?php else: ?>
      <p class="small">Googleでログインすると、このセッションをマイページに登録して、どの端末からでも管理できます。</p>
      <a class="btn btn-google btn-sm" href="<?= h(login_url('admin.php?' . http_build_query($base))) ?>">Googleでログイン</a>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="stats">
    <div><span>依頼者</span><strong><?= $sum['people'] ?>人</strong></div>
    <div><span>依頼件数</span><strong><?= $sum['count'] ?>件</strong></div>
    <div><span>総個数</span><strong><?= $sum['quantity'] ?>点</strong></div>
    <div><span>総額</span><strong><?= total_html($sum['total'], $sum['unpriced']) ?></strong></div>
  </div>

  <div class="actions">
    <form method="post" action="<?= h($self) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="toggle_closed">
      <?php if ((int)$session['is_closed'] === 1): ?>
        <button class="btn btn-ghost">受付を再開する</button>
      <?php else: ?>
        <button class="btn btn-ghost">受付を締め切る</button>
      <?php endif; ?>
    </form>
    <form method="post" action="<?= h($self) ?>"<?= session_shows_all($session) ? '' : ' data-confirm="依頼者全員の名前と依頼内容が、共有URLを開いた人全員に見えるようになります。公開しますか？"' ?>>
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="toggle_show_all">
      <button class="btn btn-ghost"><?= session_shows_all($session) ? 'みんなの依頼を非公開にする' : 'みんなの依頼を全員に公開する' ?></button>
    </form>
    <a class="btn btn-ghost" href="<?= h(url('export.php', $base + ['type' => $view])) ?>">CSVダウンロード（表示中の表）</a>
  </div>
  <p class="muted small">依頼一覧の公開: <strong><?= session_shows_all($session) ? '公開中（記入者全員が全員分の依頼を見られます）' : '非公開（各自、自分の依頼だけ表示）' ?></strong></p>
  <?php if ((int)$session['is_closed'] === 0 && !$open): ?>
    <p class="muted small">※締切日時を過ぎたため、依頼者からは受付終了と表示されています。</p>
  <?php endif; ?>
</section>

<?php if ($editing): ?>
<section class="card" id="edit">
  <h2>依頼を編集（<?= h($editing['requester_name']) ?> さん）</h2>
  <?php render_errors($errors); ?>
  <form method="post" action="<?= h($self) ?>" data-entry-form>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="update_entry">
    <input type="hidden" name="entry_id" value="<?= (int)$editing['id'] ?>">
    <label>お名前 <span class="req">必須</span>
      <input type="text" name="requester_name" value="<?= h($editing['requester_name']) ?>" maxlength="50" required>
    </label>
    <?php render_entry_fields('', $editing); ?>
    <div class="actions">
      <a class="btn btn-ghost" href="<?= h($self) ?>">キャンセル</a>
      <button type="submit" class="btn btn-primary">更新する</button>
    </div>
  </form>
</section>
<?php endif; ?>

<nav class="tabs">
  <a href="<?= h(url('admin.php', $base + ['view' => 'person'])) ?>" class="<?= $view === 'person' ? 'active' : '' ?>">依頼者別</a>
  <a href="<?= h(url('admin.php', $base + ['view' => 'item'])) ?>" class="<?= $view === 'item' ? 'active' : '' ?>">商品別（買い物リスト）</a>
  <a href="<?= h(url('admin.php', $base + ['view' => 'list'])) ?>" class="<?= $view === 'list' ? 'active' : '' ?>">全件一覧</a>
</nav>

<?php if (!$entries): ?>
  <section class="card"><p class="muted">まだ依頼はありません。共有URLを依頼者に送りましょう。</p></section>
<?php elseif ($view === 'person'): ?>
  <?php foreach (group_by_person($entries) as $p): ?>
  <section class="card person-card">
    <div class="person-head">
      <h2><?= h($p['name']) ?> さん</h2>
      <div class="person-total"><?= $p['quantity'] ?>点 <strong><?= total_html($p['total'], $p['unpriced']) ?></strong></div>
    </div>
    <div class="table-wrap">
    <table class="table">
      <thead><tr><th>購入</th><th>商品名</th><th>色 / 型</th><th class="num">個数</th><th class="num">単価</th><th class="num">小計</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($p['entries'] as $e) { admin_entry_row($e, $self, $base, false); } ?>
      </tbody>
    </table>
    </div>
  </section>
  <?php endforeach; ?>
<?php elseif ($view === 'item'): ?>
  <section class="card">
    <p class="muted small">同じ「商品名・色・型・単価」の依頼をまとめています。現地ではこのリストを見ながら購入できます。</p>
    <div class="table-wrap">
    <table class="table">
      <thead><tr><th>商品名</th><th>色 / 型</th><th class="num">必要数</th><th class="num">購入済</th><th class="num">単価</th><th class="num">金額</th><th>内訳</th></tr></thead>
      <tbody>
      <?php foreach (group_by_item($entries) as $it): ?>
        <tr class="<?= $it['purchased'] >= $it['quantity'] ? 'done' : '' ?>">
          <td><?= h($it['item_name']) ?></td>
          <td><?= h(item_label($it)) ?></td>
          <td class="num"><strong><?= $it['quantity'] ?></strong></td>
          <td class="num"><?= $it['purchased'] ?></td>
          <td class="num"><?= price_html($it['unit_price']) ?></td>
          <td class="num"><?= yen($it['total']) ?></td>
          <td class="small"><?php
            $parts = [];
            foreach ($it['people'] as $name => $q) {
                $parts[] = h($name) . '×' . $q;
            }
            echo implode('、', $parts);
          ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr><th colspan="2">合計</th><th class="num"><?= $sum['quantity'] ?></th><th></th><th></th><th class="num"><?= total_html($sum['total'], $sum['unpriced']) ?></th><th></th></tr></tfoot>
    </table>
    </div>
  </section>
<?php else: ?>
  <section class="card">
    <div class="table-wrap">
    <table class="table">
      <thead><tr><th>購入</th><th>依頼者</th><th>商品名</th><th>色 / 型</th><th class="num">個数</th><th class="num">単価</th><th class="num">小計</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($entries as $e) { admin_entry_row($e, $self, $base, true); } ?>
      </tbody>
      <tfoot><tr><th colspan="4">合計</th><th class="num"><?= $sum['quantity'] ?></th><th></th><th class="num"><?= total_html($sum['total'], $sum['unpriced']) ?></th><th></th></tr></tfoot>
    </table>
    </div>
  </section>
<?php endif; ?>

<details class="card">
  <summary><strong>セッション情報を編集</strong></summary>
  <?php if (($_POST['action'] ?? '') === 'update_session') { render_errors($errors); } ?>
  <form method="post" action="<?= h($self) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="update_session">
    <label>タイトル <span class="req">必須</span>
      <input type="text" name="title" value="<?= h($session['title']) ?>" maxlength="100" required>
    </label>
    <label>説明・注意事項
      <textarea name="description" rows="4" maxlength="2000"><?= h($session['description'] ?? '') ?></textarea>
    </label>
    <label>イベントURL（任意）
      <input type="url" name="event_url" value="<?= h($session['event_url'] ?? '') ?>" maxlength="500" inputmode="url" placeholder="例: https://example.com/event/popup">
    </label>
    <label>受付締切（任意）
      <input type="datetime-local" name="deadline" value="<?= $session['deadline'] ? h(date('Y-m-d\TH:i', strtotime($session['deadline']))) : '' ?>">
    </label>
    <button class="btn btn-primary">保存</button>
  </form>
</details>

<details class="card">
  <summary><strong>管理用URLを再発行</strong></summary>
  <p class="small">管理用URLを他人に知られた、または紛失したときに使います。新しい管理用URLを発行し、<strong>以前の管理用URLは使えなくなります</strong>。共有URL（依頼者用）は変わりません。</p>
  <form method="post" action="<?= h($self) ?>" data-confirm="管理用URLを再発行しますか？以前の管理用URLは使えなくなります。">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="regen_key">
    <button class="btn btn-ghost">管理用URLを再発行する</button>
  </form>
</details>

<details class="card danger-zone" id="danger">
  <summary><strong>セッションを削除</strong></summary>
  <p>すべての依頼データが完全に削除されます。元に戻せません。確認のためタイトル「<?= h($session['title']) ?>」を入力してください。</p>
  <form method="post" action="<?= h($self) ?>" data-confirm="本当に削除しますか？">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="delete_session">
    <input type="text" name="confirm_title" placeholder="タイトルを入力" required>
    <button class="btn btn-danger">削除する</button>
  </form>
</details>
<?php
render_footer();

function admin_entry_row(array $e, string $self, array $base, bool $showName): void
{
    $id = (int)$e['id'];
    ?>
<tr id="e<?= $id ?>" class="<?= (int)$e['is_purchased'] === 1 ? 'done' : '' ?>">
  <td>
    <form method="post" action="<?= h($self) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="toggle_purchased">
      <input type="hidden" name="entry_id" value="<?= $id ?>">
      <button class="check <?= (int)$e['is_purchased'] === 1 ? 'on' : '' ?>" title="購入済みを切り替え" aria-label="購入済みを切り替え"><?= (int)$e['is_purchased'] === 1 ? '✓' : '' ?></button>
    </form>
  </td>
  <?php if ($showName): ?><td><?= h($e['requester_name']) ?></td><?php endif; ?>
  <td><?= h($e['item_name']) ?><?php if ($e['note'] !== ''): ?><div class="note"><?= h($e['note']) ?></div><?php endif; ?></td>
  <td><?= h(item_label($e)) ?></td>
  <td class="num"><?= (int)$e['quantity'] ?></td>
  <td class="num"><?= price_html($e['unit_price']) ?></td>
  <td class="num"><?= line_total_html($e) ?></td>
  <td class="row-actions">
    <a class="btn btn-ghost btn-sm" href="<?= h(url('admin.php', $base + ['view' => $_GET['view'] ?? 'person', 'edit' => $id])) ?>#edit">編集</a>
    <form method="post" action="<?= h($self) ?>" data-confirm="<?= h($e['requester_name'] . ' さんの「' . $e['item_name'] . '」を削除しますか？') ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="delete_entry">
      <input type="hidden" name="entry_id" value="<?= $id ?>">
      <button class="btn btn-danger btn-sm">削除</button>
    </form>
  </td>
</tr>
<?php
}
