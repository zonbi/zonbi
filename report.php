<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

// 不適切な内容の通報: report.php?id=共有ID
$session = find_session((string)($_GET['id'] ?? ''));
if (!$session) {
    not_found('このセッションは存在しないか、削除されました。');
}
$back = url('s.php', ['id' => $session['public_id']]);
if (!moderation_ready()) {
    flash('error', '現在、通報を受け付けできません。運営者情報の連絡先までご連絡ください。');
    redirect($back);
}

const REPORT_REASONS = [
    'illegal'   => '違法な物品・サービスの取引（薬物・武器・盗品など）',
    'fake'      => '偽造品・海賊版の取引',
    'resale'    => '転売目的・イベント規約違反',
    'privacy'   => '個人情報の無断掲載',
    'abuse'     => '誹謗中傷・不適切な内容',
    'other'     => 'その他',
];

$errors = [];
$reason = '';
$detail = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $reason = (string)($_POST['reason'] ?? '');
    $detail = input_str($_POST, 'detail', 1000);
    if (!isset(REPORT_REASONS[$reason])) {
        $errors[] = '通報の理由を選んでください。';
    }
    if (($_POST['website'] ?? '') !== '') {
        // ボット対策（人間には見えない欄に入力があれば無視）
        redirect($back);
    }
    // 連投防止: 同じIPから1時間に5件まで
    $st = db()->prepare('SELECT COUNT(*) FROM shop_reports WHERE reporter_ip = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)');
    $st->execute([client_ip()]);
    if ((int)$st->fetchColumn() >= 5) {
        $errors[] = '短時間に多くの通報が送信されました。しばらくしてからお試しください。';
    }
    if (!$errors) {
        db()->prepare('INSERT INTO shop_reports (session_id, reason, detail, reporter_ip) VALUES (?, ?, ?, ?)')
            ->execute([$session['id'], $reason, $detail, client_ip()]);
        notify_operator(
            '【通報】' . REPORT_REASONS[$reason],
            "セッション: {$session['title']}\n理由: " . REPORT_REASONS[$reason] . "\n詳細: {$detail}\n\n確認: " . url('console.php', ['view' => 'session', 'id' => $session['public_id']])
        );
        flash('success', '通報を受け付けました。ご協力ありがとうございます。内容を確認のうえ、必要に応じて対応します（個別の返信はしていません）。');
        redirect($back);
    }
}

render_header('不適切な内容の報告');
?>
<section class="card">
  <h1>不適切な内容の報告</h1>
  <p class="muted small">対象: <a href="<?= h($back) ?>"><?= h($session['title']) ?></a></p>
  <p class="small">違法な物品の取引など、<a href="<?= h(url('terms.php')) ?>" target="_blank">利用規約</a>に違反する利用を見つけた場合はお知らせください。運営者が内容を確認し、必要に応じて削除などの対応を行います。<br>
  ※代行の取引トラブル（未払い・未着など）は対象外です。代行者と直接ご連絡ください。<br>
  ※緊急性のある犯罪や危険がある場合は、警察（110番）または最寄りの警察署にご相談ください。</p>
  <?php render_errors($errors); ?>
  <form method="post" action="<?= h(url('report.php', ['id' => $session['public_id']])) ?>">
    <?= csrf_field() ?>
    <fieldset class="radio-group">
      <legend>理由 <span class="req">必須</span></legend>
      <?php foreach (REPORT_REASONS as $k => $label): ?>
        <label class="checkbox"><input type="radio" name="reason" value="<?= h($k) ?>"<?= $reason === $k ? ' checked' : '' ?> required> <?= h($label) ?></label>
      <?php endforeach; ?>
    </fieldset>
    <label>詳細（任意）
      <textarea name="detail" rows="4" maxlength="1000" placeholder="例: ○○さんの依頼のメモ欄に、違法な物品と思われる記載があります。"><?= h($detail) ?></textarea>
    </label>
    <div class="hp" aria-hidden="true"><label>Webサイト<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
    <div class="actions">
      <a class="btn btn-ghost" href="<?= h($back) ?>">戻る</a>
      <button type="submit" class="btn btn-danger">報告する</button>
    </div>
  </form>
</section>
<?php
render_footer();
