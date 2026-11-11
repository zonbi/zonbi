<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

$errors = [];
$form = ['title' => '', 'description' => '', 'event_url' => '', 'deadline' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $form['title'] = input_str($_POST, 'title', 100);
    $form['description'] = input_str($_POST, 'description', 2000);
    $form['event_url'] = input_str($_POST, 'event_url', 500);
    $form['deadline'] = input_str($_POST, 'deadline', 20);

    if ($form['title'] === '') {
        $errors[] = 'タイトルを入力してください。';
    }
    if (empty($_POST['agree'])) {
        $errors[] = '利用規約・免責事項への同意が必要です。';
    }
    if ($err = event_url_error($form['event_url'])) {
        $errors[] = $err;
    }
    $deadline = null;
    if ($form['deadline'] !== '') {
        $ts = strtotime($form['deadline']);
        if ($ts === false) {
            $errors[] = '締切日時の形式が正しくありません。';
        } else {
            $deadline = date('Y-m-d H:i:s', $ts);
        }
    }

    if (!$errors) {
        $adminKey = random_hex(16);
        // 共有ID（衝突したら作り直す）
        for ($i = 0; $i < 5; $i++) {
            $publicId = random_hex(8);
            try {
                $st = db()->prepare('INSERT INTO shop_sessions (public_id, admin_key, title, description, deadline) VALUES (?, ?, ?, ?, ?)');
                $st->execute([$publicId, hash('sha256', $adminKey), $form['title'], $form['description'], $deadline]);
                break;
            } catch (PDOException $e) {
                if ($e->getCode() !== '23000' || $i === 4) {
                    throw $e;
                }
            }
        }
        if ($form['event_url'] !== '') {
            $newId = (int)db()->lastInsertId();
            if ($err = save_event_url($newId, $form['event_url'])) {
                flash('error', $err);
            }
        }
        if (!empty($_POST['show_all'])) {
            try {
                db()->prepare('UPDATE shop_sessions SET show_all = 1 WHERE public_id = ?')->execute([$publicId]);
            } catch (PDOException $e) {
                flash('error', '「みんなの依頼を公開」を設定できませんでした。phpMyAdmin で sql/migrations/001_add_show_all.sql を実行してください。');
            }
        }
        flash('success', 'セッションを作成しました。このページ（管理用URL）は必ずブックマークしてください。');
        redirect(url('admin.php', ['id' => $publicId, 'key' => $adminKey, 'new' => 1]));
    }
}

render_header(config('app_name', '推し活グッズ代行まとめ'));
?>
<section class="hero">
  <h1>グッズの買い物代行、<br>注文をURLひとつでまとめよう</h1>
  <p>セッションを作ってURLを共有するだけ。依頼する人が「誰が・何を（名前・色・型）・何個・単価」を入力すると、合計額・人別の請求額・買い物リストが自動で整理されます。</p>
</section>

<section class="card">
  <h2>新しいセッションを作る</h2>
  <?php render_errors($errors); ?>
  <form method="post" action="<?= h(url('index.php')) ?>">
    <?= csrf_field() ?>
    <label>タイトル <span class="req">必須</span>
      <input type="text" name="title" value="<?= h($form['title']) ?>" maxlength="100" required placeholder="例: 11/23 ○○ポップアップ 代行">
    </label>
    <label>説明・注意事項
      <textarea name="description" rows="4" maxlength="2000" placeholder="例: 1人3点まで。手数料は1点100円。支払いは受け取り時に。"><?= h($form['description']) ?></textarea>
    </label>
    <label>イベントURL（任意）
      <input type="url" name="event_url" value="<?= h($form['event_url']) ?>" maxlength="500" inputmode="url" placeholder="例: https://example.com/event/popup">
    </label>
    <label>受付締切（任意）
      <input type="datetime-local" name="deadline" value="<?= h($form['deadline']) ?>">
    </label>
    <label class="checkbox">
      <input type="checkbox" name="show_all" value="1"<?= !empty($_POST['show_all']) ? ' checked' : '' ?>>
      みんなの依頼を記入者全員に公開する（あとから変更できます）
    </label>
    <label class="checkbox consent">
      <input type="checkbox" name="agree" value="1" required<?= !empty($_POST['agree']) ? ' checked' : '' ?>>
      <span><a href="<?= h(url('terms.php')) ?>" target="_blank">利用規約・免責事項</a>に同意します（代行に関するトラブルは当事者間で解決し、運営者は関与しません）</span>
    </label>
    <button type="submit" class="btn btn-primary btn-block">作成して共有URLを発行</button>
  </form>
</section>

<section class="card" id="recent-sessions" hidden>
  <h2>このブラウザで作成したセッション</h2>
  <ul class="recent-list" data-recent-list></ul>
  <p class="muted small">※この一覧はこのブラウザにだけ保存されています。管理用URLは別途ブックマークしておくと安心です。</p>
</section>

<section class="card how">
  <h2>使い方</h2>
  <ol>
    <li>上のフォームでセッションを作成（あなた＝代行する人）</li>
    <li>表示される<strong>共有URL</strong>をLINE・Xなどで依頼者に送る</li>
    <li>依頼者が各自、欲しいグッズと個数・単価を入力</li>
    <li><strong>管理用URL</strong>で「依頼者別」の請求額や「商品別」の買い物リストを確認・CSV出力</li>
  </ol>
</section>
<?php
render_footer();
