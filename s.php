<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

// 依頼者用ページ: s.php?id=共有ID
$session = find_session((string)($_GET['id'] ?? ''));
if (!$session) {
    not_found('このセッションは存在しないか、削除されました。URLをもう一度確認してください。');
}
$sid = (int)$session['id'];
$publicId = $session['public_id'];
$owner = owner_key_hash();
$open = session_is_open($session);
$self = url('s.php', ['id' => $publicId]);

$errors = [];
$postedName = '';
$postedRows = [[]];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    if (!$open) {
        flash('error', '受付は終了しています。変更が必要な場合は代行者に連絡してください。');
        redirect($self);
    }

    if ($action === 'add') {
        $postedName = input_str($_POST, 'requester_name', 50);
        $rows = is_array($_POST['rows'] ?? null) ? array_values($_POST['rows']) : [];
        $rows = array_values(array_filter($rows, fn($r) => is_array($r) && !row_is_blank($r)));
        $postedRows = $rows ?: [[]];

        if ($postedName === '') {
            $errors[] = 'お名前を入力してください。';
        }
        if (!$rows) {
            $errors[] = '依頼する商品を1つ以上入力してください。';
        }
        if (count($rows) > 50) {
            $errors[] = '一度に登録できるのは50件までです。';
        }
        $valid = [];
        foreach ($rows as $i => $r) {
            $label = count($rows) > 1 ? (($i + 1) . '行目: ') : '';
            $v = validate_entry($r, $errors, $label);
            if ($v) {
                $valid[] = $v;
            }
        }
        if (!$errors) {
            $pdo = db();
            $pdo->beginTransaction();
            $st = $pdo->prepare('INSERT INTO shop_entries
                (session_id, requester_name, item_name, color, variant, quantity, unit_price, note, owner_key)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($valid as $v) {
                $st->execute([$sid, $postedName, $v['item_name'], $v['color'], $v['variant'], $v['quantity'], $v['unit_price'], $v['note'], $owner]);
            }
            $pdo->commit();
            flash('success', count($valid) . '件の依頼を登録しました。');
            redirect($self . '#mine');
        }
    } elseif ($action === 'update' || $action === 'delete') {
        $entry = find_entry($sid, (int)($_POST['entry_id'] ?? 0));
        if (!$entry || !hash_equals($entry['owner_key'], $owner)) {
            flash('error', 'この依頼は編集できません（別のブラウザで登録した依頼は代行者に変更を依頼してください）。');
            redirect($self);
        }
        if ($action === 'delete') {
            db()->prepare('DELETE FROM shop_entries WHERE id = ?')->execute([$entry['id']]);
            flash('success', '依頼を削除しました。');
            redirect($self . '#mine');
        }
        $name = input_str($_POST, 'requester_name', 50);
        if ($name === '') {
            $errors[] = 'お名前を入力してください。';
        }
        $v = validate_entry($_POST, $errors);
        if (!$errors) {
            db()->prepare('UPDATE shop_entries SET requester_name=?, item_name=?, color=?, variant=?, quantity=?, unit_price=?, note=? WHERE id=?')
                ->execute([$name, $v['item_name'], $v['color'], $v['variant'], $v['quantity'], $v['unit_price'], $v['note'], $entry['id']]);
            flash('success', '依頼を更新しました。');
            redirect($self . '#mine');
        }
        $_GET['edit'] = (string)$entry['id'];
    }
}

// 編集モード
$editing = null;
if ($open && isset($_GET['edit'])) {
    $e = find_entry($sid, (int)$_GET['edit']);
    if ($e && hash_equals($e['owner_key'], $owner)) {
        $editing = $_SERVER['REQUEST_METHOD'] === 'POST' ? array_merge($e, $_POST) : $e;
    }
}

$mine = fetch_entries($sid, $owner);
$mineByPerson = group_by_person($mine);
$mineTotal = summarize($mine);

render_header($session['title']);
?>
<section class="card session-head">
  <h1><?= h($session['title']) ?></h1>
  <?php if ($session['description'] !== null && $session['description'] !== ''): ?>
    <div class="description"><?= nl2br(h($session['description'])) ?></div>
  <?php endif; ?>
  <p class="meta">
    <?php if ($session['deadline']): ?>
      受付締切: <strong><?= h(date('Y/m/d H:i', strtotime($session['deadline']))) ?></strong>
    <?php endif; ?>
    <?php if ($open): ?><span class="badge badge-open">受付中</span><?php else: ?><span class="badge badge-closed">受付終了</span><?php endif; ?>
  </p>
</section>

<?php if ($editing): ?>
<section class="card">
  <h2>依頼を編集</h2>
  <?php render_errors($errors); ?>
  <form method="post" action="<?= h($self) ?>" data-entry-form>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="update">
    <input type="hidden" name="entry_id" value="<?= (int)$editing['id'] ?>">
    <label>お名前 <span class="req">必須</span>
      <input type="text" name="requester_name" value="<?= h($editing['requester_name']) ?>" maxlength="50" required>
    </label>
    <?php render_entry_fields('', $editing); ?>
    <div class="actions">
      <a class="btn btn-ghost" href="<?= h($self) ?>#mine">キャンセル</a>
      <button type="submit" class="btn btn-primary">更新する</button>
    </div>
  </form>
</section>
<?php elseif ($open): ?>
<section class="card">
  <h2>依頼を入力する</h2>
  <?php render_errors($errors); ?>
  <form method="post" action="<?= h($self) ?>" data-entry-form>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add">
    <label>1. お名前（誰が） <span class="req">必須</span>
      <input type="text" name="requester_name" value="<?= h($postedName) ?>" maxlength="50" required placeholder="例: さや / @oshi_love" data-remember-name>
    </label>
    <p class="muted small">2.〜4. 欲しいグッズを入力してください。複数ある場合は「＋ 商品を追加」で行を増やせます。</p>
    <div data-rows>
      <?php foreach ($postedRows as $i => $r) {
          render_entry_fields("rows[$i]", $r, true);
      } ?>
    </div>
    <template data-row-template>
      <?php render_entry_fields('rows[__INDEX__]', [], true); ?>
    </template>
    <button type="button" class="btn btn-ghost btn-block" data-add-row>＋ 商品を追加</button>
    <div class="form-total">合計 <strong data-form-total>¥0</strong></div>
    <button type="submit" class="btn btn-primary btn-block">この内容で依頼する</button>
  </form>
</section>
<?php else: ?>
<section class="card">
  <p>このセッションの受付は終了しました。内容の変更は代行者に直接連絡してください。</p>
</section>
<?php endif; ?>

<section class="card" id="mine">
  <h2>あなたの依頼</h2>
  <?php if (!$mine): ?>
    <p class="muted">まだ依頼はありません。</p>
  <?php else: ?>
    <?php foreach ($mineByPerson as $p): ?>
      <?php if (count($mineByPerson) > 1): ?><h3><?= h($p['name']) ?> さん</h3><?php endif; ?>
      <div class="table-wrap">
      <table class="table">
        <thead><tr><th>商品名</th><th>色 / 型</th><th class="num">個数</th><th class="num">単価</th><th class="num">小計</th><?php if ($open): ?><th></th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach ($p['entries'] as $e): ?>
          <tr>
            <td><?= h($e['item_name']) ?><?php if ($e['note'] !== ''): ?><div class="note"><?= h($e['note']) ?></div><?php endif; ?></td>
            <td><?= h(item_label($e)) ?></td>
            <td class="num"><?= (int)$e['quantity'] ?></td>
            <td class="num"><?= yen($e['unit_price']) ?></td>
            <td class="num"><?= yen((int)$e['quantity'] * (int)$e['unit_price']) ?></td>
            <?php if ($open): ?>
            <td class="row-actions">
              <a class="btn btn-ghost btn-sm" href="<?= h(url('s.php', ['id' => $publicId, 'edit' => $e['id']])) ?>">編集</a>
              <form method="post" action="<?= h($self) ?>" data-confirm="この依頼を削除しますか？">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="entry_id" value="<?= (int)$e['id'] ?>">
                <button class="btn btn-danger btn-sm">削除</button>
              </form>
            </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><th colspan="2">合計</th><th class="num"><?= (int)$p['quantity'] ?></th><th></th><th class="num"><?= yen($p['total']) ?></th><?php if ($open): ?><th></th><?php endif; ?></tr></tfoot>
      </table>
      </div>
    <?php endforeach; ?>
    <?php if (count($mineByPerson) > 1): ?>
      <p class="grand-total">総合計 <?= (int)$mineTotal['quantity'] ?>点 <strong><?= yen($mineTotal['total']) ?></strong></p>
    <?php endif; ?>
    <p class="muted small">※ここには、このブラウザから登録した依頼だけが表示されます。別の端末から登録した分の確認・変更は代行者に連絡してください。</p>
  <?php endif; ?>
</section>
<?php
render_footer();
