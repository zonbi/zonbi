<?php
// このファイルを lib/config.php にコピーして、CoreServer の DB 情報を記入してください。
return [
    'db' => [
        // CoreServer のコントロールパネル「データベース」に表示されるホスト名
        'host'     => 'localhost',
        'port'     => 3306,
        'name'     => 'your_db_name',
        'user'     => 'your_db_user',
        'password' => 'your_db_password',
    ],
    // アプリの公開URL（末尾スラッシュなし）。空なら自動判定。
    // 例: 'https://example.com/zonbi'
    'base_url' => '',
    'app_name' => '推し活グッズ代行まとめ',
    'timezone' => 'Asia/Tokyo',
];
