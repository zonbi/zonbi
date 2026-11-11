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

    // アフィリエイト（空欄ならリンクは表示されません）
    'affiliate' => [
        'amazon_tag' => '',   // AmazonアソシエイトのトラッキングID 例: 'yourname-22'
        'rakuten_id' => '',   // 楽天アフィリエイトID 例: '1a2b3c4d.5e6f7a8b.1a2b3c4d.5e6f7a8b'
    ],

    // 運営者情報ページ（about.php）に表示する内容。空欄の項目は表示されません。
    'operator' => [
        'name'          => '',   // 運営者名（屋号・ニックネーム可） 例: 'office saya'
        'representative'=> '',   // 代表者名（任意）
        'address'       => '',   // 所在地（個人の場合は空欄可。「請求があれば遅滞なく開示します」と表示）
        'email'         => '',   // お問い合わせ用メールアドレス
        'contact_url'   => '',   // お問い合わせフォームのURL（あれば）
        'website'       => '',   // 運営者のWebサイト 例: 'https://www.office-saya.com/'
        'sns'           => '',   // 公式SNS（任意） 例: 'https://x.com/xxxx'
    ],
];
