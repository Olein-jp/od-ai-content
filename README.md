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
- 公開コンテンツの Markdown URL と短い説明を一覧化するルートの `/llms.txt`
- `llms.txt` への掲載を全体の既定値または投稿単位で制御
- 投稿一覧で Markdown 診断状態（正常・注意・エラー・除外・未診断）と公開可能な Markdown へのリンクを確認
- 選択した複数投稿を、WordPress Cron による分割バックグラウンド処理で一括診断
- 投稿編集画面で診断結果、変換上の注意、Markdown プレビューを確認
- GitHubのリリースタグを利用したWordPress標準のプラグイン更新通知

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

対象投稿のブロックエディターにある「OD AI Content」インスペクターパネルでは、投稿単位で次の項目を設定できます。

- Markdown 出力からの除外
- `llms.txt` への掲載・非掲載
- `llms.txt` に掲載する短い説明（最大280文字）
- Markdown の診断結果とプレビュー
- 除外されたブロックと、未検証の HTML フォールバックで変換されたブロック

Markdown 出力から除外されたコンテンツは Markdown URL が `404` を返し、元HTMLにも Markdown版のalternate情報を出力しません。また、`llms.txt` の掲載候補からも除外されます。

`llms.txt default` を有効にすると、個別設定のない既存・新規コンテンツを `llms.txt` に一括で含められます。初期値は従来どおり「含めない」です。投稿編集画面の「OD AI Content」インスペクターパネルで指定した対象・対象外は、この既定値より優先されます。

生成された一覧はサイトルートの `/llms.txt` で取得できます。短い説明が未入力の場合は、投稿の抜粋、本文の先頭部分の順に使用されます。

## 言語

プラグインの原文は英語です。日本語翻訳を `languages` ディレクトリへ同梱しているため、WordPress のサイト言語またはユーザー言語が日本語の場合は、管理画面とプラグインが生成する固定文言が日本語で表示されます。

翻訳テンプレートは `languages/od-ai-content.pot` です。追加言語はこのテンプレートから `od-ai-content-{locale}.po` と `od-ai-content-{locale}.mo` を作成できます。

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
composer i18n:verify
```

環境を停止するには次を実行します。

```bash
npm run env:stop
```

## 対応バージョンとCI

- WordPress 6.9 以降
- PHP 7.4 以降

Pull Requestと`main`ブランチへのpushでは、GitHub Actionsが設定検証、PHPCS、WordPress統合テスト、配布ZIP検証を実行します。

統合テストは、対応下限のWordPress 6.9系とPHP 7.4、WordPress 6.9系と現在の安定PHP系列である8.5、現在の安定WordPressとPHP 8.5を組み合わせた3構成です。`latest`はwp-envが取得するWordPressの現行安定版を表します。ローカルとCIは、どちらも`npm run test:php`で同じ統合テストを実行します。

## GitHub経由の更新

WordPress標準のプラグイン更新画面から、`Olein-jp/od-ai-content`のGitHub最新リリースを確認します。公開リポジトリを利用するため、トークンや認証情報の設定は不要です。

更新判定には、GitHubのリリースタグとプラグインヘッダーの`Version`を使用します。GitHub APIへ接続できない場合は更新情報を追加せず、Markdown配信など既存機能の動作を継続します。

リリース時は、リリースタグとプラグインの`Version`を一致させ、`composer package:verify`で生成した`od-ai-content.zip`をGitHub Releaseのアセットとして添付します。GitHub ActionsのArtifactだけではプラグイン更新用の配布ファイルになりません。

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
- `node_modules`、Composerの開発専用依存
- Composer、npm、PHPCS、PHPUnit、wp-envの設定
- `.gitignore`、`.gitattributes`、ローカルoverride、生成済み`build`

配布ZIPには、UpdaterとParsedownの本番依存だけを`vendor`へインストールして同梱します。

`composer package:verify`は、同じGit参照から生成した2つのZIPが同一であること、開発専用ファイルや想定外のComposer依存が含まれないこと、メインプラグインファイルと必須ヘッダーが正しいことも検証します。
