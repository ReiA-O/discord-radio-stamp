<?php
declare(strict_types=1);
namespace RadioStamp\Discord;
/** Only the target VC's @everyone CONNECT overwrite, never another channel/role. */
final readonly class RoomMutationScope
{
    public function permits(string $method,string $path):bool
    {
        return $method==='PUT'&&$path==='/api/v10/channels/'.DiscordRestClient::VOICE_CHANNEL_ID.'/permissions/'.DiscordRestClient::GUILD_ID;
    }
}
