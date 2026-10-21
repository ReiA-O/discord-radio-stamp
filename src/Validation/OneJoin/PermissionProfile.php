<?php
declare(strict_types=1);
namespace RadioStamp\Validation\OneJoin;

use RadioStamp\Core\Clock;
use RadioStamp\Http\PrivateConfig as Scope;
use RadioStamp\Operations\OperationsConfig;

/** Recognizes existing approved capabilities; never grants or exercises them. */
final class PermissionProfile
{
    public const COMMON_ROLE_ID = '900000000000088901';
    public const GRANT_ROLE_ID = '900000000000088902';
    public const MANAGE_ROLES = 268435456;
    public const MOVE_MEMBERS = 16777216;
    private const FORBIDDEN = 2 | 4 | 8 | 16 | 131072 | self::MOVE_MEMBERS | self::MANAGE_ROLES | 1099511627776;

    private function __construct(private readonly Store $store, private readonly string $operationId, private readonly Clock $clock) {}

    public static function forOperation(Store $store, string $operationId, Clock $clock): ?self
    {
        $profile = new self($store, $operationId, $clock);
        return $profile->current() ? $profile : null;
    }

    public function __debugInfo(): array { return ['scope' => Trial::NAME, 'authority' => 'read-only-permission-recognition']; }

    /** The exception is available only inside this acknowledged owner check. */
    public function current(): bool
    {
        try {
            $activation = $this->store->activation();
            if (!Trial::active($activation, $this->clock)) { return false; }
            $state = $this->store->state(); $operation = $this->store->operation($this->operationId);
            if (($state['run'] ?? null) !== Trial::NAME || ($state['pending'] ?? null) !== $this->operationId || ($state['phase'] ?? null) !== 'processing' ||
                ($state['starts_at'] ?? null) !== $activation['starts_at'] || ($state['expires_at'] ?? null) !== $activation['expires_at'] ||
                ($state['overall_deadline_at'] ?? null) !== Window::overallDeadline($activation) ||
                ($state['activation_hash'] ?? null) !== hash('sha256', json_encode($activation, JSON_THROW_ON_ERROR)) ||
                $operation === null || $operation['step'] !== 'check' || $operation['status'] !== 'running' || (int)$operation['ack'] !== 1) { return false; }
            // The endpoint checked the signed fixed app/guild/channel before admitting
            // this row. Recheck its immutable owner/action binding, without token reads.
            $statement = $this->store->db->pdo()->prepare('SELECT user_id,action,target,scope_mode,state,lease_id,attempts,created_at FROM interaction_jobs WHERE interaction_id=?');
            $statement->execute([$this->operationId]); $job = $statement->fetch(\PDO::FETCH_ASSOC);
            return $job !== false && $job['user_id'] === Scope::OWNER_ID && $job['action'] === 'check' &&
                $job['target'] === 'fixed_scope' && $job['scope_mode'] === 'admin_validation' && $job['state'] === 'processing' &&
                is_string($job['lease_id']) && preg_match('/\A[0-9a-f]{32}\z/D', $job['lease_id']) === 1 && (int)$job['attempts'] === 1 &&
                (int)$job['created_at'] >= $activation['starts_at'] && (int)$job['created_at'] < $activation['expires_at'];
        } catch (\Throwable) { return false; }
    }

    /** Exact approved bot roles and their complete lower-role boundary. */
    public function baseAllowed(array $roles, array $memberRoleIds, int $permissions): bool
    {
        if (!$this->current() || !array_is_list($roles) || !array_is_list($memberRoleIds) || count($memberRoleIds) !== 2 || count(array_unique($memberRoleIds)) !== 2) { return false; }
        $members = $memberRoleIds; sort($members, SORT_STRING); $expected = [self::COMMON_ROLE_ID, self::GRANT_ROLE_ID]; sort($expected, SORT_STRING);
        if ($members !== $expected || ($permissions & (self::FORBIDDEN & ~self::MANAGE_ROLES)) !== 0 || ($permissions & self::MANAGE_ROLES) === 0) { return false; }
        $map = [];
        foreach ($roles as $role) {
            if (!is_array($role) || !Scope::snowflake($role['id'] ?? null) || isset($map[$role['id']]) || self::permissionValue($role['permissions'] ?? null) === null ||
                !is_int($role['position'] ?? null) || $role['position'] < 0 || !is_bool($role['managed'] ?? null)) { return false; }
            $map[$role['id']] = $role;
        }
        $common = $map[self::COMMON_ROLE_ID] ?? null; $grant = $map[self::GRANT_ROLE_ID] ?? null; $everyone = $map[Scope::GUILD_ID] ?? null;
        if ($common === null || $grant === null || $everyone === null || $common['permissions'] !== '0' || $grant['permissions'] !== (string)self::MANAGE_ROLES ||
            $common['managed'] || $grant['managed'] || $everyone['managed'] || $everyone['position'] !== 0 ||
            (self::permissionValue($everyone['permissions']) & self::FORBIDDEN) !== 0 || $grant['position'] <= 0 || $common['position'] <= $grant['position']) { return false; }
        $allowedBelow = [Scope::GUILD_ID, self::GRANT_ROLE_ID, ...array_values(OperationsConfig::TITLE_ROLE_IDS)];
        foreach (OperationsConfig::TITLE_ROLE_IDS as $id) {
            $title = $map[$id] ?? null;
            if ($title === null || $title['permissions'] !== '0' || $title['managed'] || $title['position'] <= 0 || $title['position'] >= $grant['position']) { return false; }
        }
        foreach ($map as $id => $role) {
            // No unknown ordinary OR managed role may sit below the bot's highest.
            if ($role['position'] < $common['position'] && !in_array((string)$id, $allowedBelow, true)) { return false; }
            if ((string)$id !== self::COMMON_ROLE_ID && $role['position'] === $common['position']) { return false; }
        }
        return true;
    }

    /** Move Members is tolerated only on the fixed VC's bot-member overwrite. */
    public function channelAllowed(array $channel, int $permissions): bool
    {
        if (!$this->current() || ($channel['guild_id'] ?? null) !== Scope::GUILD_ID ||
            !in_array($channel['id'] ?? null, [Scope::GUIDE_ID, Scope::RECORDS_ID, Scope::VOICE_ID], true) || !is_array($channel['permission_overwrites'] ?? null)) { return false; }
        $voice = $channel['id'] === Scope::VOICE_ID;
        if (($channel['type'] ?? null) !== ($voice ? 2 : 0)) { return false; }
        $seen = []; $everyone = null; $botMove = false;
        foreach ($channel['permission_overwrites'] as $entry) {
            if (!is_array($entry) || !in_array($entry['type'] ?? null, [0, 1], true) || !Scope::snowflake($entry['id'] ?? null)) { return false; }
            $key = $entry['type'] . ':' . $entry['id']; if (isset($seen[$key])) { return false; } $seen[$key] = true;
            $allow = self::permissionValue($entry['allow'] ?? null); $deny = self::permissionValue($entry['deny'] ?? null);
            if ($allow === null || $deny === null || ($allow & $deny) !== 0) { return false; }
            if (!(($entry['type'] === 0 && $entry['id'] === Scope::GUILD_ID) ||
                ($entry['type'] === 1 && in_array($entry['id'], [Scope::APPLICATION_ID, Scope::OWNER_ID], true)))) { return false; }
            $permitted = $voice && $entry['type'] === 1 && $entry['id'] === Scope::APPLICATION_ID ? self::MOVE_MEMBERS : 0;
            if (($allow & (self::FORBIDDEN & ~$permitted)) !== 0) { return false; }
            if (($allow & self::MOVE_MEMBERS) !== 0) { $botMove = true; }
            if ($entry['type'] === 0) { $everyone = [$allow, $deny]; }
        }
        // Keep the existing channel private. Only the acknowledged owner can act;
        // administrators' inherent access is not changed by this trial.
        if ($everyone === null || ($everyone[1] & 1024) === 0 || ($everyone[0] & 1024) !== 0 ||
            ($voice && (($everyone[1] & 1048576) === 0 || ($everyone[0] & 1048576) !== 0))) { return false; }
        $except = self::MANAGE_ROLES | ($voice && $botMove ? self::MOVE_MEMBERS : 0);
        return ($permissions & (self::FORBIDDEN & ~$except)) === 0;
    }

    private static function permissionValue(mixed $value): ?int
    {
        return is_string($value) && preg_match('/\A(?:0|[1-9][0-9]{0,18})\z/D', $value) === 1 &&
            (strlen($value) < 19 || strcmp($value, (string)PHP_INT_MAX) <= 0) ? (int)$value : null;
    }
}
