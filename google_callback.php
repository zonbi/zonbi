<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

// Google からの戻り先（Google Cloud Console の「承認済みリダイレクトURI」に登録するURL）
if (!google_enabled()) {
    not_found('ログイン機能は現在利用できません。');
}
try {
    $info = google_handle_callback($_GET);
    login_user($info);
    flash('success', 'ログインしました。');
    redirect(url($info['return_to']));
} catch (RuntimeException $e) {
    flash('error', $e->getMessage());
    redirect(url('index.php'));
}
