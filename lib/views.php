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
<?php if (analytics_allowed()): ?>
<script async src="https://www.googletagmanager.com/gtm.js?id=GTM-5TG69DSV"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
</script>
<script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-2856922787390059"
     crossorigin="anonymous"></script>
<?php endif; ?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="same-origin">
<title><?= h($title === $app ? $app : "$title | $app") ?></title>
<?php $ogDesc = '推し活グッズの買い物代行、注文をURLひとつでまとめよう。誰が・何を・何個を入力するだけで、合計や買い物リストを自動で整理します。'; ?>
<meta name="description" content="<?= h($ogDesc) ?>">
<meta property="og:type" content="website">
<meta property="og:site_name" content="<?= h($app) ?>">
<meta property="og:title" content="<?= h($title === $app ? $app : "$title | $app") ?>">
<meta property="og:description" content="<?= h($ogDesc) ?>">
<meta property="og:image" content="<?= h(url('assets/img/ogp.png')) ?>">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta name="twitter:card" content="summary_large_image">
<link rel="icon" href="<?= h(url('assets/img/favicon.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= h(asset('assets/style.css')) ?>">
<?php if ($theme = current_theme()): ?><link rel="stylesheet" href="<?= h(asset('assets/themes/' . $theme . '.css')) ?>"><?php endif; ?>
</head>
<?php $GLOBALS['show_ads'] = basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) !== 'console.php'; ?>
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
<div class="layout">
<?= $GLOBALS['show_ads'] ? ad_slot('left', 'ad-side') : '' ?>
<main class="container">
<?php foreach (take_flashes() as $f): ?>
  <div class="flash flash-<?= h($f['type']) ?>"><?= h($f['message']) ?></div>
<?php endforeach;
}

function render_footer(): void
{
    ?>
</main>
<?= $GLOBALS['show_ads'] ? ad_slot('right', 'ad-side') : '' ?>
</div>
<?= $GLOBALS['show_ads'] ? ad_slot('bottom', 'ad-bottom') : '' ?>
<footer class="site-footer">
  <nav class="footer-links">
    <a href="<?= h(url('index.php')) ?>">トップ</a>
    <a href="<?= h(url('terms.php')) ?>">利用規約・免責事項</a>
    <a href="<?= h(url('privacy.php')) ?>">プライバシーポリシー</a>
    <a href="<?= h(url('about.php')) ?>">運営者情報</a>
  </nav>
  <?php if (affiliate_enabled() || has_ad_slots() || analytics_allowed()): ?>
  <p class="aff-disclosure">当サイトは広告（Google AdSense・アフィリエイトプログラム等）を掲載しており、広告経由の購入等により運営者が収益を得ることがあります。<?php if (amazon_enabled() || ads_have_amazon_tag()): ?><br>Amazonのアソシエイトとして、<?= h(operator('name') !== '' ? operator('name') : '当サイト運営者') ?>は適格販売により収入を得ています。<?php endif; ?></p>
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
 * 依頼1件ぶんの入力欄。$prefix は name 属性の接頭辞（例: "rows[0]"）。$ff は入力項目設定。
 */
function render_entry_fields(string $prefix, array $v = [], bool $removable = false, ?array $ff = null): void
{
    $ff ??= default_form_fields();
    $n = fn(string $k) => $prefix === '' ? $k : $prefix . '[' . $k . ']';
    $badge = fn(string $k) => field_required($ff, $k) ? ' <span class="req">必須</span>' : '';
    $qty = $v['quantity'] ?? '1';
    $price = $v['unit_price'] ?? '';
    ?>
<fieldset class="entry-row" data-entry-row>
  <div class="grid">
    <label class="span-2 f-item">商品名 <span class="req">必須</span>
      <input type="text" name="<?= h($n('item_name')) ?>" value="<?= h($v['item_name'] ?? '') ?>" maxlength="200" placeholder="例: アクリルスタンド">
    </label>
    <?php if (field_on($ff, 'color')): ?>
    <label>色<?= $badge('color') ?>
      <input type="text" name="<?= h($n('color')) ?>" value="<?= h($v['color'] ?? '') ?>" maxlength="100" placeholder="例: 青 / ○○担当カラー">
    </label>
    <?php endif; ?>
    <?php if (field_on($ff, 'size')): ?>
    <label>サイズ<?= $badge('size') ?>
      <input type="text" name="<?= h($n('size')) ?>" value="<?= h($v['size'] ?? '') ?>" maxlength="100" placeholder="例: M / L / フリー">
    </label>
    <?php endif; ?>
    <?php if (field_on($ff, 'shape')): ?>
    <label>形（型・種類）<?= $badge('shape') ?>
      <input type="text" name="<?= h($n('variant')) ?>" value="<?= h($v['variant'] ?? '') ?>" maxlength="100" placeholder="例: Aタイプ / 丸型">
    </label>
    <?php endif; ?>
    <label>個数 <span class="req">必須</span>
      <input type="number" name="<?= h($n('quantity')) ?>" value="<?= h($qty) ?>" min="1" max="9999" inputmode="numeric" data-qty>
    </label>
    <?php if (field_on($ff, 'price')): ?>
    <label>価格（円）<?= $badge('price') ?>
      <input type="number" name="<?= h($n('unit_price')) ?>" value="<?= h($price) ?>" min="0" max="10000000" inputmode="numeric" placeholder="<?= field_required($ff, 'price') ? '例: 1500' : '未定なら空欄' ?>" data-price>
    </label>
    <?php endif; ?>
    <?php if (field_on($ff, 'note')): ?>
    <label class="span-2 f-note">コメント<?= $badge('note') ?>
      <input type="text" name="<?= h($n('note')) ?>" value="<?= h($v['note'] ?? '') ?>" maxlength="500" placeholder="例: 売り切れなら不要 / 第2希望は赤">
    </label>
    <?php endif; ?>
  </div>
  <div class="row-foot">
    <?php if ($ff['subtotal']): ?><span class="row-total">小計 <strong data-row-total>¥0</strong></span><?php else: ?><span></span><?php endif; ?>
    <?php if ($removable): ?>
      <button type="button" class="btn btn-ghost btn-sm" data-remove-row>この行を削除</button>
    <?php endif; ?>
  </div>
</fieldset>
<?php
}

/** 依頼者のお名前・SNS欄 */
function render_requester_fields(array $ff, string $name, string $sns, bool $remember = false, string $numberPrefix = ''): void
{
    ?>
    <label><?= h($numberPrefix) ?>お名前（誰が） <span class="req">必須</span>
      <input type="text" name="requester_name" value="<?= h($name) ?>" maxlength="50" required placeholder="例: さや"<?= $remember ? ' data-remember-name' : '' ?>>
    </label>
    <?php if (field_on($ff, 'sns')):
        // 入力エラーで戻ってきたとき（POST値）と、保存済みの値（種類:アカウント名）の両方に対応
        $postedType = (string)($_POST['requester_sns_type'] ?? '');
        [$type, $handle] = $postedType !== '' || isset($_POST['requester_sns']) ? [$postedType, $sns] : parse_sns($sns); ?>
    <fieldset class="sns-field" data-sns-field>
      <legend>SNSアカウント名<?= field_required($ff, 'sns') ? ' <span class="req">必須</span>' : '' ?></legend>
      <div class="seg sns-seg" role="radiogroup" aria-label="SNSの種類">
        <?php foreach (SNS_TYPES as $k => $t): ?>
          <label><input type="radio" name="requester_sns_type" value="<?= h($k) ?>"<?= $type === $k ? ' checked' : '' ?><?= $remember ? ' data-remember-sns-type' : '' ?>><span><?= h($t['label']) ?></span></label>
        <?php endforeach; ?>
      </div>
      <span class="input-prefix"><span>@</span><input type="text" name="requester_sns" value="<?= h($handle) ?>" maxlength="60" placeholder="アカウント名（例: oshi_love）" autocomplete="off" autocapitalize="off" spellcheck="false" aria-label="アカウント名"<?= field_required($ff, 'sns') ? ' required' : '' ?><?= $remember ? ' data-remember-sns' : '' ?>></span>
    </fieldset>
    <?php endif;
}

function item_label(array $e): string
{
    $parts = array_filter([$e['color'] ?? '', $e['size'] ?? '', $e['variant'] ?? ''], fn($s) => (string)$s !== '');
    return $parts ? implode(' / ', $parts) : '—';
}

/** キーワードの「Amazon / 楽天で探す」リンク（設定がなければ何も出さない）。下部の「関連商品」欄で使用 */
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
    return '<span class="aff-links">' . implode('', $links) . '</span>';
}

// ---------------------------------------------------------------- 広告エリア

/** ads/<name>.html の中身（運営者が貼るバナーコード）。なければ空文字 */
function ad_html(string $name): string
{
    $file = dirname(__DIR__) . '/ads/' . $name . '.html';
    if (!is_file($file)) {
        return '';
    }
    $html = trim((string)file_get_contents($file));
    // サンプルのコメントだけのファイルは空扱い
    return trim(preg_replace('/<!--.*?-->/s', '', $html) ?? '') === '' ? '' : $html;
}

/** 枠の中身が広告か（data-no-pr を付けたお知らせ・お願い等は広告として扱わない） */
function ad_is_promo(string $html): bool
{
    return $html !== '' && stripos($html, 'data-no-pr') === false;
}

function has_ad_slots(): bool
{
    foreach (['left', 'right', 'bottom'] as $n) {
        if (ad_is_promo(ad_html($n))) {
            return true;
        }
    }
    return false;
}

/** 広告枠（左右・下部）。中身がなければ何も出さない */
function ad_slot(string $name, string $class): string
{
    $html = ad_html($name);
    if ($html === '') {
        return '';
    }
    if (!ad_is_promo($html)) {
        // 広告ではないお知らせ（運営者へのお礼のお願いなど）：「PR」表示なし
        return '<aside class="ad ' . h($class) . ' ad-' . h($name) . ' ad-notice" aria-label="お知らせ"><div class="ad-body">' . $html . '</div></aside>';
    }
    return '<aside class="ad ' . h($class) . ' ad-' . h($name) . '" aria-label="広告"><span class="ad-label">PR</span><div class="ad-body">' . $html . '</div></aside>';
}

/** 単価の表示（未定なら「未定」） */
function price_html($price): string
{
    return ($price === null || $price === '') ? '<span class="tbd">未定</span>' : h(yen($price));
}

/** 小計の表示 */
function line_total_html(array $e): string
{
    $t = line_total($e);
    return $t === null ? '<span class="tbd">未定</span>' : h(yen($t));
}

/** 合計の表示（単価未定を含む場合は注記） */
function total_html(int $total, int $unpriced): string
{
    return h(yen($total)) . ($unpriced > 0 ? '<span class="tbd-note">＋単価未定' . $unpriced . '件</span>' : '');
}

/** 使用中のデザインテーマ（config の theme。assets/themes/<名前>.css） */
function current_theme(): string
{
    $t = (string)config('theme', 'sakura');
    return preg_match('/^[a-z0-9_-]{1,32}$/', $t) && is_file(dirname(__DIR__) . '/assets/themes/' . $t . '.css') ? $t : '';
}

/** 「色 / サイズ / 形」列の見出し（どれも表示しない設定なら空文字） */
function detail_header(array $ff): string
{
    $parts = [];
    foreach (['color' => '色', 'size' => 'サイズ', 'shape' => '形'] as $k => $lab) {
        if (field_on($ff, $k)) {
            $parts[] = $lab;
        }
    }
    return implode(' / ', $parts);
}

/** SVGをページに直接埋め込む（テーマの色を反映させるため） */
function inline_svg(string $path): string
{
    $file = dirname(__DIR__) . '/' . $path;
    if (!is_file($file)) {
        return '';
    }
    $svg = (string)file_get_contents($file);
    return preg_replace('/^<\?xml[^>]*>\s*/', '', $svg) ?? '';
}

/**
 * アクセス解析（Googleタグマネージャー）・広告（Google AdSense）を読み込むページか。
 * 管理キーなど秘密の値がURLに含まれるページ（管理ページ・似た商品まとめ・運営者コンソール等）では、
 * URLがGoogleに送信されないよう読み込まない。
 */
function analytics_allowed(): bool
{
    $page = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if (in_array($page, ['admin.php', 'items.php', 'console.php', 'mypage.php', 'export.php', 'google_callback.php', 'login.php'], true)) {
        return false;
    }
    foreach (['key', 'me', 'code', 'state'] as $secret) {
        if (isset($_GET[$secret])) {
            return false;
        }
    }
    return true;
}

/** 広告枠（ads/*.html）のどれかに指定の文字列が含まれるか（Amazonの広告表記の判定用） */
function ads_contain(string $needle): bool
{
    foreach (['left', 'right', 'bottom'] as $n) {
        if (stripos(ad_html($n), $needle) !== false) {
            return true;
        }
    }
    return false;
}

/** 広告枠にAmazonアソシエイトのリンク（tag=付き）があるか */
function ads_have_amazon_tag(): bool
{
    foreach (['left', 'right', 'bottom'] as $n) {
        if (preg_match('#amazon\.[^"\'\s]*[?&](amp;)?tag=#i', ad_html($n))) {
            return true;
        }
    }
    return false;
}
