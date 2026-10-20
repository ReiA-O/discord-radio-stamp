<?php
declare(strict_types=1);
namespace RadioStamp\Http;

use RadioStamp\Discord\DiscordRestClient;
use RadioStamp\Operations\SafeDiagnostics;

/** Only edits an existing ephemeral defer, with fixed messages and no new public post. */
final class DiscordRestFollowupSink implements FollowupSink
{
    public function __construct(private readonly DiscordRestClient $client,private readonly ?SafeDiagnostics $diagnostics=null) {}
    public function send(string $interactionId, string $token, array $result, ?string $png): bool
    {
        $content = match ($result['code'] ?? '') {
            'started' => '体操を開始しました。参加ボタンで記録してください。',
            'attended' => '今回の参加を記録しました。',
            'already_started' => 'この回は開始済みです。',
            'already_attended' => '今回の参加は記録済みです。',
            'receipt_opened' => 'VCチャットの共有投稿で参加受付を開始しました。参加ボタンを押してください。',
            'receipt_already_open' => 'この開催は受付中です。VCチャットの同じ参加ボタンを使ってください。閉場時刻は変わりません。',
            'receipt_announcement_unconfirmed' => '受付案内の投稿を確認できないため保留しています。重複投稿を避けて管理者が確認します。',
            'receipt_request_expired' => 'コマンドの処理期限を過ぎたため受付を開始しませんでした。',
            'card_ready' => "本人用の月間カードです。\n添付のPNGを開き、Discordの画像保存・ダウンロード機能で端末に保存してください。\n達成済みの参加日数の特典カードは、下のボタンから取得できます。画像やリンクを表示できなくなったら /radio-card から再取得できます。",
            'memorial_ready' => "参加日数の節目の特典カードです。\n添付のPNGを開き、Discordの画像保存・ダウンロード機能で端末に保存してください。\n画像やリンクを表示できなくなったら /radio-card → 達成済み日数の「特典カード」から再取得できます。獲得記録は残ります。",
            'card_render_failed' => "画像を準備できませんでした。獲得記録は残っています。\n後でもう一度 /radio-card → 達成済み日数の「特典カード」から取得してください。解消しない場合は管理者に連絡してください。",
            'memorial_locked' => 'この参加日数の節目にはまだ到達していません。',
            'status_ready' => '本人の参加記録',
            'bot_check_passed', 'validation_ready', 'bot_validation_ready', 'check_ready', 'connection_ready' => '本人限定の接続確認に成功しました。自動運用は停止中です。',
            'validation_bot_identity_unverified' => '対象Botの本人確認に失敗したため、開始・参加・カード処理を行いませんでした。Botの接続設定を確認してください。',
            'validation_identity_required' => '対象Botの本人確認処理が用意されていないため、操作を停止しました。',
            'bot_check_self_bot_failed' => 'Bot資格情報による本人確認ができませんでした。Botの接続設定を確認してください。',
            'bot_check_self_application_failed' => '対象アプリとの照合ができませんでした。Botとアプリの組合せを確認してください。',
            'bot_check_guild_failed' => '対象サーバーを確認できませんでした。Botの所属先を確認してください。',
            'bot_check_bot_member_failed' => '対象サーバー内のBotメンバー情報を確認できませんでした。',
            'bot_check_guild_roles_failed' => '対象サーバーの権限一覧を確認できませんでした。',
            'bot_check_channel_failed' => '指定チャンネルの情報を確認できませんでした。Botの閲覧権限を確認してください。',
            'bot_check_identity_failed' => 'Bot・アプリ・対象サーバーの照合が一致しませんでした。',
            'bot_check_permissions_unknown' => 'Botの有効な権限を確定できませんでした。',
            'bot_check_permissions_failed' => '必要なチャンネル権限の不足、または検証に不要な強い権限が見つかりました。下の要確認項目を確認してください。',
            'private_runtime_check_failed' => '非公開保存先の検査が完了しませんでした。下の要確認項目を確認してください。',
            'not_in_voice', 'not_in_target_voice' => '対象の体操VCへの在室を確認できませんでした。',
            'voice_unknown', 'voice_stale' => '在室を確認できないため処理を保留しました。',
            'not_started', 'session_not_started' => 'この回はまだ開始されていません。',
            'session_closed', 'outside_window', 'not_open' => 'この回は受付時間外です。',
            default => '処理結果を確定できませんでした。管理者の確認が必要です。',
        };
        if (($result['code'] ?? '') === 'status_ready') {
            $counts = $result['counts'] ?? null;
            if (!is_array($counts) || array_keys($counts) !== ['total_count', 'total_days']
                || !is_int($counts['total_count']) || !is_int($counts['total_days'])
                || $counts['total_count'] < 0 || $counts['total_days'] < 0 || $counts['total_days'] > $counts['total_count']) { return false; }
            $content = '累計参加：' . number_format($counts['total_count']) . '回'
                . "\n累計参加日数：" . number_format($counts['total_days']) . '日（JST、同日は1日）';
        }
        $labels = [
            'bot_identity' => 'Bot本人', 'application_identity' => 'アプリ一致', 'guild_membership' => '対象サーバー所属',
            'guide_permissions' => '体操案内の権限', 'records_permissions' => '参加記録の権限', 'voice_permissions' => '体操VCの権限', 'restricted_permissions' => '権限の制限',
            'runtime_curl' => 'HTTP機能', 'runtime_gd' => '画像機能', 'runtime_sodium' => '署名検証機能', 'runtime_pdo_sqlite' => 'SQLite機能',
            'runtime_sqlite_online_backup' => 'SQLiteバックアップ機能',
            'runtime_mbstring' => '日本語文字機能', 'runtime_fastcgi_finish_request' => '応答後処理', 'runtime_symlink' => 'リンク検査機能',
            'private_private_storage' => '非公開保存先', 'private_private_write' => '非公開書込み', 'private_public_candidate_guard' => '公開領域の拒否',
            'private_dangling_symlink_guard' => '切れたリンクの拒否', 'private_scratch_permissions' => '一時領域の権限', 'private_cleanup' => '検査後の片付け',
        ];
        foreach ($labels as $key => $label) {
            if (is_bool($result['checks'][$key] ?? null)) { $content .= "\n" . $label . '：' . ($result['checks'][$key] ? '確認済み' : '要確認'); }
        }
        if (isset($result['runtime_status'])) {
            $content .= "\n保存先検査：" . match ($result['runtime_status']) { 'pass' => '完了', 'skip' => '一部未測定', 'fail' => '要確認', default => '未実施' };
        }
        $started=hrtime(true);
        $sent=$this->client->editOriginal($interactionId, $token, ['content' => $content, 'components' => $result['components'] ?? []], $png);
        $this->diagnostics?->record('followup',['ok'=>$sent->ok,'sent'=>$sent->ok,'code'=>$sent->code,'status'=>$sent->status,
            'route'=>'followup','duration_ms'=>(int)((hrtime(true)-$started)/1000000)]);
        return $sent->ok;
    }
}
