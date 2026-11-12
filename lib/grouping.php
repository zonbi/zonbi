<?php
declare(strict_types=1);

/*
 * 似た商品名のグループ化（表記揺れの吸収）
 *  - 正規化: 全角/半角・カタカナ/ひらがな・大文字/小文字・空白/記号の違いを無視
 *  - 類似度: 文字バイグラムの Dice 係数、または一方が他方を十分に含む場合に同じ商品とみなす
 *  - 代行者が手動で付けた「まとめ名」（shop_entries.group_label）があれば、それを優先
 */

const GROUP_THRESHOLD = 0.7;

function group_norm(string $s): string
{
    $s = mb_convert_kana($s, 'KVas');
    $s = mb_convert_kana($s, 'c');
    $s = mb_strtolower($s);
    $s = preg_replace('/[\s\x{3000}・･\-‐－ー_\/／\\\\()（）\[\]［］【】「」『』〈〉《》<>"\'“”‘’.,，．。、!！?？~～〜:：;；#＃*＊+＋&＆♪☆★◆◇●○]+/u', '', $s) ?? $s;
    return $s;
}

function bigrams(string $s): array
{
    $len = mb_strlen($s);
    if ($len < 2) {
        return [$s => 1];
    }
    $g = [];
    for ($i = 0; $i < $len - 1; $i++) {
        $b = mb_substr($s, $i, 2);
        $g[$b] = ($g[$b] ?? 0) + 1;
    }
    return $g;
}

/** 編集距離（マルチバイト対応、短い文字列用） */
function mb_levenshtein(string $a, string $b): int
{
    $x = mb_str_split($a);
    $y = mb_str_split($b);
    $prev = range(0, count($y));
    foreach ($x as $i => $cx) {
        $cur = [$i + 1];
        foreach ($y as $j => $cy) {
            $cur[$j + 1] = min($prev[$j + 1] + 1, $cur[$j] + 1, $prev[$j] + ($cx === $cy ? 0 : 1));
        }
        $prev = $cur;
    }
    return $prev[count($y)];
}

/** 商品名を単語に分ける（空白・記号で区切り、正規化。1文字の語と「公式」「限定」などの一般語は除く） */
function name_tokens(string $s): array
{
    static $stop = null;
    if ($stop === null) {
        $stop = array_flip(array_map('group_norm', array_merge(KEYWORD_STOPWORDS, ['公式', '限定', '新作', '再販', '各種'])));
    }
    $s = mb_convert_kana($s, 'KVas');
    $parts = preg_split('/[\s\x{3000}・･\/／()（）\[\]［］【】「」『』〈〉《》<>"\'“”‘’.,，．。、!！?？~～〜:：;；#＃*＊+＋&＆♪☆★◆◇●○]+/u', $s) ?: [];
    $out = [];
    foreach ($parts as $p) {
        $n = group_norm($p);
        if (mb_strlen($n) >= 2 && !isset($stop[$n])) {
            $out[] = $n;
        }
    }
    return array_values(array_unique($out));
}

/**
 * 商品名どうしの類似度（0〜1）。$a/$b は ['norm' => 正規化名, 'tokens' => 単語]
 * 両方が2語以上なら「すべての語に似た相手の語がある」ことを条件にする（語順の入れ替えは同じとみなし、
 * 「こみゃく アクスタ」と「ミャクミャク アクスタ」のように一部の語だけ違うものは別とみなす）
 */
function item_similarity(array $a, array $b): float
{
    if ($a['norm'] === $b['norm']) {
        return 1.0;
    }
    if (count($a['tokens']) >= 2 && count($b['tokens']) >= 2) {
        $min = 1.0;
        foreach ([[$a['tokens'], $b['tokens']], [$b['tokens'], $a['tokens']]] as [$xs, $ys]) {
            foreach ($xs as $x) {
                $best = 0.0;
                foreach ($ys as $y) {
                    $best = max($best, name_similarity($x, $y));
                }
                $min = min($min, $best);
            }
        }
        return $min;
    }
    return name_similarity($a['norm'], $b['norm']);
}

/** 2つの正規化済み文字列の類似度（0〜1） */
function name_similarity(string $a, string $b): float
{
    if ($a === $b) {
        return 1.0;
    }
    if ($a === '' || $b === '') {
        return 0.0;
    }
    // 「バッジ／バッチ」「バッグ／バック」のような1文字違い（4文字以上）
    if (min(mb_strlen($a), mb_strlen($b)) >= 4 && mb_levenshtein($a, $b) === 1) {
        return 0.8;
    }
    [$short, $long] = mb_strlen($a) <= mb_strlen($b) ? [$a, $b] : [$b, $a];
    // 「すごろく」と「万博すごろく」のように、短い方が長い方に含まれ、長さが近い場合
    if (mb_strlen($short) >= 3 && mb_strpos($long, $short) !== false && mb_strlen($short) / mb_strlen($long) >= 0.5) {
        return 0.9;
    }
    $ga = bigrams($a);
    $gb = bigrams($b);
    $common = 0;
    foreach ($ga as $k => $n) {
        if (isset($gb[$k])) {
            $common += min($n, $gb[$k]);
        }
    }
    return 2 * $common / (array_sum($ga) + array_sum($gb));
}

/**
 * 依頼をグループにまとめる。
 * 返り値: [['label','manual'(bool),'entries'=>[...],'quantity','purchased','total','unpriced','people'=>[name=>qty],
 *           'spellings'=>[表記=>qty],'variants'=>[[color,variant,unit_price,quantity],...]], ...]
 */
function group_similar_items(array $entries): array
{
    // 1) 手動のまとめ名があるものは、まとめ名（正規化）で確定
    // 2) それ以外は正規化名ごとに集め、類似度で連結（union-find）
    $keys = [];          // 正規化キー => 代表情報
    $entryKey = [];      // entry index => キー
    foreach ($entries as $i => $e) {
        $label = trim((string)($e['group_label'] ?? ''));
        if ($label !== '') {
            $k = 'm:' . group_norm($label);
            $keys[$k] ??= ['norm' => group_norm($label), 'tokens' => name_tokens($label), 'manual' => true, 'label' => $label];
        } else {
            $n = group_norm((string)$e['item_name']);
            $k = 'a:' . $n;
            $keys[$k] ??= ['norm' => $n, 'tokens' => name_tokens((string)$e['item_name']), 'manual' => false, 'label' => null];
        }
        $entryKey[$i] = $k;
    }

    // 完全連結法: グループ内のすべての名前どうしが似ている場合だけ同じグループにする
    // （「アクスタ」を介して「こみゃく アクスタ」と「ミャクミャク アクスタ」がつながる、といった連鎖を防ぐ）
    $freq = [];
    foreach ($entryKey as $i => $k) {
        $freq[$k] = ($freq[$k] ?? 0) + (int)$entries[$i]['quantity'];
    }
    $order = array_keys($keys);
    usort($order, fn($a, $b) => [(int)!$keys[$a]['manual'], -$freq[$a]] <=> [(int)!$keys[$b]['manual'], -$freq[$b]]);
    $clusters = []; // root => [member keys]
    $rootOf = [];
    foreach ($order as $k) {
        $best = null;
        $bestScore = 0.0;
        foreach ($clusters as $root => $members) {
            if ($keys[$k]['manual']) {
                break; // 手動のまとめ名は常に独立したグループ
            }
            // 手動グループ（まとめ名あり）は、まとめ名と、そこに合流した自動の名前の両方と似ていれば合流できる
            // （手動同士の比較は members のうち自動のものだけで行う）
            $min = 1.0;
            $sum = 0.0;
            foreach ($members as $m) {
                if ($keys[$m]['manual'] && $m !== $root) {
                    continue;
                }
                $sim = item_similarity($keys[$k], $keys[$m]);
                $min = min($min, $sim);
                $sum += $sim;
            }
            if ($min >= GROUP_THRESHOLD && $sum / count($members) > $bestScore) {
                $best = $root;
                $bestScore = $sum / count($members);
            }
        }
        if ($best === null) {
            $clusters[$k] = [$k];
            $rootOf[$k] = $k;
        } else {
            $clusters[$best][] = $k;
            $rootOf[$k] = $best;
        }
    }
    $find = fn(string $k): string => $rootOf[$k];

    $groups = [];
    foreach ($entries as $i => $e) {
        $root = $find($entryKey[$i]);
        $g = &$groups[$root];
        $g ??= ['root' => $root, 'label' => $keys[$root]['label'], 'manual' => $keys[$root]['manual'], 'entries' => [],
                'quantity' => 0, 'purchased' => 0, 'total' => 0, 'unpriced' => 0, 'people' => [], 'spellings' => [], 'variants' => []];
        $q = (int)$e['quantity'];
        $g['entries'][] = $e;
        $g['quantity'] += $q;
        $g['purchased'] += (int)$e['is_purchased'] === 1 ? $q : 0;
        $g['total'] += (int)line_total($e);
        $g['unpriced'] += has_price($e) ? 0 : 1;
        $g['people'][$e['requester_name']] = ($g['people'][$e['requester_name']] ?? 0) + $q;
        $g['spellings'][$e['item_name']] = ($g['spellings'][$e['item_name']] ?? 0) + $q;
        $vk = mb_strtolower(group_norm((string)$e['color']) . "\0" . group_norm((string)($e['size'] ?? '')) . "\0" . group_norm((string)$e['variant']));
        $g['variants'][$vk] ??= ['color' => $e['color'], 'size' => $e['size'] ?? '', 'variant' => $e['variant'], 'quantity' => 0];
        $g['variants'][$vk]['quantity'] += $q;
        unset($g);
    }
    foreach ($groups as &$g) {
        if ($g['label'] === null) {
            // 代表名: 正規化して最も多い表記（同数なら長い＝具体的な方）
            $byNorm = [];
            foreach ($g['spellings'] as $sp => $q) {
                $byNorm[group_norm($sp)][$sp] = $q;
            }
            uksort($byNorm, fn($a, $b) => [array_sum($byNorm[$b]), mb_strlen($b)] <=> [array_sum($byNorm[$a]), mb_strlen($a)]);
            $top = reset($byNorm);
            arsort($top);
            $g['label'] = (string)array_key_first($top);
        }
        arsort($g['spellings']);
        $g['variants'] = array_values($g['variants']);
        usort($g['entries'], fn($a, $b) => [group_norm($a['item_name']), $a['requester_name']] <=> [group_norm($b['item_name']), $b['requester_name']]);
    }
    unset($g);
    return array_values($groups);
}

function group_label_ready(): bool
{
    try {
        db()->query('SELECT group_label FROM shop_entries LIMIT 0');
        return true;
    } catch (PDOException $e) {
        return false;
    }
}
