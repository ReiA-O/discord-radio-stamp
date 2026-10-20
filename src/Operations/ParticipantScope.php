<?php
declare(strict_types=1);
namespace RadioStamp\Operations;

use RadioStamp\Http\PrivateConfig;

/** Deployment scope is independent of schedule and database location. Missing scope keeps legacy allowlists. */
final class ParticipantScope
{
    public static function mode(array $config): string { $mode=$config['participant_scope']??'allowlist';return is_string($mode)?$mode:'invalid'; }
    public static function valid(array $config): bool
    {
        $mode=self::mode($config);$ids=$config['participant_user_ids']??[];
        return in_array($mode,['guild','allowlist'],true)&&is_array($ids)&&array_is_list($ids)
            &&count(array_unique($ids))===count($ids)&&count(array_filter($ids,PrivateConfig::snowflake(...)))===count($ids)
            &&($mode==='guild'||(count($ids)>=1&&count($ids)<=20));
    }
    public static function allows(array $config,string $id): bool
    {
        return PrivateConfig::snowflake($id)&&(self::mode($config)==='guild'||in_array($id,$config['participant_user_ids']??[],true));
    }
    public static function allowlist(array $config): ?array { return self::mode($config)==='guild'?null:$config['participant_user_ids']; }
}
