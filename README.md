# Discord ラジオ体操スタンプ

**非公開で保存する、匿名化した開発用スナップショットです。** 実運用は停止中です。実サーバーのID・参加者情報・認証情報・DB・運用ログ・監査履歴は含めません。公開や運用開始は、このリポジトリの作成では行いません。

体操後の参加受付、JST参加日数、月間スタンプカード、日数特典、参加回数ロール、達成通知、受付終了と安全な復旧処理をPHPで実装しています。毎日00:00・04:00・07:00・12:30・22:00 JSTの5枠と、IC・コンデンサ・LED・プラグ・電池の5種類に対応します。時刻は共通Schedule設定で変更できます。

## ローカルで試す

1. PHP 8.3を用意し、`curl`、`gd`（FreeType/PNG）、`sodium`、`pdo_sqlite`、`sqlite3`、`mbstring`を有効にします。メモリ上限は128M以上を用意します。PHPやDLLをこのリポジトリから配布しません。
2. 日本語フォントを用意します。WindowsはBIZ UDゴシックまたはMSゴシック、Linuxはコードの許可リストにあるIPA/VL/Noto CJK等を利用します。任意の外部フォントURLは読み込みません。
3. リポジトリのルートでオフライン試験を実行します。

PowerShell:

```powershell
$env:RADIO_STAMP_TEST_ARTIFACT_DIR = Join-Path $env:TEMP ('radio-stamp-tests-' + [guid]::NewGuid().ToString('N'))
php -d memory_limit=128M tests/run.php
Remove-Item Env:\RADIO_STAMP_TEST_ARTIFACT_DIR
```

POSIX shell:

```sh
RADIO_STAMP_TEST_ARTIFACT_DIR="$(mktemp -d)" php -d memory_limit=128M tests/run.php
```

試験は架空ユーザー、隔離SQLite、fake HTTP/Gatewayを使用します。本番Botへの接続や実Discord投稿はしません。結果・PNGはリポジトリ外の一時領域へ出力してください。SQLite 3.53.4と3.26.0の双方で検証する方式を採っています。実行結果と環境依存スキップは [VALIDATION.md](docs/VALIDATION.md) に記録します。

## 設定と配置の境界

`config/*.example.php` は資格情報なし・既定OFFの例です。実設定は公開ディレクトリ外に別途置き、コミットしません。公開するファイルと非公開のコード・設定・DBを分離します。停止状態の設定をコピーしただけでは本番処理を有効にできません。

この保存用コピーでは、コードに固定されたBot・サーバー・チャンネル・管理者・ロールの許可範囲も架空IDへ置き換えています。そのまま実サーバーへ配備する候補ではありません。実運用前には、許可された対象への設定とコード上の固定スコープを照合し、署名・Bot本人確認・権限・DB保全・停止/復旧を別途確認する必要があります。秘密値をissue、commit、ログへ貼り付けないでください。

## カード

日数特典は10・30・60・100・300・365日の6段階です。本人は `/radio-card` → 達成済み日数の「特典カード」→ 本人限定応答の添付PNGを画像保存・ダウンロードして受け取ります。再取得で参加記録や資格は減りません。

正式な特典カードの絵は後日追加します。現在の `assets/memorial-cards/active.json` は `{}` で、文字だけの仮カードを生成できます。完成PNG1200×800・1MiB以下を版付きファイル名で非公開releaseに追加して対応表を切り替えます。旧版と履歴を残します。[素材の手順](assets/memorial-cards/README.md) を参照してください。

## 保存していないものと残る確認

- 認証情報・秘密鍵・token・環境実値、実参加者ID/履歴、SQLite/WAL/backup、診断・運用ログ、元の監査report/配備成果/個人パス、画像アーカイブと実機観測資料は含めません。Git履歴もこの匿名化コピーから新規に開始します。
- 元の実機観測・画像保全の試験は保存先に持ち込まず、必要なコードfixtureだけを匿名化して `tests/fixtures` へ移します。元の監査結果を再現・公開するリポジトリではありません。
- 実DiscordでのPC/スマートフォン保存、実添付URL失効後の再取得、実サーバーのPHP/GD/フォント/素材読取りは未検証部分です。Windowsで実行できないsymlinkケース等はスキップとして表示します。

GitHub Pages、CI/CD自動配備、Cron、worker自動起動、共同編集者追加、公開切り替えは設定していません。
