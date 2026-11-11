<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

$app = config('app_name', '代行さん');

$link = function (string $u): string {
    return preg_match('#^https?://#i', $u)
        ? '<a href="' . h($u) . '" target="_blank" rel="noopener noreferrer">' . h($u) . '</a>'
        : h($u);
};

$rows = [];
$rows['サービス名'] = h($app);
$rows['運営者'] = operator('name') !== '' ? h(operator('name')) : '<span class="muted">（未設定）</span>';
if (operator('representative') !== '') {
    $rows['代表者'] = h(operator('representative'));
}
$rows['所在地'] = operator('address') !== ''
    ? nl2br(h(operator('address')))
    : '請求があった場合、遅滞なく開示いたします。';
$contact = [];
if (operator('email') !== '') {
    $e = operator('email');
    $contact[] = 'メール: <a href="mailto:' . h($e) . '">' . h($e) . '</a>';
}
if (operator('contact_url') !== '') {
    $contact[] = 'お問い合わせフォーム: ' . $link(operator('contact_url'));
}
$rows['お問い合わせ'] = $contact ? implode('<br>', $contact) : '<span class="muted">（未設定）</span>';
if (operator('website') !== '') {
    $rows['Webサイト'] = $link(operator('website'));
}
if (operator('sns') !== '') {
    $rows['公式SNS'] = $link(operator('sns'));
}
$rows['料金'] = '無料';

render_header('運営者情報');
?>
<article class="card doc">
  <h1>運営者情報</h1>
  <table class="info-table">
    <?php foreach ($rows as $label => $html): ?>
      <tr><th><?= h($label) ?></th><td><?= $html ?></td></tr>
    <?php endforeach; ?>
  </table>

  <h2>お問い合わせについて</h2>
  <p class="small">※代行の取引内容（支払い・受け渡し・キャンセル等）に関するお問い合わせには対応できません。代行者と直接ご連絡ください。<br>
  ※データ削除のご依頼は、対象ページのURLを添えてご連絡ください。</p>

  <p>ご利用にあたっては<a href="<?= h(url('terms.php')) ?>">利用規約・免責事項</a>をご確認ください。</p>
</article>
<?php
render_footer();
