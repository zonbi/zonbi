<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

// 似た商品まとめ（代行者用・別ウィンドウ）: items.php?id=共有ID&key=管理キー
$session = find_session((string)($_GET['id'] ?? ''));
$key = (string)($_GET['key'] ?? '');
if (!$session || !can_manage_session($session, $key)) {
    not_found('管理用URLが正しくありません。');
}
$sid = (int)$session['id'];
$base = is_admin_key_valid($session, $key) ? ['id' => $session['public_id'], 'key' => $key] : ['id' => $session['public_id']];
$sort = ($_GET['sort'] ?? '') === 'name' ? 'name' : 'qty';
$self = url('items.php', $base + ['sort' => $sort]);
$manualReady = group_label_ready();
$ff = form_fields($session);
$showPrice = field_on($ff, 'price');

/** POSTされた依頼IDのうち、このセッションのものだけ返す */
function posted_entry_ids(int $sid): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['entry_ids'] ?? [])))));
    if (!$ids) {
        return [];
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = db()->prepare("SELECT id FROM shop_entries WHERE session_id = ? AND id IN ($in)");
    $st->execute(array_merge([$sid], $ids));
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

function set_group_label(array $ids, ?string $label): void
{
    if (!$ids) {
        return;
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    db()->prepare("UPDATE shop_entries SET group_label = ? WHERE id IN ($in)")->execute(array_merge([$label], $ids));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'toggle_purchased') {
        db()->prepare('UPDATE shop_entries SET is_purchased = 1 - is_purchased WHERE id = ? AND session_id = ?')
            ->execute([(int)($_POST['entry_id'] ?? 0), $sid]);
        redirect($self . '#' . preg_replace('/[^a-z0-9-]/', '', (string)($_POST['anchor'] ?? '')));
    }
    if (!$manualReady) {
        flash('error', 'まとめ直し機能にはデータベースの更新が必要です。phpMyAdmin で sql/migrations/006_add_group_label.sql を実行してください。');
        redirect($self);
    }
    $ids = posted_entry_ids($sid);
    switch ($action) {
        case 'assign':
            $target = (string)($_POST['target'] ?? '');
            $label = $target === '__new__' ? input_str($_POST, 'new_label', 200) : input_str(['t' => $target], 't', 200);
            if (!$ids) {
                flash('error', 'まとめ直す依頼にチェックを入れてください。');
            } elseif ($label === '') {
                flash('error', '移動先のまとめ名を選ぶか、新しいまとめ名を入力してください。');
            } else {
                set_group_label($ids, $label);
                flash('success', count($ids) . '件を「' . $label . '」にまとめました。');
            }
            break;
        case 'rename':
            $label = input_str($_POST, 'label', 200);
            if ($ids && $label !== '') {
                set_group_label($ids, $label);
                flash('success', 'まとめ名を「' . $label . '」にしました。');
            }
            break;
        case 'reset':
            set_group_label($ids, null);
            flash('success', '自動のまとめ方に戻しました。');
            break;
    }
    redirect($self);
}

$entries = fetch_entries($sid);
$groups = group_similar_items($entries);
if ($sort === 'name') {
    usort($groups, fn($a, $b) => group_norm($a['label']) <=> group_norm($b['label']));
} else {
    usort($groups, fn($a, $b) => [$b['quantity'], group_norm($a['label'])] <=> [$a['quantity'], group_norm($b['label'])]);
}
$sum = summarize($entries);

render_header('似た商品まとめ: ' . $session['title'], 'items-page');
?>
<section class="card items-head">
  <p class="eyebrow">似た商品まとめ（買い物リスト）</p>
  <h1><?= h($session['title']) ?></h1>
  <p class="small muted">表記の揺れ（カタカナ/ひらがな、全角/半角、空白・記号など）を吸収して、似た商品名をまとめて合計しています。
    まとめ方が違うときは、依頼にチェックを入れて下のバーから移動できます。</p>
  <div class="stats">
    <div><span>商品の種類</span><strong><?= count($groups) ?></strong></div>
    <div><span>総個数</span><strong><?= $sum['quantity'] ?>点</strong></div>
    <div><span>購入済み</span><strong><?= array_sum(array_column($groups, 'purchased')) ?>点</strong></div>
    <?php if ($showPrice): ?><div><span>総額</span><strong><?= total_html($sum['total'], $sum['unpriced']) ?></strong></div><?php endif; ?>
  </div>
  <div class="actions no-print">
    <a class="btn btn-ghost btn-sm<?= $sort === 'qty' ? ' is-on' : '' ?>" href="<?= h(url('items.php', $base + ['sort' => 'qty'])) ?>">個数の多い順</a>
    <a class="btn btn-ghost btn-sm<?= $sort === 'name' ? ' is-on' : '' ?>" href="<?= h(url('items.php', $base + ['sort' => 'name'])) ?>">名前順</a>
    <button type="button" class="btn btn-ghost btn-sm" onclick="window.print()">印刷</button>
    <a class="btn btn-ghost btn-sm" href="<?= h(url('admin.php', $base)) ?>">管理ページへ</a>
  </div>
  <?php if (!$manualReady): ?>
    <p class="flash flash-error small">手動でのまとめ直しには、phpMyAdmin で <code>sql/migrations/006_add_group_label.sql</code> の実行が必要です（自動のまとめは利用できます）。</p>
  <?php endif; ?>
</section>

<?php if (!$entries): ?>
  <section class="card"><p class="muted">まだ依頼はありません。</p></section>
<?php else: ?>
<form method="post" action="<?= h($self) ?>" id="regroup-form">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="assign">

  <?php foreach ($groups as $gi => $g): $anchor = 'g' . $gi; $done = $g['purchased'] >= $g['quantity']; ?>
  <section class="card group-card<?= $done ? ' is-done' : '' ?>" id="<?= $anchor ?>">
    <div class="group-head">
      <div class="group-title">
        <h2><?= h($g['label']) ?><?php if ($g['manual']): ?> <span class="badge badge-manual">手動</span><?php endif; ?></h2>
        <?php if (count($g['spellings']) > 1 || $g['manual']): ?>
          <p class="spellings"><?php foreach ($g['spellings'] as $sp => $q): ?><span><?= h($sp) ?> ×<?= (int)$q ?></span><?php endforeach; ?></p>
        <?php endif; ?>
      </div>
      <div class="group-total">
        <strong><?= (int)$g['quantity'] ?></strong><span>個</span>
        <div class="small muted"><?= count($g['people']) ?>人<?php if ($showPrice): ?>・<?= total_html($g['total'], $g['unpriced']) ?><?php endif; ?></div>
        <div class="progress" title="購入済み <?= (int)$g['purchased'] ?> / <?= (int)$g['quantity'] ?>"><i style="width: <?= $g['quantity'] ? round($g['purchased'] / $g['quantity'] * 100) : 0 ?>%"></i></div>
        <div class="small muted">購入済み <?= (int)$g['purchased'] ?> / <?= (int)$g['quantity'] ?></div>
      </div>
    </div>

    <?php $hasVariants = count(array_filter($g['variants'], fn($v) => $v['color'] !== '' || $v['variant'] !== '')) > 0; ?>
    <?php if ($hasVariants): ?>
      <p class="variant-chips"><?php foreach ($g['variants'] as $v): ?><span><?= h(item_label($v)) ?> <b>×<?= (int)$v['quantity'] ?></b></span><?php endforeach; ?></p>
    <?php endif; ?>

    <div class="table-wrap">
    <table class="table group-table">
      <thead><tr><th class="no-print">選択</th><th>依頼者</th><th>入力された商品名</th><th>色 / サイズ / 形</th><th class="num">個数</th><?php if ($showPrice): ?><th class="num">単価</th><?php endif; ?><th>購入</th></tr></thead>
      <tbody>
      <?php foreach ($g['entries'] as $e): ?>
        <tr class="<?= (int)$e['is_purchased'] === 1 ? 'done' : '' ?>">
          <td class="no-print"><input type="checkbox" name="entry_ids[]" value="<?= (int)$e['id'] ?>" form="regroup-form" aria-label="選択" data-pick></td>
          <td><?= h($e['requester_name']) ?><?php if (($e['requester_sns'] ?? '') !== ''): ?><div class="note"><?= sns_html($e['requester_sns']) ?></div><?php endif; ?></td>
          <td><?= h($e['item_name']) ?><?php if ($e['note'] !== ''): ?><div class="note"><?= h($e['note']) ?></div><?php endif; ?></td>
          <td><?= h(item_label($e)) ?></td>
          <td class="num"><?= (int)$e['quantity'] ?></td>
          <?php if ($showPrice): ?><td class="num"><?= price_html($e['unit_price']) ?></td><?php endif; ?>
          <td><button type="submit" form="tp<?= (int)$e['id'] ?>" class="check <?= (int)$e['is_purchased'] === 1 ? 'on' : '' ?>" aria-label="購入済みを切り替え"><?= (int)$e['is_purchased'] === 1 ? '✓' : '' ?></button></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>

    <?php if ($manualReady): ?>
    <details class="group-tools no-print">
      <summary>このグループのまとめ名を変更</summary>
      <div class="group-tools-row">
        <input type="text" name="label" value="<?= h($g['label']) ?>" maxlength="200" form="rn<?= $gi ?>" aria-label="まとめ名">
        <button type="submit" class="btn btn-ghost btn-sm" form="rn<?= $gi ?>">名前を変更</button>
        <?php if ($g['manual']): ?><button type="submit" class="btn btn-ghost btn-sm" form="rs<?= $gi ?>">自動のまとめ方に戻す</button><?php endif; ?>
      </div>
    </details>
    <?php endif; ?>
  </section>
  <?php endforeach; ?>

  <?php if ($manualReady): ?>
  <div class="regroup-bar no-print" data-regroup-bar>
    <span class="regroup-count"><b data-pick-count>0</b>件を選択中</span>
    <select name="target" data-target aria-label="移動先">
      <option value="">移動先のまとめ名を選ぶ…</option>
      <?php foreach ($groups as $g): ?><option value="<?= h($g['label']) ?>"><?= h($g['label']) ?>（<?= (int)$g['quantity'] ?>個）</option><?php endforeach; ?>
      <option value="__new__">＋ 新しいまとめ名…</option>
    </select>
    <input type="text" name="new_label" maxlength="200" placeholder="新しいまとめ名" data-new-label hidden>
    <button type="submit" class="btn btn-primary btn-sm">まとめる</button>
  </div>
  <?php endif; ?>
</form>

<?php /* 個別のフォーム（入れ子にできないため外に置き、form属性で関連付け） */ ?>
<?php foreach ($groups as $gi => $g): ?>
  <?php foreach ($g['entries'] as $e): ?>
    <form method="post" action="<?= h($self) ?>" id="tp<?= (int)$e['id'] ?>" hidden><?= csrf_field() ?><input type="hidden" name="action" value="toggle_purchased"><input type="hidden" name="entry_id" value="<?= (int)$e['id'] ?>"><input type="hidden" name="anchor" value="g<?= $gi ?>"></form>
  <?php endforeach; ?>
  <form method="post" action="<?= h($self) ?>" id="rn<?= $gi ?>" hidden><?= csrf_field() ?><input type="hidden" name="action" value="rename">
    <?php foreach ($g['entries'] as $e): ?><input type="hidden" name="entry_ids[]" value="<?= (int)$e['id'] ?>"><?php endforeach; ?></form>
  <form method="post" action="<?= h($self) ?>" id="rs<?= $gi ?>" hidden><?= csrf_field() ?><input type="hidden" name="action" value="reset">
    <?php foreach ($g['entries'] as $e): ?><input type="hidden" name="entry_ids[]" value="<?= (int)$e['id'] ?>"><?php endforeach; ?></form>
<?php endforeach; ?>
<?php endif; ?>
<?php
render_footer();
