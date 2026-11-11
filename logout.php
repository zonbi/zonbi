<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(url('index.php'));
}
csrf_check();
logout_user();
flash('success', 'ログアウトしました。');
redirect(url('index.php'));
