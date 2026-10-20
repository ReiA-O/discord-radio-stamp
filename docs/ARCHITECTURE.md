# 構成

- `src/Core`：JSTの開催予定、参加記録、日数・回数ロール、DB transaction、冪等処理。
- `src/Http`：署名検証、本人scope、durable ACK/queue、後続workerと本人限定応答。
- `src/Discord`・`src/Operations`：固定送信先と権限のガード、REST/Gateway adapter、既知未送信の復旧、不明な副作用の保留。
- `src/Cards`：月間カードと獲得済み日数のPNG。正式素材は資格確認後に非公開の固定保存先から読む。
- `src/Validation`：停止状態からの限定検証機構。通常運用と同じcanonical処理を利用し、実データと隔離する。

HTTPは本人の署名済みIDを固定し、先にdurableな受付を返します。通常処理の副作用はguard・lease・有限retryを経由し、結果不明の送信を新規投稿で補いません。callback暗号文は終端・期限切れで消去します。ロールと日数特典は別々の達成として管理します。

このリポジトリのIDは架空値です。実運用の配備先・DB・認証情報は含みません。`public/`以外のコードと素材、設定、DBを非公開領域へ置く構成であり、リポジトリ全体をWeb公開する用途ではありません。
