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

## 設定

WordPress 管理画面の「設定 → OD AI Content」で、Markdown 出力全体の有効・無効と対象投稿タイプを設定できます。

対象投稿の編集画面にある「OD AI Content」メタボックスでは、投稿単位で Markdown 出力から除外できます。除外されたコンテンツは Markdown URL が `404` を返し、元HTMLにも Markdown版のalternate情報を出力しません。

## ブロック変換

次のコアブロックは、本文の意味を保持する専用処理または検証済みの変換処理を持ちます。

- 見出し、段落、リスト、引用、表、コード、画像
- `core/details`: summaryと開閉内コンテンツを常に出力
- `core/embed`: 埋め込みURLを明示的なリンクとして出力
- `core/buttons`: 各ボタンのラベルとリンク先を出力
- `core/media-text`: 画像・メディアと内部コンテンツを意味順に出力
- グループ、カラム、カバーなどのコンテナブロック

入れ子のリストは階層と項目順を維持します。未対応ブロックは、レンダリングHTMLをMarkdownへ変換するフォールバックを利用するため、内容を黙って削除しません。

`core/query`、ナビゲーション、ソーシャルリンク、スペーサーは既定では除外されます。`core/query` を記事本文として扱う場合は、以下のカスタムコンバーターAPIまたは既存の `od_ai_content_block_markdown` フィルターで明示的に変換できます。

### 独自ブロックコンバーター

独自ブロック用コンバーターは `Block_Markdown_Converter` を実装し、`od_ai_content_block_converters` フィルターへ登録します。登録順に `supports()` が評価され、最初に対応を表明して文字列を返したコンバーターが既定処理を置き換えます。

```php
use Olein\OdAiContent\Block_Converter;
use Olein\OdAiContent\Block_Markdown_Converter;

final class Example_Card_Converter implements Block_Markdown_Converter {

	public function supports( array $block ) {
		return 'example/card' === $block['blockName'];
	}

	public function convert( array $block, Block_Converter $converter ) {
		return "## Card\n\n" . $converter->convert_blocks( $block['innerBlocks'] );
	}
}

add_filter(
	'od_ai_content_block_converters',
	static function ( $converters ) {
		$converters[] = new Example_Card_Converter();
		return $converters;
	}
);
```

コンバーターが例外を送出する、または文字列以外を返した場合は、次のコンバーターまたは既定のHTMLフォールバックへ進みます。変換例外は `od_ai_content_block_converter_error` アクションで監視できます。

既存の低レベルフィルターも引き続き利用できます。

- `od_ai_content_block_markdown`: 既定処理より前に、単一ブロックのMarkdownを直接返す
- `od_ai_content_converted_block_markdown`: HTMLフォールバック後のMarkdownを変更する

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

## 対応バージョンとCI

- WordPress 6.9 以降
- PHP 7.2.24 以降

Pull Requestと`main`ブランチへのpushでは、GitHub Actionsが設定検証、PHPCS、WordPress統合テスト、配布ZIP検証を実行します。

統合テストは、対応下限のWordPress 6.9系と現在の安定版、および最低PHP系列の7.2と現在の安定系列である8.5を組み合わせた4構成です。`latest`はwp-envが取得するWordPressの現行安定版を表します。ローカルとCIは、どちらも`npm run test:php`で同じ統合テストを実行します。

ローカルでCI相当の主要な検証を行うには、次を実行します。

```bash
composer validate --strict
composer lint
npm run test:php
composer package:verify
```

## 配布ZIP

配布ZIPは、Gitの同一コミットから追跡ファイルだけを取得し、`.gitattributes`の`export-ignore`設定を適用して生成します。

```bash
composer package:verify
unzip -Z1 build/od-ai-content.zip
```

生成先は`build/od-ai-content.zip`です。ZIP内では`od-ai-content/`が最上位ディレクトリとなり、次の開発専用ファイルを除外します。

- `.github`、テスト、開発・配布スクリプト
- `node_modules`、開発用`vendor`
- Composer、npm、PHPCS、PHPUnit、wp-envの設定
- `.gitignore`、`.gitattributes`、ローカルoverride、生成済み`build`

`composer package:verify`は、同じGit参照から生成した2つのZIPが同一であること、開発専用ファイルが含まれないこと、メインプラグインファイルと必須ヘッダーが正しいことも検証します。
