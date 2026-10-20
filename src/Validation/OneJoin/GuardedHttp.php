<?php
declare(strict_types=1);
namespace RadioStamp\Validation\OneJoin;

use RadioStamp\Core\Clock;
use RadioStamp\Discord\HttpAdapter;
use RadioStamp\Discord\HttpRequest;
use RadioStamp\Discord\HttpResponse;
use RadioStamp\Http\PrivateConfig as Scope;

/** Enforces the trial's entire REST allowlist, after formal ACK only. */
final class GuardedHttp implements HttpAdapter
{
    public function __construct(private readonly HttpAdapter $inner, private readonly Store $store, private readonly string $operationId, private readonly Clock $clock) {}
    public function exchange(HttpRequest $request): HttpResponse
    {
        $state = $this->store->state(); $op = $this->store->operation($this->operationId);
        if ($op === null) { return self::refused(); }
        $url = $request->url(); $path = parse_url($url, PHP_URL_PATH); $method = $request->method();
        if (parse_url($url, PHP_URL_HOST) !== 'discord.com' || parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_QUERY) !== null) { return self::refused(); }
        $prefix = '/api/v10'; $voice = $prefix . '/channels/' . Scope::VOICE_ID . '/messages';
        $self = $prefix . '/users/@me';
        $presence = $prefix . '/guilds/' . Scope::GUILD_ID . '/voice-states/' . Scope::OWNER_ID;
        $checkReads = [$self, $prefix . '/oauth2/applications/@me', $prefix . '/guilds/' . Scope::GUILD_ID,
            $prefix . '/guilds/' . Scope::GUILD_ID . '/members/' . Scope::APPLICATION_ID,
            $prefix . '/guilds/' . Scope::GUILD_ID . '/roles'];
        foreach ([Scope::GUIDE_ID, Scope::RECORDS_ID, Scope::VOICE_ID] as $channel) { $checkReads[] = $prefix . '/channels/' . $channel; }
        $patch = $method === 'PATCH' && is_string($path) && preg_match('~\A/api/v10/webhooks/' . Scope::APPLICATION_ID . '/[A-Za-z0-9._-]{20,256}/messages/@original\z~D', $path) === 1;
        $delete = $method === 'DELETE' && is_string($state['message_id']) && $path === $voice . '/' . $state['message_id'] && $state['delete_attempted'];
        $post = $method === 'POST' && $op['step'] === 'check' && $path === $voice && $state['post_attempted'] && $state['message_id'] === null &&
            $request->body() === json_encode(Trial::controls($state['session'], $state['month']), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $get = $method === 'GET' && ($path === $self || ($op['step'] === 'check' && in_array($path, $checkReads, true)) ||
            (in_array($op['step'], ['inside', 'duplicate'], true) && $path === $presence));
        if (!$get && !$patch && !$post && !$delete) { return self::refused(); }
        $botAuth = count(array_filter($request->headers(), static fn(string $header): bool => str_starts_with($header, 'Authorization: Bot '))) === 1;
        if (($get || $post || $delete) !== $botAuth || ($state['bot_suspended'] && $botAuth)) { return self::refused(); }
        $cleanupRequest = $op['step'] === 'cleanup' || $delete || $patch;
        if (!$this->store->consumeRequest($this->operationId, $this->clock, $cleanupRequest)) { return self::refused(); }
        try { $response = $this->inner->exchange($request); } catch (\Throwable) { return self::refused(); }
        if ($response->status === 429 || ($botAuth && $response->status === 401)) {
            $this->store->update(static function (array &$state) use ($response, $botAuth): void {
                if ($response->status === 429) { $state['api_suspended'] = true; }
                if ($botAuth && $response->status === 401) { $state['bot_suspended'] = true; }
            });
        }
        // A late presence response cannot make attendance pass after trial expiry.
        if ($get && $this->clock->now()->getTimestamp() >= $state['expires_at'] && $op['step'] !== 'cleanup') { return self::refused(); }
        return $response;
    }
    private static function refused(): HttpResponse { return new HttpResponse(0, [], '', 'transport_failed'); }
}
