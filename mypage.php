<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

// 代行者のマイページ: ログインして作成・登録したセッションの一覧
if (!google_enabled()) {
    not_found('ログイン機能は現在利用できません。');
}
$user = current_user();
if (!$user) {
    redirect(login_url('mypage.php'));
}
$uid = (int)$user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['action'] ?? '') === 'delete_account') {
        if (input_str($_POST, 'confirm', 20) !== '削除') {
            flash('error', '確認欄に「削除」と入力してください。');
            redirect(url('mypage.php') . '#account');
        }
        // セッション自体は残る（owner_user_id は NULL になり、管理用URLで引き続き管理可能）
        logout_user();
        db()->prepare('DELETE FROM shop_users WHERE id = ?')->execute([$uid]);
        flash('success', 'アカウント情報を削除しました。作成したセッションは管理用URLで引き続き利用できます。');
        redirect(url('index.php'));
    }
}

$st = db()->prepare(
    'SELECT s.*, COUNT(e.id) AS entry_count, COUNT(DISTINCT e.requester_name) AS people,
            COALESCE(SUM(e.quantity * e.unit_price), 0) AS total
       FROM shop_sessions s
       LEFT JOIN shop_entries e ON e.session_id = s.id
      WHERE s.owner_user_id = ?
      GROUP BY s.id
      ORDER BY s.created_at DESC'
);
$st->execute([$uid]);
$sessions = $st->fetchAll();

render_header('マイページ');
?>
<section class="card">
  <p class="eyebrow">代行者マイページ</p>
  <h1><?= h($user['name'] !== '' ? $user['name'] : 'ログイン中') ?> さん</h1>
  <?php if ($user['email'] !== ''): ?><p class="muted small"><?= h($user['email']) ?> でログイン中</p><?php endif; ?>
  <div class="actions">
    <a class="btn btn-primary" href="<?= h(url('index.php')) ?>#create">＋ 新しいセッションを作る</a>
  </div>
</section>

<section class="card">
  <h2>あなたのセッション</h2>
  <?php if (!$sessions): ?>
    <p class="muted">まだありません。ログインした状態でセッションを作ると、ここに表示されます。</p>
  <?php else: ?>
    <ul class="session-list">
    <?php foreach ($sessions as $s): ?>
      <li>
        <div class="session-list-main">
          <a class="session-title" href="<?= h(url('admin.php', ['id' => $s['public_id']])) ?>"><?= h($s['title']) ?></a>
          <?php if (session_is_open($s)): ?><span class="badge badge-open">受付中</span><?php else: ?><span class="badge badge-closed">受付終了</span><?php endif; ?>
          <div class="muted small">
            依頼者 <?= (int)$s['people'] ?>人・<?= (int)$s['entry_count'] ?>件・<?= yen($s['total']) ?>
            ／ 作成 <?= h(date('Y/m/d', strtotime($s['created_at']))) ?>
            <?php if ($s['deadline']): ?>／ 締切 <?= h(date('Y/m/d H:i', strtotime($s['deadline']))) ?><?php endif; ?>
          </div>
        </div>
        <button type="button" class="btn btn-ghost btn-sm" data-copy="<?= h(url('s.php', ['id' => $s['public_id']])) ?>">共有URLをコピー</button>
      </li>
    <?php endforeach; ?>
    </ul>
  <?php endif; ?>
  <p class="muted small">ログイン前に作ったセッションは、そのセッションの管理用URLを開き「マイページに登録」を押すと、ここに追加されます。</p>
</section>

<details class="card danger-zone" id="account">
  <summary><strong>アカウント情報を削除</strong></summary>
  <p>ログイン情報（Googleアカウントの名前・メールアドレス）を削除します。作成したセッションと依頼データは削除されず、管理用URLで引き続き利用できます（管理用URLが分からない場合は、削除前に各セッションの管理ページで「管理用URLを再発行」してください）。確認のため「削除」と入力してください。</p>
  <form method="post" action="<?= h(url('mypage.php')) ?>" data-confirm="アカウント情報を削除しますか？">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="delete_account">
    <input type="text" name="confirm" placeholder="削除" required>
    <button class="btn btn-danger">削除する</button>
  </form>
</details>
<?php
render_footer();
