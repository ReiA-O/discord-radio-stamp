<?php
declare(strict_types=1);
namespace RadioStamp\Discord;

/** Owner validation only. No arbitrary paths, member changes, public messages, or global commands. */
final class DiscordRestClient
{
    public const APPLICATION_ID = '800000000000001003';
    public const GUILD_ID = '800000000000001001';
    public const ADMIN_USER_ID = '800000000000001021';
    public const TEXT_CHANNEL_ID = '800000000000001005';
    public const RECORDS_CHANNEL_ID = '800000000000001006';
    public const VOICE_CHANNEL_ID = '800000000000001007';
    private const BASE = 'https://discord.com/api/v10';
    private readonly RestStateStore $store;
    private readonly \Closure $now;
    private readonly bool $enabled;
    private readonly bool $registerEnabled;
    private readonly bool $connectionReady;
    private readonly bool $inlineOnly;
    private readonly int $timeoutMs;
    private readonly int $connectTimeoutMs;
    private readonly ?float $deadline;
    private int $inlineCalls = 0;
    private bool $authenticationFailed = false;
    private ?string $botId = null;
    private ?string $applicationBotId = null;

    public function __construct(private readonly HttpAdapter $http, private readonly string $applicationId, #[\SensitiveParameter] private readonly string $botToken, array $options = [])
    {
        if ($applicationId !== self::APPLICATION_ID || !self::token($botToken)) { throw new \InvalidArgumentException('discord_configuration_invalid'); }
        $this->enabled = ($options['enabled'] ?? false) === true;
        $this->registerEnabled = ($options['command_registration_enabled'] ?? false) === true;
        $this->connectionReady = ($options['connection_ready'] ?? false) === true;
        $this->inlineOnly = ($options['inline_self_bot_only'] ?? false) === true;
        $timeout = $options['timeout_ms'] ?? ($this->inlineOnly ? 900 : 5000);
        $connect = $options['connect_timeout_ms'] ?? ($this->inlineOnly ? 300 : 1500);
        $deadline = $options['deadline_monotonic'] ?? null;
        if (!is_int($timeout) || !is_int($connect) || $timeout < 100 || $timeout > ($this->inlineOnly ? 900 : 10000) || $connect < 100 || $connect > min($timeout, $this->inlineOnly ? 300 : 3000) || ($deadline !== null && (!is_numeric($deadline) || !is_finite((float) $deadline))) || ($this->inlineOnly && ($deadline === null || (float) $deadline > hrtime(true) / 1e9 + 1.8))) { throw new \InvalidArgumentException('discord_configuration_invalid'); }
        $this->timeoutMs = $timeout; $this->connectTimeoutMs = $connect; $this->deadline = $deadline === null ? null : (float) $deadline;
        $state = $options['state_store'] ?? null;
        if ($state !== null && !$state instanceof RestStateStore) { throw new \InvalidArgumentException('discord_configuration_invalid'); }
        // Live composition supplies its existing private SQLite connection.
        $this->store = $state ?? new RestStateStore(new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]));
        $this->now = isset($options['now']) && is_callable($options['now']) ? \Closure::fromCallable($options['now']) : static fn(): float => microtime(true);
    }
    public function __debugInfo(): array { return ['application_id' => $this->applicationId, 'enabled' => $this->enabled, 'credentials' => '[redacted]']; }

    public function botRead(string $operation, ?string $id = null): RestResult
    {
        if ($this->inlineOnly && ($operation !== 'self_bot' || $id !== null)) { return new RestResult(false, 'scope_rejected'); }
        if ($id !== null && $operation !== 'channel') { return new RestResult(false, 'scope_rejected'); }
        $path = match ($operation) {
            'self_bot' => '/users/@me',
            'self_application' => '/oauth2/applications/@me',
            'guild' => '/guilds/' . self::GUILD_ID,
            'bot_member' => $this->botId === null ? null : '/guilds/' . self::GUILD_ID . '/members/' . $this->botId,
            'guild_roles' => '/guilds/' . self::GUILD_ID . '/roles',
            'channel' => in_array($id, [self::TEXT_CHANNEL_ID, self::RECORDS_CHANNEL_ID, self::VOICE_CHANNEL_ID], true) ? '/channels/' . $id : null,
            'admin_voice' => '/guilds/' . self::GUILD_ID . '/voice-states/' . self::ADMIN_USER_ID,
            default => null,
        };
        if ($path === null) { return new RestResult(false, 'scope_rejected'); }
        $result = $this->request('GET', $path, $operation . ':' . ($id ?? self::GUILD_ID), $id ?? self::GUILD_ID);
        if (!$result->ok) { return $result; }
        $data = $result->data; $clean = [];
        switch ($operation) {
            case 'self_bot':
                if (($data['id'] ?? null) !== self::APPLICATION_ID || ($data['bot'] ?? false) !== true || ($this->applicationBotId !== null && $this->applicationBotId !== $data['id'])) { return new RestResult(false, 'identity_mismatch', $result->status); }
                $this->botId = $data['id']; $clean = ['id' => $data['id'], 'bot' => true]; break;
            case 'self_application':
                if (($data['id'] ?? null) !== $this->applicationId || ($data['bot']['id'] ?? null) !== self::APPLICATION_ID || ($this->botId !== null && $this->botId !== $data['bot']['id'])) { return new RestResult(false, 'identity_mismatch', $result->status); }
                $this->applicationBotId = $data['bot']['id']; $clean = ['id' => $data['id'], 'bot' => ['id' => $data['bot']['id']], 'flags' => is_int($data['flags'] ?? null) ? $data['flags'] : 0]; break;
            case 'guild':
                if (($data['id'] ?? null) !== self::GUILD_ID || !self::snowflake($data['owner_id'] ?? null)) { return new RestResult(false, 'scope_mismatch', $result->status); }
                $clean = ['id' => $data['id'], 'owner_id' => $data['owner_id']]; break;
            case 'bot_member':
                if (($data['user']['id'] ?? null) !== $this->botId || !is_array($data['roles'] ?? null) || count(array_filter($data['roles'], self::snowflake(...))) !== count($data['roles'])) { return new RestResult(false, 'response_invalid', $result->status); }
                $clean = ['user' => ['id' => $this->botId], 'roles' => array_values($data['roles']), 'pending' => ($data['pending'] ?? false) === true]; break;
            case 'guild_roles':
                if (!array_is_list($data)) { return new RestResult(false, 'response_invalid', $result->status); }
                foreach ($data as $role) {
                    if (!is_array($role) || !self::snowflake($role['id'] ?? null) || !self::permissions($role['permissions'] ?? null) || !is_int($role['position'] ?? null)) { return new RestResult(false, 'response_invalid', $result->status); }
                    $clean[] = ['id' => $role['id'], 'permissions' => $role['permissions'], 'position' => $role['position'], 'managed' => ($role['managed'] ?? false) === true];
                } break;
            case 'channel':
                if (($data['id'] ?? null) !== $id || ($data['guild_id'] ?? null) !== self::GUILD_ID || !is_int($data['type'] ?? null) || !is_array($data['permission_overwrites'] ?? null) || !is_bool($data['nsfw'] ?? null)) { return new RestResult(false, 'scope_mismatch', $result->status); }
                $overwrites = [];
                foreach ($data['permission_overwrites'] as $entry) {
                    if (!is_array($entry) || !self::snowflake($entry['id'] ?? null) || !in_array($entry['type'] ?? null, [0, 1], true) || !self::permissions($entry['allow'] ?? null) || !self::permissions($entry['deny'] ?? null)) { return new RestResult(false, 'response_invalid', $result->status); }
                    $overwrites[] = ['id' => $entry['id'], 'type' => $entry['type'], 'allow' => $entry['allow'], 'deny' => $entry['deny']];
                }
                $parent = $data['parent_id'] ?? null;
                if ($parent !== null && !self::snowflake($parent)) { return new RestResult(false, 'response_invalid', $result->status); }
                $clean = ['id' => $id, 'guild_id' => self::GUILD_ID, 'type' => $data['type'], 'parent_id' => $parent, 'permission_overwrites' => $overwrites, 'nsfw' => $data['nsfw']]; break;
            case 'admin_voice':
                if (($data['user_id'] ?? null) !== self::ADMIN_USER_ID || (isset($data['guild_id']) && $data['guild_id'] !== self::GUILD_ID) || !array_key_exists('channel_id', $data) || ($data['channel_id'] !== null && !self::snowflake($data['channel_id']))) { return new RestResult(false, 'response_invalid', $result->status); }
                $clean = ['user_id' => self::ADMIN_USER_ID, 'channel_id' => $data['channel_id']]; break;
        }
        return new RestResult(true, 'ok', $result->status, $clean);
    }

    public function adminVoice(): RestResult { return $this->botRead('admin_voice'); }

    /** PATCH replaces the original already-ephemeral defer; it never creates a new message. */
    public function editOriginal(string $interactionId, #[\SensitiveParameter] string $callbackToken, array $payload, ?string $png = null): RestResult
    {
        if ($this->inlineOnly) { return new RestResult(false, 'scope_rejected'); }
        if (!self::snowflake($interactionId) || !self::token($callbackToken) || hash_equals($this->botToken, $callbackToken) || !$this->safePayload($payload)) { return new RestResult(false, 'scope_rejected'); }
        if ($png !== null && (strlen($png) > 1048576 || !str_starts_with($png, "\x89PNG\r\n\x1a\n"))) { return new RestResult(false, 'image_invalid'); }
        $payload['allowed_mentions'] = Gateway::ALLOWED_MENTIONS;
        $payload['attachments'] = $png === null ? [] : [['id' => 0, 'filename' => 'card.png', 'description' => 'ラジオ体操スタンプカード']];
        try {
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $headers = ['Content-Type: application/json']; $body = $json;
            if ($png !== null) {
                // Boundary is internal formatting, never an authentication credential.
                $boundary = 'RadioStamp' . bin2hex(random_bytes(16));
                $headers = ['Content-Type: multipart/form-data; boundary=' . $boundary];
                $body = '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"payload_json\"\r\nContent-Type: application/json\r\n\r\n" . $json . "\r\n--" . $boundary . "\r\nContent-Disposition: form-data; name=\"files[0]\"; filename=\"card.png\"\r\nContent-Type: image/png\r\n\r\n" . $png . "\r\n--" . $boundary . "--\r\n";
            }
            $result = $this->request('PATCH', '/webhooks/' . $this->applicationId . '/' . rawurlencode($callbackToken) . '/messages/@original', 'interaction_edit', $this->applicationId, $body, $headers, false);
            return $result->ok ? new RestResult(true, 'original_updated', $result->status) : $result;
        } catch (\Throwable) { return new RestResult(false, 'payload_invalid'); }
    }

    public static function validationCommandDefinitions(): array
    {
        return [
            ['type' => 1, 'name' => 'radio-check', 'description' => '本人限定のBot接続と権限を確認', 'default_member_permissions' => '0', 'nsfw' => true, 'options' => []],
            ['type' => 1, 'name' => 'radio-card', 'description' => '本人の月間スタンプカード', 'default_member_permissions' => '0', 'nsfw' => true, 'options' => [['type' => 3, 'name' => 'month', 'description' => '対象年月（YYYY-MM）', 'required' => false, 'min_length' => 7, 'max_length' => 7]]],
            ['type' => 1, 'name' => 'radio-status', 'description' => '自分の累計参加回数と参加日数を確認', 'default_member_permissions' => '0', 'nsfw' => true, 'options' => []],
        ];
    }

    /** OFF by default. Existing unlike commands are never overwritten. */
    public function upsertValidationCommands(?array $onlyNames = null): RestResult
    {
        if (!$this->registerEnabled || !$this->connectionReady || !$this->enabled || $this->inlineOnly) { return new RestResult(false, 'command_registration_disabled'); }
        $definitions = self::validationCommandDefinitions();
        if ($onlyNames !== null) {
            if (!array_is_list($onlyNames) || $onlyNames === [] || count(array_unique($onlyNames, SORT_REGULAR)) !== count($onlyNames)
                || count(array_filter($onlyNames, static fn(mixed $name): bool => is_string($name) && in_array($name, array_column(self::validationCommandDefinitions(), 'name'), true))) !== count($onlyNames)) {
                return new RestResult(false, 'command_scope_rejected');
            }
            $definitions = array_values(array_filter($definitions, static fn(array $definition): bool => in_array($definition['name'], $onlyNames, true)));
        }
        $requestedNames = array_column($definitions, 'name');
        $path = '/applications/' . $this->applicationId . '/guilds/' . self::GUILD_ID . '/commands';
        $existing = $this->request('GET', $path, 'validation_commands_read', self::GUILD_ID);
        if (!$existing->ok) { return $existing; }
        if (!array_is_list($existing->data)) { return new RestResult(false, 'response_invalid', $existing->status); }
        $byName = [];
        foreach ($existing->data as $entry) {
            if (!is_array($entry) || !is_string($entry['name'] ?? null)) { return new RestResult(false, 'response_invalid', $existing->status); }
            if (in_array($entry['name'], $requestedNames, true)) {
                if (isset($byName[$entry['name']]) || ($entry['application_id'] ?? null) !== $this->applicationId || ($entry['guild_id'] ?? null) !== self::GUILD_ID) { return new RestResult(false, 'command_name_conflict'); }
                $byName[$entry['name']] = $entry;
            }
        }
        // Preflight all names before writing any command. Registration remains OFF by default.
        foreach ($definitions as $definition) {
            if (isset($byName[$definition['name']]) && !$this->sameCommand($byName[$definition['name']], $definition)) { return new RestResult(false, 'command_name_conflict'); }
        }
        foreach ($definitions as $definition) {
            $revision = hash('sha256', json_encode($definition, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $key = $this->applicationId . ':' . self::GUILD_ID . ':' . $definition['name'] . ':' . $revision;
            try {
                if (isset($byName[$definition['name']])) { $this->store->finishCommand($key, true, 0); continue; }
                $now = ($this->now)();
                $claim = $this->store->claimCommand($key, $now);
                if ($claim === 'done') { return new RestResult(false, 'command_missing_after_registration'); }
                if ($claim !== 'claimed') { return new RestResult(false, 'command_' . $claim); }
                $body = json_encode($definition, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $result = $this->request('POST', $path, 'validation_commands_write', self::GUILD_ID, $body, ['Content-Type: application/json'], true, $definition);
                $inspection = $result->data['command_response_check'] ?? [];
                $valid = $result->ok && ($inspection['verified'] ?? false) === true;
                $completedAt = ($this->now)();
                $this->store->finishCommand($key, $valid, $completedAt + max(2.0, $result->retryAfter), in_array($result->status, [400, 401, 403, 404], true));
                if (!$valid) { return $result->ok ? new RestResult(false, 'command_response_invalid', $result->status, ['command_response_check'=>$inspection]) : $result; }
            } catch (\Throwable) { return new RestResult(false, 'state_unavailable'); }
        }
        return new RestResult(true, 'commands_ready', 200);
    }

    /** Independent read-only inventory. Never calls a registrar or enables command writing. */
    public function inspectGuildCommands(): RestResult
    {
        if ($this->inlineOnly) { return new RestResult(false, 'scope_rejected'); }
        $definitions=array_values(array_filter(self::validationCommandDefinitions(),static fn(array $row):bool=>in_array($row['name'],['radio-card','radio-status'],true)));
        $definitions[]=ParticipantCommand::definition()+['default_member_permissions'=>'0'];
        return $this->request('GET','/applications/'.self::APPLICATION_ID.'/guilds/'.self::GUILD_ID.'/commands?with_localizations=true','command_inventory_read',self::GUILD_ID,commandReadSet:$definitions);
    }

    private function sameCommand(array $actual, array $expected): bool
    {
        return CommandResponseInspector::compare($actual, $expected)['matches'];
    }
    private function safePayload(array $payload): bool
    {
        if (array_diff(array_keys($payload), ['content', 'components', 'allowed_mentions']) !== [] || !is_string($payload['content'] ?? null) || strlen($payload['content']) > 6000) { return false; }
        if (isset($payload['allowed_mentions']) && $payload['allowed_mentions'] !== Gateway::ALLOWED_MENTIONS) { return false; }
        $rows = $payload['components'] ?? [];
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > 2) { return false; }
        $kind = null; $actions = [];
        foreach ($rows as $row) {
            if (!is_array($row) || array_diff(array_keys($row), ['type', 'components']) !== [] || ($row['type'] ?? null) !== 1 || !is_array($row['components'] ?? null) || !array_is_list($row['components']) || count($row['components']) < 1 || count($row['components']) > 5) { return false; }
            $session = null; $month = null;
            foreach ($row['components'] as $button) {
                if (!is_array($button) || array_diff(array_keys($button), ['type', 'style', 'label', 'custom_id']) !== [] || ($button['type'] ?? null) !== 2 || !is_string($button['label'] ?? null) || strlen($button['label']) > 100 || !is_string($button['custom_id'] ?? null)) { return false; }
                $id = $button['custom_id'];
                if (isset($actions[$id])) { return false; }
                $actions[$id] = true;
                if (preg_match('/\Aradio:memorial:([1-9][0-9]{0,2})\z/D', $id, $milestone) === 1
                    && in_array((int)$milestone[1], \RadioStamp\Core\ParticipationDays::MILESTONES, true)) {
                    // Accept old cached private controls as well as the current neutral artwork label.
                    if ($kind === 'controls' || ($button['style'] ?? null) !== 2
                        || !in_array($button['label'], [$milestone[1] . '日 仮カード', $milestone[1] . '日 特典カード'], true)) { return false; }
                    $kind = 'memorial'; continue;
                }
                if ($kind === 'memorial' || count($rows) > 1 || count($row['components']) > 3) { return false; }
                $kind = 'controls';
                if (preg_match('/\Aradio:(start|attend):(.+)\z/D', $id, $parts) === 1) {
                    if (!\RadioStamp\Core\Schedule::validSessionId($parts[2]) || ($button['style'] ?? null) !== ($parts[1] === 'start' ? 1 : 3) || $button['label'] !== ($parts[1] === 'start' ? '体操開始' : '参加')) { return false; }
                    $target = substr($id, strlen('radio:' . $parts[1] . ':'));
                    if ($session !== null && $session !== $target) { return false; }
                    $session = $target;
                    if ($month !== null && $month !== substr($target, 0, 7)) { return false; }
                    $month = substr($target, 0, 7);
                } elseif (preg_match('/\Aradio:card:(20[0-9]{2}-(0[1-9]|1[0-2]))\z/D', $id, $parts) === 1) {
                    if (($button['style'] ?? null) !== 2 || $button['label'] !== 'カード表示' || ($month !== null && $month !== $parts[1])) { return false; }
                    $month = $parts[1];
                } else { return false; }
            }
        }
        return true;
    }
    private function request(string $method, string $path, string $route, string $major, string $body = '', array $extraHeaders = [], bool $botAuth = true, ?array $commandDefinition = null, ?array $commandReadSet = null): RestResult
    {
        if (!$this->enabled) { return new RestResult(false, 'transport_disabled'); }
        if ($botAuth && $this->authenticationFailed) { return new RestResult(false, 'unauthorized', 401, [], 0.0, true); }
        try {
            $now = ($this->now)(); $wait = $this->store->cooldown($route, $now);
            if ($wait > 0) { return new RestResult(false, 'rate_limited', 429, [], $wait, true); }
            if ($this->inlineOnly && $this->inlineCalls >= 1) { return new RestResult(false, 'operation_budget_exhausted'); }
            $timeout = $this->timeoutMs;
            if ($this->deadline !== null) {
                $remaining = (int) floor(($this->deadline - hrtime(true) / 1e9) * 1000);
                if ($remaining < 100) { return new RestResult(false, 'operation_deadline'); }
                $timeout = min($timeout, $remaining);
            }
            $headers = ['Accept: application/json', 'User-Agent: DiscordBot (https://hosting-user.example.invalid, 0.1)'];
            if ($botAuth) { $headers[] = 'Authorization: Bot ' . $this->botToken; }
            if ($this->inlineOnly) { $this->inlineCalls++; }
            $response = $this->http->exchange(new HttpRequest($method, self::BASE . $path, array_merge($headers, $extraHeaders), $body, $timeout, min($this->connectTimeoutMs, $timeout), 1048576, $this->inlineOnly));
            // Retry-After and Reset-After are relative to reception, not dispatch.
            $receivedAt = ($this->now)();
            if ($this->deadline !== null && hrtime(true) / 1e9 >= $this->deadline) { return new RestResult(false, 'operation_deadline'); }
            if ($response->error !== null || $response->status === 0) {
                $code = in_array($response->error, ['transport_timeout', 'response_too_large', 'transport_unavailable', 'transport_async_dns_required'], true) ? $response->error : 'transport_failed';
                return new RestResult(false, $code);
            }
            if (strlen($response->body) > 1048576) { return new RestResult(false, 'response_too_large'); }
            $data = $response->body === '' ? [] : json_decode($response->body, true, 32);
            $data = is_array($data) ? $data : [];
            $rateHeaders = [];
            foreach ($response->headers as $key => $value) {
                if (is_string($key) && (is_string($value) || is_int($value))) { $rateHeaders[strtolower($key)] = (string) $value; }
            }
            $durations = []; $invalidRate = false;
            foreach ([$rateHeaders['retry-after'] ?? null, $data['retry_after'] ?? null] as $duration) {
                if ($duration === null) { continue; }
                if (!is_numeric($duration) || !is_finite((float) $duration) || (float) $duration < 0) { $invalidRate = true; continue; }
                $durations[] = (float) $duration;
            }
            if ($response->status === 429 && $durations === []) { $invalidRate = true; }
            $retry = max(0.25, ...($durations === [] ? [1.0] : $durations));
            if ($invalidRate) { $retry = max(86400.0, $retry); }
            $global = $invalidRate || ($data['global'] ?? false) === true || strtolower($rateHeaders['x-ratelimit-global'] ?? '') === 'true' || ($rateHeaders['x-ratelimit-scope'] ?? '') === 'global';
            $this->store->observe($route, $major, $rateHeaders, $response->status, $retry, $global, $receivedAt);
            if ($botAuth && $response->status === 401) { $this->authenticationFailed = true; }
            if ($response->status >= 200 && $response->status < 300) {
                if ($commandReadSet !== null) {
                    $inspection=CommandResponseInspector::inspectCollection($response->status,$response->body,$commandReadSet);
                    return new RestResult($inspection['valid'],$inspection['valid']?'command_inventory':'command_inventory_invalid',$response->status,['command_inventory'=>$inspection]);
                }
                if ($commandDefinition !== null) {
                    $inspection = CommandResponseInspector::inspect($response->status, $response->body, $commandDefinition);
                    // Registration never returns the raw response or unknown fields to callers.
                    return new RestResult(true, 'ok', $response->status, ['command_response_check'=>$inspection]);
                }
                if ($response->body !== '' && json_last_error() !== JSON_ERROR_NONE) { return new RestResult(false, 'response_invalid', $response->status); }
                return new RestResult(true, 'ok', $response->status, $data);
            }
            $code = match (true) { $response->status === 401 => 'unauthorized', $response->status === 403 => 'forbidden', $response->status === 404 => 'not_found', $response->status === 429 => $invalidRate ? 'rate_limit_invalid' : 'rate_limited', $response->status >= 300 && $response->status < 400 => 'redirect_refused', $response->status >= 500 => 'discord_unavailable', default => 'discord_rejected' };
            return new RestResult(false, $code, $response->status, [], $response->status === 429 ? $retry : 0.0);
        } catch (\Throwable) { return new RestResult(false, 'transport_failed'); }
    }
    private static function snowflake(mixed $value): bool { return is_string($value) && preg_match('/\A[1-9][0-9]{16,19}\z/D', $value) === 1; }
    private static function token(string $value): bool { return preg_match('/\A[A-Za-z0-9._-]{20,256}\z/D', $value) === 1; }
    private static function permissions(mixed $value): bool { return is_string($value) && preg_match('/\A(?:0|[1-9][0-9]{0,18})\z/D', $value) === 1; }
}
