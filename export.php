<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

// CSV出力（Excelで文字化けしないよう UTF-8 BOM 付き）
$session = find_session((string)($_GET['id'] ?? ''));
if (!$session || (!is_admin_key_valid($session, (string)($_GET['key'] ?? '')) && !is_session_owner($session))) {
    not_found('管理用URLが正しくありません。');
}
$type = in_array($_GET['type'] ?? '', ['list', 'person', 'item'], true) ? $_GET['type'] : 'list';
$entries = fetch_entries((int)$session['id']);

$rows = [];
switch ($type) {
    case 'person':
        $rows[] = ['依頼者', '点数', '合計金額', '内訳'];
        foreach (group_by_person($entries) as $p) {
            $detail = array_map(
                fn($e) => $e['item_name'] . ($e['color'] !== '' ? "（{$e['color']}）" : '') . ($e['variant'] !== '' ? "［{$e['variant']}］" : '') . '×' . $e['quantity'],
                $p['entries']
            );
            $rows[] = [$p['name'], $p['quantity'], $p['total'], implode(' / ', $detail)];
        }
        break;
    case 'item':
        $rows[] = ['商品名', '色', '型', '単価', '必要数', '購入済', '金額', '内訳'];
        foreach (group_by_item($entries) as $it) {
            $people = [];
            foreach ($it['people'] as $n => $q) {
                $people[] = "{$n}×{$q}";
            }
            $rows[] = [$it['item_name'], $it['color'], $it['variant'], $it['unit_price'], $it['quantity'], $it['purchased'], $it['total'], implode(' / ', $people)];
        }
        break;
    default:
        $rows[] = ['No', '依頼者', '商品名', '色', '型', '個数', '単価', '小計', 'メモ', '購入済', '登録日時'];
        foreach ($entries as $i => $e) {
            $rows[] = [
                $i + 1, $e['requester_name'], $e['item_name'], $e['color'], $e['variant'],
                $e['quantity'], $e['unit_price'], (int)$e['quantity'] * (int)$e['unit_price'],
                $e['note'], (int)$e['is_purchased'] === 1 ? '済' : '', $e['created_at'],
            ];
        }
}

$labels = ['list' => '全件', 'person' => '依頼者別', 'item' => '商品別'];
$filename = preg_replace('/[\\\\\/:*?"<>|\r\n]/u', '_', $session['title']) . '_' . $labels[$type] . '_' . date('Ymd_Hi') . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header("Content-Disposition: attachment; filename=\"export.csv\"; filename*=UTF-8''" . rawurlencode($filename));
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
foreach ($rows as $r) {
    // 数式インジェクション対策（=,+,-,@ で始まる文字列の先頭に ' を付ける）
    $r = array_map(fn($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v, $r);
    fputcsv($out, $r, ',', '"', '');
}
fclose($out);
