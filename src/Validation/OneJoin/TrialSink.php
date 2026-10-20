<?php
declare(strict_types=1);
namespace RadioStamp\Validation\OneJoin;

use RadioStamp\Discord\DiscordRestClient;
use RadioStamp\Http\FollowupSink;

final class TrialSink implements FollowupSink
{
    public bool $sent = false;
    public bool $image = false;
    public function __construct(private readonly DiscordRestClient $client, private readonly Store $store, private readonly string $step) {}
    public function send(string $interactionId, string $token, array $result, ?string $png): bool
    {
        $this->image = $png !== null && str_starts_with($png, "\x89PNG\r\n\x1a\n") && @getimagesizefromstring($png) !== false;
        $code = Trial::safeCode($result['code'] ?? null);
        $expected = Trial::expected($this->step, $code, $this->store->counts(), $this->image);
        $needsPresence = in_array($this->step, ['inside', 'duplicate'], true);
        if ($needsPresence && !$this->store->presenceRecorded($interactionId)) { $expected = false; }
        $content = '【本人限定・別DBテスト】' . (!$expected ? '予定の結果と一致しません。追加操作を止め、復元と片付けを行います。' : match ($this->step) {
            'check' => '接続確認とVCチャットのテスト投稿が完了しました。対象VCに接続したままお待ちください。こちらで参加・再参加・カードを順に確認します。',
            'inside' => 'テスト参加を1件記録しました。本番記録への加算はありません。この結果を確認後、同じ「参加」をもう1回押してください。',
            'duplicate' => '同じ開催の参加は記録済みです。テスト加算は1件のまま。「カード表示」を押してください。',
            'card' => '月間テストカードです。テスト印は1個です。退室できます。共有テスト投稿の削除と入口の復元を続けます。',
            'cleanup' => 'テストを停止し、共有テスト投稿を削除しました。入口と一時ファイルの復元を続けます。',
            default => 'テストを停止しました。',
        });
        if ($needsPresence && ($diagnostic = $this->store->operation($interactionId)) !== null && $diagnostic['presence_classification'] !== null) {
            $content .= "\n在室HTTP：" . ($diagnostic['presence_http_status'] === null ? '不明' : (string)$diagnostic['presence_http_status']) . ' / 分類：' . $diagnostic['presence_classification'];
        }
        $this->sent = $this->client->editOriginal($interactionId, $token, ['content' => $content, 'components' => []], $png)->ok;
        return $this->sent;
    }
}
