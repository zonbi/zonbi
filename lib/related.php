<?php
declare(strict_types=1);

/*
 * 関連商品（広告）
 *  1. セッションのタイトル・説明・依頼内容から名詞らしいキーワードを抽出（「万博」「EXPO」「ミャクミャク」など）
 *  2. 楽天市場の商品検索APIで商品画像つきの関連商品を取得（結果はファイルに24時間キャッシュ）
 *  3. Amazon・楽天の検索リンクも併せて表示
 */

/** キーワード抽出で除外する一般的な語 */
const KEYWORD_STOPWORDS = [
    // 代行・取引まわり
    '代行', '依頼', '購入', '商品', '個数', '単価', '合計', '小計', '金額', '料金', '手数料', '送料', '発送', '配送', '郵送', '受取', '受け取り',
    '支払', '支払い', '振込', '入金', '現金', '前払', '後払', '締切', '受付', '注意', '事項', '説明', '連絡', '確認', '対応', '予定', '希望',
    '不要', '可能', '場合', '以上', '以下', '各種', '種類', '全種', '当日', '現地', '会場', '先着', '抽選', '販売', '在庫', '売切', '交換',
    '一人', '一点', '複数', '同時', '期間', '期限', '日時', '時間', '午前', '午後', '開場', '開演', '整理券', '整列', '物販', '通販', '公式',
    '限定', '新作', '新商品', '第一', '第二', '第三', 'グッズ', 'セット', 'ランダム', 'シークレット', 'タイプ', 'サイズ', 'カラー', 'デザイン',
    'メモ', 'キャンセル', 'サイト', 'ページ', 'リンク', 'フォーム', 'アカウント', 'ルール', 'マナー', 'イベント', 'チケット', 'オンライン', 'ショップ', 'ストア',
    // 色
    '黄色', '水色', '紫色', '茶色', '桃色', '金色', '銀色', 'ピンク', 'ブルー', 'レッド', 'ホワイト', 'ブラック', 'グリーン', 'イエロー',
    'パープル', 'オレンジ', 'ネイビー', 'ベージュ', 'グレー', 'ゴールド', 'シルバー',
    // 英字
    'http', 'https', 'www', 'com', 'net', 'org', 'html', 'php', 'the', 'and', 'for', 'with', 'url', 'ok', 'ng', 'jp',
];

/**
 * テキスト群から名詞らしいキーワードを抽出（形態素解析なしの簡易版）
 * - カタカナ2文字以上 / 漢字2文字以上 / 英字3文字以上（大文字の略語は2文字以上）の連続を候補にする
 * - 一般語・危険ワードを除外し、出現回数の多い順に返す
 */
function extract_keywords(array $texts, int $max = 3): array
{
    $ng = array_map('normalize_for_scan', ng_words());
    $stop = array_flip(array_map('mb_strtolower', KEYWORD_STOPWORDS));
    $score = [];
    $order = 0;
    foreach ($texts as $weight => $t) {
        $w = is_int($weight) ? 1 : (int)substr((string)$weight, strrpos((string)$weight, ':') + 1);
        $t = mb_convert_kana((string)$t, 'KVa');                       // 半角カナ→全角、全角英数→半角
        $t = preg_replace('#https?://\S+#u', ' ', $t) ?? $t;           // URLは除外
        // 「大阪・関西万博」のように中黒でつながった漢字語は1つのフレーズとして扱う
        preg_match_all('/[一-龠々〆ヶ]+(?:・[一-龠々〆ヶ]+)+|[ァ-ヴー]{2,}|[一-龠々〆ヶ]{2,}|[A-Za-z][A-Za-z0-9]{1,}/u', $t, $m);
        foreach ($m[0] as $word) {
            $word = strip_affix_stopwords(preg_replace('/^ー+|ー+$/u', '', $word) ?? $word);
            $word = str_replace('・', ' ', $word);
            $low = mb_strtolower($word);
            if (mb_strlen($word) < 2 || isset($stop[$low])) {
                continue;
            }
            if (preg_match('/^[A-Za-z0-9]+$/', $word) && strlen($word) < 3 && !preg_match('/^[A-Z]{2}$/', $word)) {
                continue;
            }
            if (preg_match('/^[ァ-ヴー]+$/u', $word) && mb_strlen($word) < 3) {
                continue; // 「アク」など短すぎるカタカナは除外
            }
            $norm = normalize_for_scan($word);
            foreach ($ng as $n) {
                if ($n !== '' && mb_strpos($norm, $n) !== false) {
                    continue 2; // 危険ワードは広告に使わない
                }
            }
            $key = $low;
            if (!isset($score[$key])) {
                $score[$key] = ['word' => $word, 'score' => 0, 'order' => $order++];
            }
            $score[$key]['score'] += $w;
        }
    }
    uasort($score, fn($a, $b) => [$b['score'], $a['order']] <=> [$a['score'], $b['order']]);
    // 重複する語（「万博」と「関西万博」、「EXPO」と「EXPO2025」など）は上位の1つだけ採用
    $picked = [];
    foreach ($score as $low => $s) {
        $plain = str_replace(' ', '', $low);
        foreach ($picked as $p) {
            $pp = str_replace(' ', '', mb_strtolower($p));
            if (mb_strpos($pp, $plain) !== false || mb_strpos($plain, $pp) !== false) {
                continue 2;
            }
        }
        $picked[] = $s['word'];
        if (count($picked) >= $max) {
            break;
        }
    }
    return $picked;
}

/** 「万博限定」→「万博」のように、複合語の前後についた一般語を取り除く */
function strip_affix_stopwords(string $word): string
{
    static $affixes = null;
    if ($affixes === null) {
        $affixes = array_merge(
            array_filter(KEYWORD_STOPWORDS, fn($w) => preg_match('/^[一-龠々〆ヶァ-ヴー]{2,}$/u', $w)),
            ['記念', '会場', '公式', '限定', '商品', '関連', '用品', '各種']
        );
        usort($affixes, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
    }
    for ($guard = 0; $guard < 4; $guard++) {
        $changed = false;
        foreach ($affixes as $a) {
            $len = mb_strlen($a);
            if (mb_strlen($word) - $len >= 2) {
                if (mb_substr($word, -$len) === $a) {
                    $word = mb_substr($word, 0, -$len);
                    $changed = true;
                } elseif (mb_substr($word, 0, $len) === $a) {
                    $word = mb_substr($word, $len);
                    $changed = true;
                }
            }
        }
        if (!$changed) {
            break;
        }
    }
    return $word;
}

/** 関連商品のキーワード */
function related_keywords(array $session, array $entries, int $max = 3): array
{
    // 重み: タイトル3、依頼内容2、説明1（説明は注意書きなど一般的な語が多いため低め）
    $texts = [
        'title:3' => $session['title'] ?? '',
        'desc:1'  => $session['description'] ?? '',
    ];
    $i = 0;
    foreach ($entries as $e) {
        if (($e['flag_words'] ?? '') !== '') {
            continue; // 危険ワードを含む依頼は使わない
        }
        $texts['e' . $i++ . ':2'] = implode(' ', [$e['item_name'] ?? '', $e['color'] ?? '', $e['variant'] ?? '', $e['note'] ?? '']);
    }
    return extract_keywords($texts, $max);
}

// ---------------------------------------------------------------- 楽天市場 商品検索API

function rakuten_api_conf(string $key): string
{
    $c = config('rakuten_api', []);
    return is_array($c) ? trim((string)($c[$key] ?? '')) : '';
}

function rakuten_api_enabled(): bool
{
    return rakuten_api_conf('application_id') !== '';
}

function product_cache_dir(): string
{
    return dirname(__DIR__) . '/cache/products';
}

/**
 * 楽天市場で商品を検索（24時間キャッシュ、失敗時は1時間キャッシュ）
 * 返り値: [['name','price','url','image','shop'], ...]
 */
function rakuten_search_items(string $keyword, int $hits = 4): array
{
    if (!rakuten_api_enabled() || $keyword === '') {
        return [];
    }
    $dir = product_cache_dir();
    $file = $dir . '/' . sha1($keyword . '|' . $hits) . '.json';
    if (is_file($file)) {
        $cached = json_decode((string)file_get_contents($file), true);
        if (is_array($cached) && ($cached['expires'] ?? 0) > time()) {
            return $cached['items'];
        }
    }

    $endpoint = rakuten_api_conf('endpoint') !== ''
        ? rakuten_api_conf('endpoint')
        : 'https://openapi.rakuten.co.jp/ichibams/api/IchibaItem/Search/20220601';
    $params = [
        'applicationId' => rakuten_api_conf('application_id'),
        'keyword'       => $keyword,
        'hits'          => $hits,
        'imageFlag'     => 1,
        'availability'  => 1,
        'sort'          => 'standard',
        'formatVersion' => 2,
        'format'        => 'json',
    ];
    if (rakuten_api_conf('access_key') !== '') {
        $params['accessKey'] = rakuten_api_conf('access_key');
    }
    if (rakuten_enabled()) {
        $params['affiliateId'] = affiliate_conf('rakuten_id');
    }
    $url = $endpoint . '?' . http_build_query($params);
    $headers = ['Referer: ' . base_url() . '/', 'Origin: ' . preg_replace('#^(https?://[^/]+).*$#', '$1', base_url())];

    $body = null;
    $status = 0;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_HTTPHEADER => $headers]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => ['timeout' => 4, 'header' => implode("\r\n", $headers), 'ignore_errors' => true]]);
        $body = @file_get_contents($url, false, $ctx);
        $status = preg_match('#\s(\d{3})\s#', $http_response_header[0] ?? '', $mm) ? (int)$mm[1] : 0;
    }

    $items = [];
    $json = is_string($body) ? json_decode($body, true) : null;
    if ($status === 200 && is_array($json)) {
        foreach ($json['Items'] ?? $json['items'] ?? [] as $row) {
            $it = $row['Item'] ?? $row['item'] ?? $row; // formatVersion 1 / 2 の両方に対応
            $img = $it['mediumImageUrls'][0] ?? '';
            if (is_array($img)) {
                $img = $img['imageUrl'] ?? '';
            }
            $link = (string)($it['affiliateUrl'] ?? '') !== '' ? $it['affiliateUrl'] : ($it['itemUrl'] ?? '');
            if (!preg_match('#^https://#', (string)$link)) {
                continue;
            }
            $items[] = [
                'name'  => mb_substr((string)($it['itemName'] ?? ''), 0, 120),
                'price' => (int)($it['itemPrice'] ?? 0),
                'url'   => (string)$link,
                'image' => preg_match('#^https://#', (string)$img) ? preg_replace('/\?_ex=\d+x\d+$/', '?_ex=200x200', (string)$img) : '',
                'shop'  => mb_substr((string)($it['shopName'] ?? ''), 0, 60),
            ];
        }
    }
    $ttl = $status === 200 ? 86400 : 3600;
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    @file_put_contents($file, json_encode(['expires' => time() + $ttl, 'status' => $status, 'items' => $items], JSON_UNESCAPED_UNICODE), LOCK_EX);
    return $items;
}

// ---------------------------------------------------------------- 表示

/** 依頼者ページ下部の「関連商品」欄 */
function render_related_area(array $session, array $entries): void
{
    if (!affiliate_enabled() && !rakuten_api_enabled()) {
        return;
    }
    $keywords = related_keywords($session, $entries, 3);
    if (!$keywords) {
        return;
    }
    $groups = [];
    $seenUrl = [];
    foreach ($keywords as $kw) {
        $items = [];
        foreach (rakuten_search_items($kw, 4) as $it) {
            if (!isset($seenUrl[$it['url']])) {
                $seenUrl[$it['url']] = true;
                $items[] = $it;
            }
        }
        $groups[] = ['keyword' => $kw, 'items' => $items];
    }
    $hasItems = (bool)array_filter($groups, fn($g) => $g['items']);
    $attr = ' target="_blank" rel="sponsored nofollow noopener"';
    ?>
<section class="card related-box" aria-label="広告">
  <h2><span class="ad-label">PR</span> 関連商品</h2>
  <?php foreach ($groups as $g): ?>
    <div class="related-group">
      <div class="related-head">
        <span class="related-kw">「<?= h($g['keyword']) ?>」</span>
        <?= affiliate_links(['item_name' => $g['keyword']]) ?>
      </div>
      <?php if ($g['items']): ?>
        <div class="product-grid">
          <?php foreach ($g['items'] as $it): ?>
            <a class="product" href="<?= h($it['url']) ?>"<?= $attr ?>>
              <?php if ($it['image'] !== ''): ?><img src="<?= h($it['image']) ?>" alt="" loading="lazy" referrerpolicy="no-referrer"><?php else: ?><span class="noimg">No Image</span><?php endif; ?>
              <span class="product-name"><?= h($it['name']) ?></span>
              <span class="product-price"><?= yen($it['price']) ?></span>
              <?php if ($it['shop'] !== ''): ?><span class="product-shop"><?= h($it['shop']) ?></span><?php endif; ?>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
  <p class="muted small">ページの内容から自動で選んだキーワードの検索結果です。イベント限定品は取り扱いがない場合や、定価より高い転売品が表示される場合があります。価格は取得時点のものです。</p>
  <?php if ($hasItems): ?>
    <p class="rakuten-credit"><!-- Rakuten Web Service Center -->Supported by <a href="https://developers.rakuten.com/" target="_blank" rel="noopener">Rakuten Developers</a></p>
  <?php endif; ?>
</section>
<?php
}
