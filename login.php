<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

// 代行者ログイン: Google の認可画面へ移動
if (!google_enabled()) {
    not_found('ログイン機能は現在利用できません。');
}
if (current_user()) {
    redirect(url(safe_return_to($_GET['return_to'] ?? '')));
}
if (!login_tables_ready()) {
    flash('error', 'データベースの更新が必要です。phpMyAdmin で sql/migrations/003_add_google_login.sql を実行してください。');
    redirect(url('index.php'));
}
redirect(google_auth_url((string)($_GET['return_to'] ?? '')));
