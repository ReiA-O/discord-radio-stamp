<?php
declare(strict_types=1);
namespace RadioStamp\Operations;

use RadioStamp\Discord\{DiscordRestClient,ServerOperationsClient};
use RadioStamp\Http\PrivateConfig;

/** Separate private small-group scope. Existing owner-only config cannot opt into this by accident. */
final class OperationsConfig
{
    /** Fictional permissionless role IDs for this sanitized offline snapshot. */
    public const TITLE_ROLE_IDS=[
        1=>'800000000000001010',40=>'800000000000001011',80=>'800000000000001012',120=>'800000000000001013',
        160=>'800000000000001014',200=>'800000000000001015',240=>'800000000000001016',280=>'800000000000001017',
        320=>'800000000000001018',365=>'800000000000001019',
    ];
    public static function ready(array $config):bool
    {
        if(isset($config['owner_user_id'])&&!PrivateConfig::snowflake($config['owner_user_id'])){return false;}
        try { \RadioStamp\Core\Schedule::fromConfig($config); } catch (\Throwable) { return false; }
        // Retired trial configuration must not silently run through a new schedule.
        if (($config['owner_trial_enabled']??false)!==false||isset($config['owner_trial_window'])) { return false; }
        if(($config['mode']??null)!=='operations'||($config['operations_enabled']??false)!==true
            ||($config['participant_commands_enabled']??null)!==true
            ||($config['application_id']??null)!==DiscordRestClient::APPLICATION_ID||($config['guild_id']??null)!==DiscordRestClient::GUILD_ID
            ||($config['voice_channel_id']??null)!==DiscordRestClient::VOICE_CHANNEL_ID||($config['text_channel_id']??null)!==DiscordRestClient::TEXT_CHANNEL_ID
            ||($config['records_channel_id']??null)!==DiscordRestClient::RECORDS_CHANNEL_ID||($config['destination_channel_id']??null)!==ServerOperationsClient::DESTINATION_ID
            ||($config['timezone']??null)!=='Asia/Tokyo'||!ParticipantScope::valid($config)) { return false; }
        foreach(['posts_enabled','roles_enabled','room_permissions_enabled','member_actions_enabled','accept_voice_race_risk','periodic_worker_enabled','achievement_notifications_enabled','command_registration_enabled']as$key){if(!is_bool($config[$key]??null)){return false;}}
        if(!$config['posts_enabled']||!$config['room_permissions_enabled']){return false;}
        $policy=$config['closure_policy']??null;
        if(!in_array($policy,['manual','best_effort'],true)||($policy==='manual'&&($config['member_actions_enabled']||$config['accept_voice_race_risk']))
            ||($policy==='best_effort'&&(!$config['member_actions_enabled']||!$config['accept_voice_race_risk']))){return false;}
        $roles=$config['role_ids']??null;
        if(!is_array($roles)||count($roles)>10||count(array_unique($roles))!==count($roles)||($config['roles_enabled']&&count($roles)!==10)||(!$config['roles_enabled']&&$roles!==[])
            ||($config['achievement_notifications_enabled']&&!$config['roles_enabled'])){return false;}
        foreach($roles as$threshold=>$id){if(!isset(\RadioStamp\Core\TitlePolicy::TITLES[$threshold])||!PrivateConfig::snowflake($id)){return false;}}
        if($config['roles_enabled']&&array_diff_assoc($roles,self::TITLE_ROLE_IDS)!==[]){return false;}
        $baseline=$config['room_baseline']??null;
        if(!is_array($baseline)){return false;}
        foreach(['allow','deny']as$key){if(!is_string($baseline[$key]??null)||preg_match('/\A(?:0|[1-9][0-9]{0,18})\z/D',$baseline[$key])!==1||strlen($baseline[$key])===19&&strcmp($baseline[$key],(string)PHP_INT_MAX)>0){return false;}}
        return is_string($config['bot_token']??null)&&preg_match('/\A[A-Za-z0-9._-]{20,256}\z/D',$config['bot_token'])===1
            &&is_string($config['public_key_hex']??null)&&preg_match('/\A[0-9a-fA-F]{64}\z/D',$config['public_key_hex'])===1
            &&is_string($config['token_encryption_key_hex']??null)&&preg_match('/\A[0-9a-fA-F]{64}\z/D',$config['token_encryption_key_hex'])===1
            &&is_string($config['private_db_path']??null)&&$config['private_db_path']!==''
            &&is_array($config['font_candidates']??null);
    }
    public static function load(string $path,string $publicRoot,bool $operational=true):array
    {
        $config=PrivateConfig::load($path,$publicRoot,false,$operational?self::ready(...):self::storageReady(...));
        if(!is_string($config['private_root']??null)||realpath($config['private_root'])===false){throw new \RuntimeException('operations_storage_unavailable');}
        PrivateConfig::requirePrivatePath(rtrim($config['private_root'],'/\\').'/storage-check.sqlite',$publicRoot);
        // Internal provenance is always overwritten by the trusted loader, never selected by HTTP input.
        $config['_validated_public_root']=realpath($publicRoot);
        return $config;
    }
    public static function storageReady(array $config):bool{return ($config['mode']??null)==='operations'&&is_string($config['private_db_path']??null)&&is_string($config['private_root']??null);}
    public static function controllerConfig(array $config):array
    {
        if(!self::ready($config)){throw new \InvalidArgumentException('operations_configuration_unavailable');}
        return array_replace($config,['mode'=>'mock','admin_validation_enabled'=>true,
            'operations_participant_scope'=>ParticipantScope::mode($config),
            'admin_user_ids'=>ParticipantScope::allowlist($config)??[PrivateConfig::OWNER_ID]]);
    }
}
