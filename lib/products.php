<?php
declare(strict_types=1);

/*
 * グッズ確認欄（イベントURL＋参考画像）
 *  - 代行者が管理ページで、グッズを確認してもらうための参考画像を登録する（価格・在庫は載せない）
 *  - 依頼の内容・個数・価格は、依頼者がグッズ確認欄を見て自分で入力する
 *  - 画像はサーバーで読み込み直して縮小・再保存する（位置情報などの埋め込み情報を除去、画像以外のファイルを排除）
 *  ※テーブル名は shop_products のまま（price 等の列は使用しない）
 */

const PRODUCT_IMAGE_MAX_BYTES = 8 * 1024 * 1024;  // アップロード上限 8MB
const PRODUCT_IMAGE_MAX_SIDE = 800;               // 保存時の最大辺(px)

/** 登録できない商品（チケットの不正転売防止など） */
const PRODUCT_BLOCKED_WORDS = ['チケット', '入場券', '観覧券', '整理券', '指定席', 'ticket'];

function products_ready(): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            db()->query('SELECT 1 FROM shop_products LIMIT 0');
            db()->query('SELECT product_id FROM shop_entries LIMIT 0');
            db()->query('SELECT allow_free_items FROM shop_sessions LIMIT 0');
            $ready = true;
        } catch (PDOException $e) {
            $ready = false;
        }
    }
    return $ready;
}

function fetch_products(int $sessionId, bool $activeOnly = false): array
{
    if (!products_ready()) {
        return [];
    }
    $sql = 'SELECT * FROM shop_products WHERE session_id = ?' . ($activeOnly ? ' AND is_active = 1' : '') . ' ORDER BY sort_order, id';
    $st = db()->prepare($sql);
    $st->execute([$sessionId]);
    return $st->fetchAll();
}

function find_product(int $sessionId, int $productId): ?array
{
    if (!products_ready() || $productId <= 0) {
        return null;
    }
    $st = db()->prepare('SELECT * FROM shop_products WHERE id = ? AND session_id = ?');
    $st->execute([$productId, $sessionId]);
    return $st->fetch() ?: null;
}

/** 「青, 赤、黄」→ ['青','赤','黄'] */
function parse_options(string $s): array
{
    $parts = preg_split('/[,，、\/／\n]+/u', $s) ?: [];
    $out = [];
    foreach ($parts as $p) {
        $p = mb_substr(trim($p), 0, 50);
        if ($p !== '' && !in_array($p, $out, true)) {
            $out[] = $p;
        }
    }
    return array_slice($out, 0, 30);
}

function product_dir(): string
{
    return dirname(__DIR__) . '/uploads/products';
}

function product_image_url(string $name): string
{
    return preg_match('/^[0-9a-f]{32}\.(webp|jpg)$/', $name) ? url('uploads/products/' . $name) : '';
}

function delete_product_image(string $name): void
{
    if (preg_match('/^[0-9a-f]{32}\.(webp|jpg)$/', $name)) {
        @unlink(product_dir() . '/' . $name);
    }
}

/** セッション削除時に、そのセッションの商品画像ファイルも消す */
function delete_session_product_images(int $sessionId): void
{
    foreach (fetch_products($sessionId) as $p) {
        delete_product_image($p['image']);
    }
}

/**
 * アップロードされた画像を検証・縮小して保存し、ファイル名を返す。
 * 画像がなければ ''。問題があれば RuntimeException。
 */
function save_product_image(?array $file): string
{
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return '';
    }
    if (($file['error'] ?? 0) !== UPLOAD_ERR_OK) {
        throw new RuntimeException(in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            ? '画像のサイズが大きすぎます（8MBまで）。' : '画像のアップロードに失敗しました。');
    }
    if (!is_uploaded_file($file['tmp_name']) || filesize($file['tmp_name']) > PRODUCT_IMAGE_MAX_BYTES) {
        throw new RuntimeException('画像のサイズが大きすぎます（8MBまで）。');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
        throw new RuntimeException('画像は JPEG・PNG・WebP・GIF のいずれかを選んでください。');
    }
    $info = @getimagesize($file['tmp_name']);
    if (!$info || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > 40000000) {
        throw new RuntimeException('画像を読み込めませんでした。別の画像でお試しください。');
    }
    if (!is_dir(product_dir())) {
        @mkdir(product_dir(), 0755, true);
    }
    $name = random_hex(16);

    if (!function_exists('imagecreatefromstring')) {
        // GDがないサーバー: JPEG/WebPのみ、検証したうえでそのまま保存
        if (!in_array($mime, ['image/jpeg', 'image/webp'], true)) {
            throw new RuntimeException('このサーバーでは JPEG または WebP の画像を選んでください。');
        }
        $name .= $mime === 'image/webp' ? '.webp' : '.jpg';
        if (!move_uploaded_file($file['tmp_name'], product_dir() . '/' . $name)) {
            throw new RuntimeException('画像を保存できませんでした。');
        }
        return $name;
    }

    $src = @imagecreatefromstring((string)file_get_contents($file['tmp_name']));
    if (!$src) {
        throw new RuntimeException('画像を読み込めませんでした。別の画像でお試しください。');
    }
    // スマホ写真の向き（EXIF）を補正
    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($file['tmp_name']);
        $rot = [3 => 180, 6 => -90, 8 => 90][(int)($exif['Orientation'] ?? 1)] ?? 0;
        if ($rot) {
            $src = imagerotate($src, $rot, 0) ?: $src;
        }
    }
    $w = imagesx($src);
    $h = imagesy($src);
    $scale = min(1, PRODUCT_IMAGE_MAX_SIDE / max($w, $h));
    $nw = max(1, (int)round($w * $scale));
    $nh = max(1, (int)round($h * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    $webp = function_exists('imagewebp');
    if ($webp) {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 255, 255, 255, 127));
    } else {
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    }
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    $name .= $webp ? '.webp' : '.jpg';
    $ok = $webp ? imagewebp($dst, product_dir() . '/' . $name, 82) : imagejpeg($dst, product_dir() . '/' . $name, 85);
    imagedestroy($src);
    imagedestroy($dst);
    if (!$ok) {
        throw new RuntimeException('画像を保存できませんでした。');
    }
    return $name;
}

/** 商品登録フォームの検証 */
function validate_product(array $src, array &$errors): array
{
    $p = [
        'name'           => input_str($src, 'p_name', 200),
        'description'    => input_str($src, 'p_description', 500),
        'price'          => null,
        'color_options'  => implode(', ', parse_options(input_str($src, 'p_color_options', 500))),
        'size_options'   => implode(', ', parse_options(input_str($src, 'p_size_options', 500))),
        'shape_options'  => implode(', ', parse_options(input_str($src, 'p_shape_options', 500))),
        'max_per_person' => null,
    ];
    if ($p['name'] === '') {
        $errors[] = 'タイトル（グッズ名）を入力してください。';
    }
    $raw = trim((string)($src['p_price'] ?? ''));
    if ($raw !== '') {
        $p['price'] = to_int_or_null($raw);
        if ($p['price'] === null || $p['price'] > 10000000) {
            $errors[] = '定価は 0〜10,000,000 の数字（円）で入力してください。';
        }
    }
    $raw = trim((string)($src['p_max'] ?? ''));
    if ($raw !== '') {
        $p['max_per_person'] = to_int_or_null($raw);
        if ($p['max_per_person'] === null || $p['max_per_person'] < 1 || $p['max_per_person'] > 999) {
            $errors[] = '1人あたりの上限は 1〜999 の数字で入力してください。';
        }
    }
    // チケット類・危険ワードは登録不可
    // 「チケットホルダー」などのグッズ名は除外してから判定
    $hay = normalize_for_scan(preg_replace('/(チケット|ticket)\s*(ホルダー|ケース|ファイル|風|型|柄|holder|case)/iu', '', $p['name'] . ' ' . $p['description']) ?? '');
    foreach (PRODUCT_BLOCKED_WORDS as $w) {
        if (mb_strpos($hay, normalize_for_scan($w)) !== false) {
            $errors[] = 'チケット・入場券の類は登録できません（チケット不正転売禁止法やイベントの規約に抵触するおそれがあるため）。';
            break;
        }
    }
    if (scan_ng_words([$p['name'], $p['description']])) {
        $errors[] = '登録できない語句が含まれています。';
    }
    return $p;
}

/** 依頼に使った商品の画像（小） */
function entry_thumb(array $e, array $productsById): string
{
    $pid = (int)($e['product_id'] ?? 0);
    if (!$pid || empty($productsById[$pid]['image'])) {
        return '';
    }
    $u = product_image_url($productsById[$pid]['image']);
    return $u === '' ? '' : '<img class="thumb" src="' . h($u) . '" alt="" loading="lazy">';
}

function products_by_id(array $products): array
{
    $out = [];
    foreach ($products as $p) {
        $out[(int)$p['id']] = $p;
    }
    return $out;
}

// ---------------------------------------------------------------- 管理ページ：操作

/** 管理ページの商品操作を処理する。処理したら true（呼び出し側でリダイレクト） */
function handle_product_action(string $action, int $sid, array &$flashForm): bool
{
    if (!in_array($action, ['product_save', 'product_toggle', 'product_delete', 'product_move', 'goods_event_url'], true)) {
        return false;
    }
    if ($action === 'goods_event_url') {
        // グッズ確認欄のイベントURL（参考画像の機能が未導入でも保存できる）
        $u = input_str($_POST, 'event_url', 500);
        if ($err = event_url_error($u)) {
            flash('error', $err);
        } elseif ($err = save_event_url($sid, $u)) {
            flash('error', $err);
        } else {
            moderate_session($sid, false);
            flash('success', $u === '' ? 'イベントURLを削除しました。' : 'イベントURLを保存しました。');
        }
        return true;
    }
    if (!products_ready()) {
        flash('error', '参考画像の登録にはデータベースの更新が必要です。phpMyAdmin で sql/migrations/008_add_products.sql を実行してください。');
        return true;
    }
    $pdo = db();
    $pid = (int)($_POST['product_id'] ?? 0);
    $product = $pid ? find_product($sid, $pid) : null;

    switch ($action) {
        case 'product_save':
            $errors = [];
            $p = validate_product($_POST, $errors);
            $hasFile = isset($_FILES['p_image']) && ($_FILES['p_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
            if ($hasFile && empty($_POST['p_rights'])) {
                $errors[] = '画像を登録するには「画像を掲載する権利があることを確認しました」にチェックしてください。';
            }
            $image = $product['image'] ?? '';
            if (!$errors && $hasFile) {
                try {
                    $new = save_product_image($_FILES['p_image']);
                    if ($new !== '') {
                        delete_product_image($image);
                        $image = $new;
                    }
                } catch (RuntimeException $e) {
                    $errors[] = $e->getMessage();
                }
            } elseif (!$errors && !empty($_POST['p_remove_image'])) {
                delete_product_image($image);
                $image = '';
            }
            if ($errors) {
                foreach ($errors as $m) {
                    flash('error', $m);
                }
                $flashForm = $_POST;
                return true;
            }
            $vals = [$p['name'], $p['description'], $p['price'], $p['color_options'], $p['size_options'], $p['shape_options'], $p['max_per_person'], $image];
            if ($product) {
                $pdo->prepare('UPDATE shop_products SET name=?, description=?, price=?, color_options=?, size_options=?, shape_options=?, max_per_person=?, image=? WHERE id=?')
                    ->execute(array_merge($vals, [$product['id']]));
                flash('success', '参考画像「' . $p['name'] . '」を更新しました。');
            } else {
                $st = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM shop_products WHERE session_id = ?');
                $st->execute([$sid]);
                $pdo->prepare('INSERT INTO shop_products (session_id, name, description, price, color_options, size_options, shape_options, max_per_person, image, sort_order) VALUES (?,?,?,?,?,?,?,?,?,?)')
                    ->execute(array_merge([$sid], $vals, [(int)$st->fetchColumn()]));
                flash('success', '参考画像「' . $p['name'] . '」を追加しました。');
            }
            return true;

        case 'product_toggle':
            if ($product) {
                $pdo->prepare('UPDATE shop_products SET is_active = 1 - is_active WHERE id = ?')->execute([$product['id']]);
                flash('success', '「' . $product['name'] . '」を' . ((int)$product['is_active'] === 1 ? '非表示にしました。' : '表示するようにしました。'));
            }
            return true;

        case 'product_delete':
            if ($product) {
                delete_product_image($product['image']);
                $pdo->prepare('UPDATE shop_entries SET product_id = NULL WHERE product_id = ?')->execute([$product['id']]);
                $pdo->prepare('DELETE FROM shop_products WHERE id = ?')->execute([$product['id']]);
                flash('success', '参考画像「' . $product['name'] . '」を削除しました。');
            }
            return true;

        case 'product_move':
            if ($product) {
                $list = fetch_products($sid);
                $ids = array_map(fn($x) => (int)$x['id'], $list);
                $i = array_search((int)$product['id'], $ids, true);
                $j = ($_POST['dir'] ?? '') === 'up' ? $i - 1 : $i + 1;
                if ($i !== false && isset($ids[$j])) {
                    [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
                    $st = $pdo->prepare('UPDATE shop_products SET sort_order = ? WHERE id = ?');
                    foreach ($ids as $n => $id) {
                        $st->execute([$n + 1, $id]);
                    }
                }
            }
            return true;

    }
    return false;
}

// ---------------------------------------------------------------- 管理ページ：表示

function render_product_manager(array $session, string $self, array $form = []): void
{
    $sid = (int)$session['id'];
    $ready = products_ready();
    $products = $ready ? fetch_products($sid) : [];
    $editId = (int)($_GET['product'] ?? ($form['product_id'] ?? 0));
    $editing = $editId ? find_product($sid, $editId) : null;
    $v = $form ?: ($editing ? ['p_name' => $editing['name'], 'p_description' => $editing['description']] : []);
    $open = $products || $editing || $form || isset($_GET['product']) || !empty($session['event_url']);
    ?>
<details class="card product-manager" id="products"<?= $open ? ' open' : '' ?>>
  <summary><strong>グッズ確認欄</strong><?php if ($products): ?> <span class="badge badge-open">参考画像 <?= count($products) ?>件</span><?php endif; ?></summary>
  <p class="small muted">依頼者がグッズを確認するための情報を載せる欄です（依頼者のページの上部に表示されます）。依頼の内容・個数・価格は、依頼者がこの欄を見て自分で入力します。</p>

  <form method="post" action="<?= h($self) ?>#products" class="goods-url-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="goods_event_url">
    <label>イベントURL（任意）
      <span class="field-help" id="event-url-help">場所やイベント限定グッズを購入しにいく場合は、場所やイベントのURLが分かりましたら入力してください。公式のグッズ紹介ページのURLもおすすめです。</span>
      <span class="copy-row"><input type="url" name="event_url" value="<?= h($session['event_url'] ?? '') ?>" aria-describedby="event-url-help" maxlength="500" inputmode="url" placeholder="例: https://example.com/event/goods"><button class="btn btn-primary btn-sm">保存</button></span>
    </label>
  </form>

  <?php if (!$ready): ?>
    <p class="small">参考画像を追加するには、phpMyAdmin で <code>sql/migrations/008_add_products.sql</code> の実行が必要です。</p>
</details>
  <?php return; endif; ?>
  <h3 class="goods-sub">参考画像</h3>
  <details class="legal-note">
    <summary>⚠ 参考画像を載せる前に確認してください</summary>
    <ul>
      <li>参考画像は<strong>グッズを確認してもらうためのもの</strong>です。価格や在庫は載せず、依頼者が自分で確認・入力する形にしています（「販売」と受け取られないようにするため）。</li>
      <li><strong>自分で撮影した写真など、掲載する権利のある画像</strong>を使ってください。公式サイトや他人のSNSの画像を無断で載せると著作権侵害になるおそれがあります。公式の画像を見てほしい場合は、画像ではなく<strong>公式ページのURL</strong>を上の「イベントURL」に入れるのが安全です。</li>
      <li>チケット・入場券の類は載せられません。イベントや販売元の規約（代理購入の禁止・購入数の制限など）に従ってください。詳しくは<a href="<?= h(url('terms.php')) ?>#products" target="_blank">利用規約</a>をご覧ください。</li>
    </ul>
  </details>

  <?php if ($products): ?>
  <ul class="product-admin-list">
    <?php foreach ($products as $i => $p): $img = product_image_url($p['image']); ?>
    <li class="<?= (int)$p['is_active'] === 1 ? '' : 'is-off' ?>">
      <div class="pa-thumb"><?php if ($img !== ''): ?><img src="<?= h($img) ?>" alt=""><?php else: ?><span>No Image</span><?php endif; ?></div>
      <div class="pa-body">
        <strong><?= h($p['name']) ?></strong><?php if ((int)$p['is_active'] !== 1): ?> <span class="badge badge-closed">非表示</span><?php endif; ?>
        <?php if ($p['description'] !== ''): ?><div class="small muted"><?= h($p['description']) ?></div><?php endif; ?>
      </div>
      <div class="pa-actions">
        <?= product_btn($self, 'product_move', $p['id'], '↑', 'btn-ghost', '', ['dir' => 'up'], $i === 0) ?>
        <?= product_btn($self, 'product_move', $p['id'], '↓', 'btn-ghost', '', ['dir' => 'down'], $i === count($products) - 1) ?>
        <a class="btn btn-ghost btn-sm" href="<?= h($self . (str_contains($self, '?') ? '&' : '?') . 'product=' . (int)$p['id']) ?>#product-form">編集</a>
        <?= product_btn($self, 'product_toggle', $p['id'], (int)$p['is_active'] === 1 ? '非表示' : '表示', 'btn-ghost') ?>
        <?= product_btn($self, 'product_delete', $p['id'], '削除', 'btn-danger', '「' . $p['name'] . '」を削除しますか？') ?>
      </div>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>

  <form method="post" action="<?= h($self) ?>#products" enctype="multipart/form-data" class="product-form" id="product-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="product_save">
    <?php if ($editing): ?><input type="hidden" name="product_id" value="<?= (int)$editing['id'] ?>"><?php endif; ?>
    <h3><?= $editing ? '「' . h($editing['name']) . '」を編集' : '参考画像を追加' ?></h3>
    <label>タイトル（グッズ名） <span class="req">必須</span>
      <input type="text" name="p_name" value="<?= h($v['p_name'] ?? '') ?>" maxlength="200" required placeholder="例: 万博すごろく">
    </label>
    <label>メモ
      <input type="text" name="p_description" value="<?= h($v['p_description'] ?? '') ?>" maxlength="500" placeholder="例: 会場限定。色は青・赤の2種類">
    </label>
    <div class="image-field">
      <?php if ($editing && product_image_url($editing['image']) !== ''): ?>
        <img class="current-img" src="<?= h(product_image_url($editing['image'])) ?>" alt="現在の画像">
        <label class="checkbox"><input type="checkbox" name="p_remove_image" value="1"> 画像を外す</label>
      <?php endif; ?>
      <label>画像（JPEG・PNG・WebP・GIF、8MBまで）
        <input type="file" name="p_image" accept="image/jpeg,image/png,image/webp,image/gif" data-image-input>
      </label>
      <img class="preview-img" alt="" hidden data-image-preview>
      <label class="checkbox rights"><input type="checkbox" name="p_rights" value="1"> 画像を掲載する権利があることを確認しました（自分で撮影した写真など）</label>
      <p class="small muted">画像は自動で縮小し、撮影場所などの情報は取り除いて保存します。</p>
    </div>
    <div class="actions">
      <?php if ($editing): ?><a class="btn btn-ghost" href="<?= h($self) ?>#products">キャンセル</a><?php endif; ?>
      <button class="btn btn-primary"><?= $editing ? '更新する' : '追加する' ?></button>
    </div>
  </form>
</details>
<?php
}

/** 依頼者ページのグッズ確認欄（イベントURL＋参考画像）。何もなければ表示しない */
function render_goods_section(array $session, array $goods): void
{
    $url = (string)($session['event_url'] ?? '');
    if ($url === '' && !$goods) {
        return;
    }
    ?>
<section class="card goods-section" id="goods">
  <h2>グッズ確認</h2>
  <?php if ($url !== ''): ?>
    <p class="event-url">🔗 イベント・グッズ情報: <a href="<?= h($url) ?>" target="_blank" rel="noopener noreferrer nofollow"><?= h($url) ?></a></p>
  <?php endif; ?>
  <?php if ($goods): ?>
    <div class="goods-grid">
      <?php foreach ($goods as $g): $img = product_image_url($g['image']); ?>
      <figure class="goods-item">
        <?php if ($img !== ''): ?><a href="<?= h($img) ?>" target="_blank" rel="noopener" class="goods-img"><img src="<?= h($img) ?>" alt="<?= h($g['name']) ?>" loading="lazy"></a><?php else: ?><div class="goods-img goods-noimg">🛍️</div><?php endif; ?>
        <figcaption>
          <strong><?= h($g['name']) ?></strong>
          <?php if ($g['description'] !== ''): ?><span><?= h($g['description']) ?></span><?php endif; ?>
          <?php if (session_is_open($session)): ?><button type="button" class="btn btn-ghost btn-sm" data-use-name="<?= h($g['name']) ?>">この名前で入力</button><?php endif; ?>
        </figcaption>
      </figure>
      <?php endforeach; ?>
    </div>
    <p class="muted small">※画像は確認用の参考です。在庫・価格・仕様は変わることがあります。欲しいグッズは下の依頼欄にご自身で入力してください。</p>
  <?php endif; ?>
</section>
<?php
}

function product_btn(string $self, string $action, $pid, string $label, string $class, string $confirm = '', array $extra = [], bool $disabled = false): string
{
    $html = '<form method="post" action="' . h($self) . '#products"' . ($confirm !== '' ? ' data-confirm="' . h($confirm) . '"' : '') . '>' . csrf_field()
        . '<input type="hidden" name="action" value="' . h($action) . '"><input type="hidden" name="product_id" value="' . (int)$pid . '">';
    foreach ($extra as $k => $val) {
        $html .= '<input type="hidden" name="' . h($k) . '" value="' . h($val) . '">';
    }
    return $html . '<button class="btn ' . h($class) . ' btn-sm"' . ($disabled ? ' disabled' : '') . '>' . h($label) . '</button></form>';
}
