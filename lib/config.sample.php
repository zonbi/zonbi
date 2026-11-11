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
    'app_name' => '代行さん',
    'timezone' => 'Asia/Tokyo',
    // デザインテーマ: sakura（さくら・標準） / mono（モノトーン） / lavender（ラベンダー） / neon（ネオン） / mint（ミント）
    'theme'    => 'sakura',

    // 代行者の Google ログイン（空欄ならログイン機能は表示されません）
    // Google Cloud Console で OAuth クライアントID（ウェブアプリケーション）を作成し、
    // 承認済みリダイレクトURIに  <base_url>/google_callback.php  を登録してください。
    'google' => [
        'client_id'     => '',
        'client_secret' => '',
    ],

    // 運営者コンソール（console.php）と犯罪利用対策
    'moderation' => [
        // コンソールに入れる運営者の Googleアカウント（Googleログイン設定時）
        'admin_emails'     => [],          // 例: ['you@example.com']
        // コンソール用パスワード（12文字以上。Googleログインを使わない場合）
        'console_password' => '',
        // 危険ワード検知・通報の通知先メールアドレス（空欄なら通知しない）
        'notify_email'     => '',
        // 通知メールの送信元（サーバーのドメインのアドレス推奨。例: 'noreply@example.com'）
        'notify_from'      => '',
        // 標準の危険ワードに追加する語
        'extra_words'      => [],
    ],

    // アフィリエイト（空欄ならリンクは表示されません）
    'affiliate' => [
        'amazon_tag' => '',   // AmazonアソシエイトのトラッキングID 例: 'yourname-22'
        'rakuten_id' => '',   // 楽天アフィリエイトID 例: '1a2b3c4d.5e6f7a8b.1a2b3c4d.5e6f7a8b'
    ],

    // 楽天市場 商品検索API（関連商品を画像つきで表示。空欄なら検索リンクのみ）
    // 楽天ウェブサービス（Rakuten Developers）でアプリを登録し、アプリID・アクセスキーを取得してください。
    // 許可するWebサイト（Referer）に設置先ドメインを登録してください。
    'rakuten_api' => [
        'application_id' => '',
        'access_key'     => '',
        'endpoint'       => '',   // 空欄なら標準のURL。楽天の仕様変更時に新しいURLを指定
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
