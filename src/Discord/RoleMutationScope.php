<?php
declare(strict_types=1);
namespace RadioStamp\Discord;

/** Explicit capability for per-role PUT/DELETE; never enables arbitrary DELETE. */
final readonly class RoleMutationScope
{
    public function __construct(private array $roleIds)
    {
        if (!$roleIds || count($roleIds)>10 || count(array_unique($roleIds))!==count($roleIds)) { throw new \InvalidArgumentException('role_scope_invalid'); }
        foreach($roleIds as $id){if(!is_string($id)||preg_match('/\A[1-9][0-9]{16,19}\z/D',$id)!==1){throw new \InvalidArgumentException('role_scope_invalid');}}
    }
    public function permits(string $verb,string $path):bool
    {
        return in_array($verb,['PUT','DELETE'],true)
            && preg_match('~\A/api/v10/guilds/'.DiscordRestClient::GUILD_ID.'/members/[1-9][0-9]{16,19}/roles/([1-9][0-9]{16,19})\z~D',$path,$m)===1
            && in_array($m[1],$this->roleIds,true);
    }
}
