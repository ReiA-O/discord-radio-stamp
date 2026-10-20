<?php
declare(strict_types=1);
namespace RadioStamp\Http;

use RadioStamp\Discord\DiscordRestClient;

/** Reads only the fixed app, guild, Bot member, roles, and approved channels. */
final class BotValidationCheck
{
    public function __construct(private readonly DiscordRestClient $client) {}

    public function run(): array
    {
        $checks = ['bot_identity' => false, 'application_identity' => false, 'guild_membership' => false,
            'guide_permissions' => false, 'records_permissions' => false, 'voice_permissions' => false,
            'restricted_permissions' => false];
        $data = [];
        foreach (['self_bot', 'self_application', 'guild', 'bot_member', 'guild_roles'] as $operation) {
            $response = $this->client->botRead($operation);
            if (!$response->ok) { return ['ok' => false, 'code' => 'bot_check_' . $operation . '_failed', 'checks' => $checks]; }
            $data[$operation] = $response->data;
        }
        $bot = $data['self_bot']['id'] ?? null;
        $checks['bot_identity'] = $bot === PrivateConfig::APPLICATION_ID && ($data['self_bot']['bot'] ?? false) === true;
        $checks['application_identity'] = ($data['self_application']['id'] ?? null) === PrivateConfig::APPLICATION_ID && ($data['self_application']['bot']['id'] ?? null) === $bot;
        $checks['guild_membership'] = ($data['guild']['id'] ?? null) === PrivateConfig::GUILD_ID && ($data['bot_member']['user']['id'] ?? null) === $bot && ($data['bot_member']['pending'] ?? true) === false;
        if (!$checks['bot_identity'] || !$checks['application_identity'] || !$checks['guild_membership']) {
            return ['ok' => false, 'code' => 'bot_check_identity_failed', 'checks' => $checks];
        }
        $base = $this->basePermissions($data['guild_roles'], $data['bot_member']['roles'] ?? []);
        if ($base === null) { return ['ok' => false, 'code' => 'bot_check_permissions_unknown', 'checks' => $checks]; }
        // This validation does not need moderation, channel/role management, or moves.
        $forbidden = 2 | 4 | 8 | 16 | 131072 | 16777216 | 268435456 | 1099511627776;
        $checks['restricted_permissions'] = ($base & $forbidden) === 0 && ($data['guild']['owner_id'] ?? null) !== $bot;
        foreach ([
            [PrivateConfig::GUIDE_ID, 'guide_permissions', 0, 1024 | 2048 | 32768],
            [PrivateConfig::RECORDS_ID, 'records_permissions', 0, 1024 | 2048 | 32768],
            [PrivateConfig::VOICE_ID, 'voice_permissions', 2, 1024 | 2048 | 1048576],
        ] as [$id, $key, $type, $required]) {
            $response = $this->client->botRead('channel', $id);
            if (!$response->ok) { return ['ok' => false, 'code' => 'bot_check_channel_failed', 'checks' => $checks]; }
            $channel = $response->data;
            $permissions = $this->effectivePermissions($base, $data['bot_member']['roles'] ?? [], $bot, $channel['permission_overwrites'] ?? []);
            $checks[$key] = ($channel['id'] ?? null) === $id && ($channel['guild_id'] ?? null) === PrivateConfig::GUILD_ID && ($channel['type'] ?? null) === $type &&
                $permissions !== null && ($permissions & $required) === $required;
            $checks['restricted_permissions'] = $checks['restricted_permissions'] && $permissions !== null && ($permissions & $forbidden) === 0;
        }
        $ok = !in_array(false, $checks, true);
        return ['ok' => $ok, 'code' => $ok ? 'bot_check_passed' : 'bot_check_permissions_failed', 'checks' => $checks];
    }

    private function basePermissions(array $roles, array $memberRoles): ?int
    {
        $requiredRoles = array_fill_keys([PrivateConfig::GUILD_ID, ...$memberRoles], false);
        $permissions = 0;
        foreach ($roles as $role) {
            if (!array_key_exists($role['id'] ?? '', $requiredRoles)) { continue; }
            $value = $this->permissionValue($role['permissions'] ?? null);
            if ($value === null) { return null; }
            $permissions |= $value;
            $requiredRoles[$role['id']] = true;
        }
        return in_array(false, $requiredRoles, true) ? null : $permissions;
    }

    private function effectivePermissions(int $base, array $roles, string $bot, array $overwrites): ?int
    {
        if (($base & 8) === 8) { return PHP_INT_MAX; }
        $everyone = null; $member = null; $roleAllow = 0; $roleDeny = 0;
        foreach ($overwrites as $overwrite) {
            $allow = $this->permissionValue($overwrite['allow'] ?? null); $deny = $this->permissionValue($overwrite['deny'] ?? null);
            if ($allow === null || $deny === null) { return null; }
            if (($overwrite['type'] ?? null) === 0 && ($overwrite['id'] ?? null) === PrivateConfig::GUILD_ID) { $everyone = [$allow, $deny]; }
            elseif (($overwrite['type'] ?? null) === 0 && in_array($overwrite['id'] ?? null, $roles, true)) { $roleAllow |= $allow; $roleDeny |= $deny; }
            elseif (($overwrite['type'] ?? null) === 1 && ($overwrite['id'] ?? null) === $bot) { $member = [$allow, $deny]; }
        }
        $permissions = $everyone === null ? $base : (($base & ~$everyone[1]) | $everyone[0]);
        $permissions = ($permissions & ~$roleDeny) | $roleAllow;
        return $member === null ? $permissions : (($permissions & ~$member[1]) | $member[0]);
    }

    private function permissionValue(mixed $value): ?int
    {
        if (!is_string($value) || !preg_match('/\A[0-9]{1,19}\z/D', $value) || strlen($value) === 19 && strcmp($value, (string) PHP_INT_MAX) > 0) { return null; }
        return (int) $value;
    }
}
