<?php
declare(strict_types=1);

/*
 * 依頼フォームの入力項目設定（セッションごとに代行者が選択）
 *  必須（固定）: 依頼者（お名前）・商品名・個数
 *  任意: 価格・小計表示・コメント・色・サイズ・形・SNSアカウント名
 *  入力欄の項目は「表示しない / 任意 / 必須」、小計表示は「表示する / しない」
 */

const FIELD_OFF = 'off';
const FIELD_OPTIONAL = 'optional';
const FIELD_REQUIRED = 'required';

/** 項目の定義（キー => [ラベル, 既定値, 説明]） */
const FORM_FIELD_DEFS = [
    'price'    => ['label' => '価格（単価）',        'default' => FIELD_OPTIONAL, 'help' => '1個あたりの価格（円）'],
    'subtotal' => ['label' => '小計・合計の表示',    'default' => true,           'help' => '依頼者の画面に小計・合計金額を表示（価格がオンのときのみ）'],
    'note'     => ['label' => 'コメント',            'default' => FIELD_OPTIONAL, 'help' => '「売り切れなら不要」などの自由記入'],
    'color'    => ['label' => '色',                  'default' => FIELD_OPTIONAL, 'help' => '例: 青 / ○○担当カラー'],
    'size'     => ['label' => 'サイズ',              'default' => FIELD_OFF,      'help' => '例: M / L / フリー'],
    'shape'    => ['label' => '形（型・種類）',      'default' => FIELD_OPTIONAL, 'help' => '例: Aタイプ / 丸型'],
    'sns'      => ['label' => 'SNSアカウント名（@）', 'default' => FIELD_OFF,     'help' => 'X・Instagram・Threads などのアカウント名（依頼者ごとに1つ）'],
];

/** 新しい列（size・requester_sns・form_fields）がDBにあるか（007 のSQL適用済みか） */
function form_fields_ready(): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            db()->query('SELECT form_fields FROM shop_sessions LIMIT 0');
            db()->query('SELECT size, requester_sns FROM shop_entries LIMIT 0');
            $ready = true;
        } catch (PDOException $e) {
            $ready = false;
        }
    }
    return $ready;
}

function default_form_fields(): array
{
    $out = [];
    foreach (FORM_FIELD_DEFS as $k => $d) {
        $out[$k] = $d['default'];
    }
    return $out;
}

/** セッションの入力項目設定（未設定・未適用なら既定値） */
function form_fields(array $session): array
{
    $ff = default_form_fields();
    $saved = isset($session['form_fields']) && is_string($session['form_fields']) ? json_decode($session['form_fields'], true) : null;
    if (is_array($saved)) {
        foreach ($ff as $k => $def) {
            if (!array_key_exists($k, $saved)) {
                continue;
            }
            if ($k === 'subtotal') {
                $ff[$k] = (bool)$saved[$k];
            } elseif (in_array($saved[$k], [FIELD_OFF, FIELD_OPTIONAL, FIELD_REQUIRED], true)) {
                $ff[$k] = $saved[$k];
            }
        }
    }
    if (!form_fields_ready()) {
        $ff['size'] = FIELD_OFF;
        $ff['sns'] = FIELD_OFF;
    }
    if ($ff['price'] === FIELD_OFF) {
        $ff['subtotal'] = false;
    }
    return $ff;
}

/** フォーム送信値から設定を作る */
function form_fields_from_post(array $src): array
{
    $ff = default_form_fields();
    foreach ($ff as $k => $def) {
        if ($k === 'subtotal') {
            $ff[$k] = !empty($src['ff_subtotal']);
        } else {
            $v = (string)($src['ff_' . $k] ?? $def);
            $ff[$k] = in_array($v, [FIELD_OFF, FIELD_OPTIONAL, FIELD_REQUIRED], true) ? $v : $def;
        }
    }
    return $ff;
}

function save_form_fields(int $sessionId, array $ff): bool
{
    if (!form_fields_ready()) {
        return false;
    }
    db()->prepare('UPDATE shop_sessions SET form_fields = ? WHERE id = ?')->execute([json_encode($ff), $sessionId]);
    return true;
}

function field_on(array $ff, string $k): bool
{
    return ($ff[$k] ?? FIELD_OFF) !== FIELD_OFF && ($ff[$k] ?? false) !== false;
}

function field_required(array $ff, string $k): bool
{
    return ($ff[$k] ?? '') === FIELD_REQUIRED;
}

/** 金額（単価・小計）の列を依頼者に見せるか */
function show_money(array $ff): bool
{
    return field_on($ff, 'price');
}

/** SNSアカウント名の正規化（先頭の@や空白を除去）。不正なら null */
function normalize_sns(string $s): ?string
{
    $s = trim(mb_convert_kana($s, 'as'));
    $s = ltrim($s, '@＠ ');
    if ($s === '') {
        return '';
    }
    return preg_match('/^[A-Za-z0-9_.]{1,30}$/', $s) ? $s : null;
}

function sns_html(string $sns): string
{
    return $sns === '' ? '' : '<span class="sns">@' . h($sns) . '</span>';
}

/** 依頼者のSNS欄の検証 */
function validate_sns(array $ff, array $src, array &$errors): string
{
    if (!field_on($ff, 'sns')) {
        return '';
    }
    $raw = input_str($src, 'requester_sns', 60);
    $sns = normalize_sns($raw);
    if ($sns === null) {
        $errors[] = 'SNSアカウント名は英数字・_（アンダーバー）・.（ドット）で入力してください（@は不要です）。';
        return '';
    }
    if ($sns === '' && field_required($ff, 'sns')) {
        $errors[] = 'SNSアカウント名を入力してください。';
    }
    return $sns;
}

/** 入力項目設定フォーム（作成画面・管理ページ共通） */
function render_form_fields_settings(array $ff, bool $ready = true): void
{
    ?>
<div class="ff-settings">
  <p class="small muted">依頼者・商品名・個数は常に必須です。ほかの項目は「表示しない／任意／必須」を選べます。</p>
  <div class="ff-grid">
  <?php foreach (FORM_FIELD_DEFS as $k => $d):
      $disabled = !$ready && in_array($k, ['size', 'sns'], true); ?>
    <div class="ff-item<?= $disabled ? ' is-disabled' : '' ?>">
      <div class="ff-label"><strong><?= h($d['label']) ?></strong><span class="small muted"><?= h($d['help']) ?></span></div>
      <?php if ($k === 'subtotal'): ?>
        <label class="switch"><input type="checkbox" name="ff_subtotal" value="1"<?= $ff['subtotal'] ? ' checked' : '' ?>> 表示する</label>
      <?php else: ?>
        <div class="seg" role="radiogroup" aria-label="<?= h($d['label']) ?>">
          <?php foreach ([FIELD_OFF => '表示しない', FIELD_OPTIONAL => '任意', FIELD_REQUIRED => '必須'] as $v => $lab): ?>
            <label><input type="radio" name="ff_<?= h($k) ?>" value="<?= $v ?>"<?= $ff[$k] === $v ? ' checked' : '' ?><?= $disabled ? ' disabled' : '' ?>><span><?= $lab ?></span></label>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
  </div>
  <?php if (!$ready): ?>
    <p class="small muted">※サイズ・SNSアカウント名を使うには、phpMyAdmin で <code>sql/migrations/007_add_form_fields.sql</code> を実行してください。</p>
  <?php endif; ?>
</div>
<?php
}
