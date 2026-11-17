<?php
declare(strict_types=1);

/*
 * 代行する商品の登録（商品カタログ）
 *  - 代行者が管理ページで商品を画像付きで登録し、依頼者は一覧から選んで個数を入力する
 *  - 画像はサーバーで読み込み直して縮小・再保存する（位置情報などの埋め込み情報を除去、画像以外のファイルを排除）
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

/** 依頼者が登録商品以外も依頼できるか（商品が未登録なら常に可） */
function allows_free_items(array $session, array $products): bool
{
    return !$products || !array_key_exists('allow_free_items', $session) || (int)$session['allow_free_items'] === 1;
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
        $errors[] = '商品名を入力してください。';
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
    if (!in_array($action, ['product_save', 'product_toggle', 'product_delete', 'product_move', 'product_settings'], true)) {
        return false;
    }
    if (!products_ready()) {
        flash('error', '商品の登録にはデータベースの更新が必要です。phpMyAdmin で sql/migrations/008_add_products.sql を実行してください。');
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
                flash('success', '「' . $p['name'] . '」を更新しました。');
            } else {
                $st = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM shop_products WHERE session_id = ?');
                $st->execute([$sid]);
                $pdo->prepare('INSERT INTO shop_products (session_id, name, description, price, color_options, size_options, shape_options, max_per_person, image, sort_order) VALUES (?,?,?,?,?,?,?,?,?,?)')
                    ->execute(array_merge([$sid], $vals, [(int)$st->fetchColumn()]));
                flash('success', '「' . $p['name'] . '」を登録しました。');
            }
            return true;

        case 'product_toggle':
            if ($product) {
                $pdo->prepare('UPDATE shop_products SET is_active = 1 - is_active WHERE id = ?')->execute([$product['id']]);
                flash('success', '「' . $product['name'] . '」を' . ((int)$product['is_active'] === 1 ? '受付停止にしました（依頼者の一覧に表示されません）。' : '受付中にしました。'));
            }
            return true;

        case 'product_delete':
            if ($product) {
                delete_product_image($product['image']);
                $pdo->prepare('UPDATE shop_entries SET product_id = NULL WHERE product_id = ?')->execute([$product['id']]);
                $pdo->prepare('DELETE FROM shop_products WHERE id = ?')->execute([$product['id']]);
                flash('success', '「' . $product['name'] . '」を削除しました（すでに届いている依頼はそのまま残ります）。');
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

        case 'product_settings':
            $pdo->prepare('UPDATE shop_sessions SET allow_free_items = ? WHERE id = ?')->execute([empty($_POST['allow_free_items']) ? 0 : 1, $sid]);
            flash('success', empty($_POST['allow_free_items']) ? '登録した商品だけを受け付けるようにしました。' : '登録した商品以外の依頼も受け付けるようにしました。');
            return true;
    }
    return false;
}

// ---------------------------------------------------------------- 管理ページ：表示

function render_product_manager(array $session, string $self, array $form = []): void
{
    $sid = (int)$session['id'];
    if (!products_ready()) {
        ?>
<details class="card" id="products">
  <summary><strong>代行する商品を登録（画像付き）</strong></summary>
  <p class="small">商品の登録には、phpMyAdmin で <code>sql/migrations/008_add_products.sql</code> の実行が必要です。</p>
</details>
<?php
        return;
    }
    $products = fetch_products($sid);
    $editId = (int)($_GET['product'] ?? ($form['product_id'] ?? 0));
    $editing = $editId ? find_product($sid, $editId) : null;
    $v = $form ?: ($editing ? [
        'p_name' => $editing['name'], 'p_description' => $editing['description'], 'p_price' => $editing['price'] ?? '',
        'p_color_options' => $editing['color_options'], 'p_size_options' => $editing['size_options'],
        'p_shape_options' => $editing['shape_options'], 'p_max' => $editing['max_per_person'] ?? '',
    ] : []);
    $open = $products || $editing || $form || isset($_GET['product']);
    ?>
<details class="card product-manager" id="products"<?= $open ? ' open' : '' ?>>
  <summary><strong>代行する商品を登録（画像付き）</strong><?php if ($products): ?> <span class="badge badge-open"><?= count($products) ?>件</span><?php endif; ?></summary>
  <p class="small muted">登録した商品は依頼者のページに画像付きで並び、依頼者は選んで個数を入れるだけで依頼できます。</p>

  <details class="legal-note">
    <summary>⚠ 登録の前に確認してください（法律・規約まわり）</summary>
    <ul>
      <li><strong>価格は販売元の定価（税込）</strong>で登録してください。手数料などを受け取る場合は、商品の価格に上乗せせず、<strong>別に明記</strong>してください。定価より高い価格での販売は転売とみなされ、イベントの規約違反や法令違反になるおそれがあります。</li>
      <li><strong>チケット・入場券の類は登録できません</strong>（チケット不正転売禁止法、イベントの規約）。</li>
      <li><strong>画像は、自分で撮影した写真など、掲載する権利のあるもの</strong>を使ってください。公式サイトや他人のSNSの画像を無断で使うと著作権侵害になるおそれがあります。キャラクターが写る写真の扱いは権利者の方針に従ってください。</li>
      <li>イベントや販売元が<strong>代理購入・購入数を制限</strong>している場合は、それに従ってください（「1人あたりの上限」も設定できます）。</li>
      <li>代行を<strong>事業として継続的に</strong>行う場合（手数料で利益を得る等）は、特定商取引法の表示や古物商許可などが必要になることがあります。詳しくは<a href="<?= h(url('terms.php')) ?>#products" target="_blank">利用規約</a>をご覧ください。</li>
    </ul>
  </details>

  <?php if ($products): ?>
  <ul class="product-admin-list">
    <?php foreach ($products as $i => $p): $img = product_image_url($p['image']); ?>
    <li class="<?= (int)$p['is_active'] === 1 ? '' : 'is-off' ?>">
      <div class="pa-thumb"><?php if ($img !== ''): ?><img src="<?= h($img) ?>" alt=""><?php else: ?><span>No Image</span><?php endif; ?></div>
      <div class="pa-body">
        <strong><?= h($p['name']) ?></strong><?php if ((int)$p['is_active'] !== 1): ?> <span class="badge badge-closed">受付停止</span><?php endif; ?>
        <div class="small muted">
          <?= $p['price'] !== null ? h(yen($p['price'])) : '価格未定' ?>
          <?php foreach (['color_options' => '色', 'size_options' => 'サイズ', 'shape_options' => '形'] as $k => $lab): if ($p[$k] !== ''): ?>／<?= h($lab) ?>: <?= h($p[$k]) ?><?php endif; endforeach; ?>
          <?php if ($p['max_per_person'] !== null): ?>／1人<?= (int)$p['max_per_person'] ?>個まで<?php endif; ?>
        </div>
      </div>
      <div class="pa-actions">
        <?= product_btn($self, 'product_move', $p['id'], '↑', 'btn-ghost', '', ['dir' => 'up'], $i === 0) ?>
        <?= product_btn($self, 'product_move', $p['id'], '↓', 'btn-ghost', '', ['dir' => 'down'], $i === count($products) - 1) ?>
        <a class="btn btn-ghost btn-sm" href="<?= h($self . (str_contains($self, '?') ? '&' : '?') . 'product=' . (int)$p['id']) ?>#product-form">編集</a>
        <?= product_btn($self, 'product_toggle', $p['id'], (int)$p['is_active'] === 1 ? '停止' : '再開', 'btn-ghost') ?>
        <?= product_btn($self, 'product_delete', $p['id'], '削除', 'btn-danger', '「' . $p['name'] . '」を削除しますか？（届いている依頼は残ります）') ?>
      </div>
    </li>
    <?php endforeach; ?>
  </ul>
  <form method="post" action="<?= h($self) ?>#products" class="free-items-setting">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="product_settings">
    <label class="checkbox"><input type="checkbox" name="allow_free_items" value="1"<?= allows_free_items($session, $products) ? ' checked' : '' ?> onchange="this.form.submit()"> 登録した商品以外の依頼（自由入力）も受け付ける</label>
  </form>
  <?php endif; ?>

  <form method="post" action="<?= h($self) ?>#products" enctype="multipart/form-data" class="product-form" id="product-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="product_save">
    <?php if ($editing): ?><input type="hidden" name="product_id" value="<?= (int)$editing['id'] ?>"><?php endif; ?>
    <h3><?= $editing ? '「' . h($editing['name']) . '」を編集' : '商品を追加' ?></h3>
    <div class="grid">
      <label class="span-2">商品名 <span class="req">必須</span>
        <input type="text" name="p_name" value="<?= h($v['p_name'] ?? '') ?>" maxlength="200" required placeholder="例: 万博すごろく">
      </label>
      <label>定価（税込・円）
        <input type="number" name="p_price" value="<?= h($v['p_price'] ?? '') ?>" min="0" max="10000000" inputmode="numeric" placeholder="未定なら空欄">
      </label>
      <label>1人あたりの上限
        <input type="number" name="p_max" value="<?= h($v['p_max'] ?? '') ?>" min="1" max="999" inputmode="numeric" placeholder="なしなら空欄">
      </label>
      <label>色の選択肢
        <input type="text" name="p_color_options" value="<?= h($v['p_color_options'] ?? '') ?>" maxlength="500" placeholder="例: 青, 赤, 黄">
      </label>
      <label>サイズの選択肢
        <input type="text" name="p_size_options" value="<?= h($v['p_size_options'] ?? '') ?>" maxlength="500" placeholder="例: S, M, L">
      </label>
      <label>形の選択肢
        <input type="text" name="p_shape_options" value="<?= h($v['p_shape_options'] ?? '') ?>" maxlength="500" placeholder="例: Aタイプ, Bタイプ">
      </label>
      <label class="span-2">説明
        <input type="text" name="p_description" value="<?= h($v['p_description'] ?? '') ?>" maxlength="500" placeholder="例: 会場限定。ランダム封入（全8種）">
      </label>
    </div>
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
      <button class="btn btn-primary"><?= $editing ? '更新する' : '登録する' ?></button>
    </div>
  </form>
</details>
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

// ---------------------------------------------------------------- 依頼者ページ

/**
 * 依頼フォームで選ばれた登録商品を、保存用の依頼データに変換（検証込み）
 * POST: products[商品ID][qty|color|size|variant]
 */
function collect_product_picks(array $products, array $post, int $sid, string $ownerHash, array &$errors): array
{
    $in = is_array($post['products'] ?? null) ? $post['products'] : [];
    $out = [];
    foreach ($products as $p) {
        $row = is_array($in[$p['id']] ?? null) ? $in[$p['id']] : [];
        $qty = to_int_or_null($row['qty'] ?? '');
        if (!$qty) {
            continue;
        }
        $name = $p['name'];
        if ($qty > 9999) {
            $errors[] = "「{$name}」の個数は 9999 までです。";
            continue;
        }
        $chosen = [];
        foreach (['color' => ['color_options', '色'], 'size' => ['size_options', 'サイズ'], 'variant' => ['shape_options', '形']] as $k => [$col, $lab]) {
            $opts = parse_options((string)$p[$col]);
            $val = input_str($row, $k, 100);
            if ($opts && !in_array($val, $opts, true)) {
                $errors[] = "「{$name}」の{$lab}を選んでください。";
            }
            $chosen[$k] = $opts ? $val : '';
        }
        if ($p['max_per_person'] !== null) {
            // すでに依頼済みの分（このブラウザ／編集URLの依頼者）と合わせて上限を確認
            $st = db()->prepare('SELECT COALESCE(SUM(quantity), 0) FROM shop_entries WHERE session_id = ? AND product_id = ? AND owner_key = ?');
            $st->execute([$sid, $p['id'], $ownerHash]);
            $already = (int)$st->fetchColumn();
            $sameInForm = array_sum(array_map(fn($o) => $o['product_id'] === (int)$p['id'] ? $o['quantity'] : 0, $out));
            if ($already + $sameInForm + $qty > (int)$p['max_per_person']) {
                $errors[] = "「{$name}」は1人{$p['max_per_person']}個までです" . ($already ? "（依頼済み {$already}個）" : '') . '。';
                continue;
            }
        }
        $out[] = [
            'product_id' => (int)$p['id'],
            'item_name'  => $name,
            'color'      => $chosen['color'],
            'size'       => $chosen['size'],
            'variant'    => $chosen['variant'],
            'quantity'   => $qty,
            'unit_price' => $p['price'] !== null ? (int)$p['price'] : db_price(null),
            'note'       => input_str($row, 'note', 500),
        ];
    }
    return $out;
}

/** 依頼フォーム内の商品一覧（カタログ） */
function render_product_catalog(array $products, array $post, bool $showPrice): void
{
    if (!$products) {
        return;
    }
    $in = is_array($post['products'] ?? null) ? $post['products'] : [];
    ?>
<div class="catalog" data-catalog>
  <?php foreach ($products as $p):
      $row = is_array($in[$p['id']] ?? null) ? $in[$p['id']] : [];
      $img = product_image_url($p['image']);
      $n = fn(string $k) => 'products[' . (int)$p['id'] . '][' . $k . ']'; ?>
  <div class="catalog-item" data-product>
    <div class="ci-img"><?php if ($img !== ''): ?><img src="<?= h($img) ?>" alt="<?= h($p['name']) ?>" loading="lazy"><?php else: ?><span>🛍️</span><?php endif; ?></div>
    <div class="ci-body">
      <div class="ci-name"><?= h($p['name']) ?></div>
      <?php if ($showPrice): ?><div class="ci-price"><?= $p['price'] !== null ? h(yen($p['price'])) : '<span class="tbd">価格未定</span>' ?></div><?php endif; ?>
      <?php if ($p['description'] !== ''): ?><div class="ci-desc"><?= h($p['description']) ?></div><?php endif; ?>
      <?php foreach (['color' => ['color_options', '色'], 'size' => ['size_options', 'サイズ'], 'variant' => ['shape_options', '形']] as $k => [$col, $lab]):
          $opts = parse_options((string)$p[$col]); if (!$opts) { continue; } ?>
        <label class="ci-opt"><span><?= h($lab) ?></span>
          <select name="<?= h($n($k)) ?>">
            <option value="">選択</option>
            <?php foreach ($opts as $o): ?><option value="<?= h($o) ?>"<?= ($row[$k] ?? '') === $o ? ' selected' : '' ?>><?= h($o) ?></option><?php endforeach; ?>
          </select>
        </label>
      <?php endforeach; ?>
      <div class="ci-qty">
        <button type="button" class="qty-btn" data-qty-step="-1" aria-label="1つ減らす">−</button>
        <input type="number" name="<?= h($n('qty')) ?>" value="<?= h((string)($row['qty'] ?? '0')) ?>" min="0" max="<?= $p['max_per_person'] !== null ? (int)$p['max_per_person'] : 9999 ?>" inputmode="numeric" aria-label="<?= h($p['name']) ?>の個数" data-product-qty data-unit-price="<?= $p['price'] !== null ? (int)$p['price'] : '' ?>">
        <button type="button" class="qty-btn" data-qty-step="1" aria-label="1つ増やす">＋</button>
      </div>
      <?php if ($p['max_per_person'] !== null): ?><div class="ci-limit">1人<?= (int)$p['max_per_person'] ?>個まで</div><?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php
}
