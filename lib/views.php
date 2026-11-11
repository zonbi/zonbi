<?php
declare(strict_types=1);

function asset(string $path): string
{
    $file = dirname(__DIR__) . '/' . $path;
    $v = is_file($file) ? (string)filemtime($file) : '1';
    return url($path, ['v' => $v]);
}

function render_header(string $title, string $bodyClass = ''): void
{
    $app = config('app_name', '代行さん');
    header('Content-Type: text/html; charset=UTF-8');
    ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="same-origin">
<title><?= h($title === $app ? $app : "$title | $app") ?></title>
<link rel="stylesheet" href="<?= h(asset('assets/style.css')) ?>">
</head>
<body class="<?= h($bodyClass) ?>">
<header class="site-header">
  <a class="brand" href="<?= h(url('index.php')) ?>">🛍️ <?= h($app) ?></a>
  <?php if (google_enabled()): ?>
  <nav class="user-nav">
    <?php if ($u = current_user()): ?>
      <a href="<?= h(url('mypage.php')) ?>">マイページ</a>
      <form method="post" action="<?= h(url('logout.php')) ?>">
        <?= csrf_field() ?>
        <button type="submit" class="linklike">ログアウト</button>
      </form>
    <?php else: ?>
      <a class="btn btn-google btn-sm" href="<?= h(login_url()) ?>">代行者ログイン</a>
    <?php endif; ?>
  </nav>
  <?php endif; ?>
</header>
<main class="container">
<?php foreach (take_flashes() as $f): ?>
  <div class="flash flash-<?= h($f['type']) ?>"><?= h($f['message']) ?></div>
<?php endforeach;
}

function render_footer(): void
{
    ?>
</main>
<footer class="site-footer">
  <nav class="footer-links">
    <a href="<?= h(url('index.php')) ?>">トップ</a>
    <a href="<?= h(url('terms.php')) ?>">利用規約・免責事項</a>
    <a href="<?= h(url('privacy.php')) ?>">プライバシーポリシー</a>
    <a href="<?= h(url('about.php')) ?>">運営者情報</a>
  </nav>
  <?php if (affiliate_enabled()): ?>
  <p class="aff-disclosure">当サイトはアフィリエイトプログラムを利用しており、商品リンク（PR）経由の購入により運営者が収益を得ることがあります。<?php if (amazon_enabled()): ?><br>Amazonのアソシエイトとして、<?= h(operator('name') !== '' ? operator('name') : '当サイト運営者') ?>は適格販売により収入を得ています。<?php endif; ?></p>
  <?php endif; ?>
  <p>© <?= date('Y') ?> <?= h(operator('name') !== '' ? operator('name') : config('app_name', '代行さん')) ?></p>
</footer>
<script src="<?= h(asset('assets/app.js')) ?>"></script>
</body>
</html>
<?php
}

function render_errors(array $errors): void
{
    if (!$errors) {
        return;
    }
    echo '<div class="flash flash-error"><ul>';
    foreach ($errors as $e) {
        echo '<li>' . h($e) . '</li>';
    }
    echo '</ul></div>';
}

/**
 * 依頼1件ぶんの入力欄。$prefix は name 属性の接頭辞（例: "rows[0]"）。
 */
function render_entry_fields(string $prefix, array $v = [], bool $removable = false): void
{
    $n = fn(string $k) => $prefix === '' ? $k : $prefix . '[' . $k . ']';
    $qty = $v['quantity'] ?? '1';
    $price = $v['unit_price'] ?? '';
    ?>
<fieldset class="entry-row" data-entry-row>
  <div class="grid">
    <label class="span-2">商品名 <span class="req">必須</span>
      <input type="text" name="<?= h($n('item_name')) ?>" value="<?= h($v['item_name'] ?? '') ?>" maxlength="200" placeholder="例: アクリルスタンド">
    </label>
    <label>色
      <input type="text" name="<?= h($n('color')) ?>" value="<?= h($v['color'] ?? '') ?>" maxlength="100" placeholder="例: 青 / ○○担当カラー">
    </label>
    <label>型・種類
      <input type="text" name="<?= h($n('variant')) ?>" value="<?= h($v['variant'] ?? '') ?>" maxlength="100" placeholder="例: Aタイプ / Mサイズ">
    </label>
    <label>個数 <span class="req">必須</span>
      <input type="number" name="<?= h($n('quantity')) ?>" value="<?= h($qty) ?>" min="1" max="9999" inputmode="numeric" data-qty>
    </label>
    <label>単価（円） <span class="req">必須</span>
      <input type="number" name="<?= h($n('unit_price')) ?>" value="<?= h($price) ?>" min="0" max="10000000" inputmode="numeric" placeholder="例: 1500" data-price>
    </label>
    <label class="span-2">メモ
      <input type="text" name="<?= h($n('note')) ?>" value="<?= h($v['note'] ?? '') ?>" maxlength="500" placeholder="例: 売り切れなら不要 / 第2希望は赤">
    </label>
  </div>
  <div class="row-foot">
    <span class="row-total">小計 <strong data-row-total>¥0</strong></span>
    <?php if ($removable): ?>
      <button type="button" class="btn btn-ghost btn-sm" data-remove-row>この行を削除</button>
    <?php endif; ?>
  </div>
</fieldset>
<?php
}

function item_label(array $e): string
{
    $parts = array_filter([$e['color'] ?? '', $e['variant'] ?? ''], fn($s) => $s !== '');
    return $parts ? implode(' / ', $parts) : '—';
}

/** 商品ごとの「Amazon / 楽天で探す」リンク（設定がなければ何も出さない） */
function affiliate_links(array $e): string
{
    if (!affiliate_enabled()) {
        return '';
    }
    $kw = affiliate_keyword($e);
    if ($kw === '') {
        return '';
    }
    $attr = ' target="_blank" rel="sponsored nofollow noopener"';
    $links = [];
    if (amazon_enabled()) {
        $links[] = '<a class="aff aff-amazon" href="' . h(amazon_search_url($kw)) . '"' . $attr . '>Amazonで探す</a>';
    }
    if (rakuten_enabled()) {
        $links[] = '<a class="aff aff-rakuten" href="' . h(rakuten_search_url($kw)) . '"' . $attr . '>楽天で探す</a>';
    }
    return '<div class="aff-links"><span class="pr">PR</span>' . implode('', $links) . '</div>';
}
