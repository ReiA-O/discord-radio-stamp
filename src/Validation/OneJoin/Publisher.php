<?php
declare(strict_types=1);
namespace RadioStamp\Validation\OneJoin;

use RadioStamp\Discord\HttpAdapter;
use RadioStamp\Discord\HttpRequest;
use RadioStamp\Http\PrivateConfig as Scope;

/** Can post once in one fixed VC, and delete only that confirmed Bot message. */
final class Publisher
{
    private const URL = 'https://discord.com/api/v10/channels/' . Scope::VOICE_ID . '/messages';
    public function __construct(private readonly HttpAdapter $http, private readonly Store $store, #[\SensitiveParameter] private readonly string $botToken) {}
    public function __debugInfo(): array { return ['scope' => Trial::NAME, 'credentials' => '[redacted]']; }
    public function publish(bool $identityVerified): bool
    {
        if (!$identityVerified) { return false; }
        $claim = $this->store->update(static function (array &$state): bool {
            if ($state['post_attempted'] || !$state['seeded']) { return false; }
            $state['post_attempted'] = true; $state['cleanup'] = 'manual_required'; return true;
        });
        if (!$claim) { return false; }
        $state = $this->store->state();
        $payload = Trial::controls($state['session'], $state['month']);
        try {
            $response = $this->http->exchange(new HttpRequest('POST', self::URL, $this->headers(), json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 3000, 1000));
            if ($response->error !== null || $response->status !== 200 || strlen($response->body) > 1048576) { return false; }
            $message = json_decode($response->body, true, 24, JSON_THROW_ON_ERROR);
            if (!is_array($message) || !Scope::snowflake($message['id'] ?? null) || ($message['channel_id'] ?? null) !== Scope::VOICE_ID ||
                ($message['author']['id'] ?? null) !== Scope::APPLICATION_ID || ($message['author']['bot'] ?? null) !== true ||
                isset($message['webhook_id']) || ($message['nonce'] ?? null) !== Trial::NONCE || ($message['content'] ?? null) !== $payload['content'] ||
                !is_int($message['flags'] ?? null) || ($message['flags'] & 64) !== 0 || !$this->componentsMatch($message['components'] ?? null, $payload['components'])) { return false; }
            $this->store->update(static function (array &$state) use ($message): void { $state['message_id'] = $message['id']; $state['cleanup'] = 'pending'; });
            return true;
        } catch (\Throwable) { return false; }
    }
    public function cleanup(bool $identityVerified): bool
    {
        $state = $this->store->state();
        if (!$state['post_attempted']) { return true; }
        if ($state['cleanup'] === 'deleted') { return true; }
        if (!$identityVerified || !Scope::snowflake($state['message_id']) || $state['delete_attempted']) { return false; }
        $claimed = $this->store->update(static function (array &$state): bool {
            if ($state['delete_attempted']) { return false; }
            $state['delete_attempted'] = true; $state['cleanup'] = 'manual_required'; return true;
        });
        if (!$claimed) { return false; }
        try {
            $response = $this->http->exchange(new HttpRequest('DELETE', self::URL . '/' . $state['message_id'], $this->headers(), '', 3000, 1000));
            if ($response->error !== null || $response->status !== 204 || $response->body !== '') { return false; }
            $this->store->update(static function (array &$state): void { $state['cleanup'] = 'deleted'; }); return true;
        } catch (\Throwable) { return false; }
    }
    private function componentsMatch(mixed $actual, array $expected): bool
    {
        if (!is_array($actual) || !array_is_list($actual) || count($actual) !== 1 || ($actual[0]['type'] ?? null) !== 1 || !is_array($actual[0]['components'] ?? null) || count($actual[0]['components']) !== 2) { return false; }
        foreach ($expected[0]['components'] as $i => $button) {
            foreach ($button as $key => $value) { if (($actual[0]['components'][$i][$key] ?? null) !== $value) { return false; } }
            if (isset($actual[0]['components'][$i]['url'])) { return false; }
        }
        return true;
    }
    private function headers(): array { return ['Authorization: Bot ' . $this->botToken, 'Accept: application/json', 'Content-Type: application/json', 'User-Agent: DiscordBot (https://hosting-user.example.invalid, 0.1)']; }
}
