# OD AI Content

WordPress の公開コンテンツを、意味構造と出典情報を保った Markdown 代替表現として配信するプラグインです。

## MVP の機能

- 投稿・固定ページの `index.html.md` URL
- タイトル、canonical URL、言語、著者、公開日、更新日、投稿タイプ、タクソノミーの YAML front matter
- コアブロックの意味を保った Markdown 変換
- 未対応ブロックのレンダリング HTML から Markdown へのフォールバック
- ナビゲーション、ソーシャルリンク、クエリーループ、スペーサーの除外
- HTML の `<link rel="alternate" type="text/markdown">`
- HTML レスポンスの `Link` ヘッダー
- Markdown レスポンスの `Content-Type`、`Content-Language`、canonical `Link`、`X-Robots-Tag`
- 下書き、非公開、パスワード保護コンテンツの非公開
- カスタム投稿タイプ、ブロック変換、メタデータ、最終文書のフィルター

通常ページが次の場合、

```text
https://example.com/blog/example/
```

Markdown は次の URL で取得できます。

```text
https://example.com/blog/example/index.html.md
```

## 開発

```bash
npm install
composer install
npm run env:start
composer lint
npm run test:php
```

環境を停止するには次を実行します。

```bash
npm run env:stop
```
