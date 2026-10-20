<?php
declare(strict_types=1);
namespace RadioStamp\Discord;
interface VoiceRosterSource
{
    public function start():bool;
    public function pump():bool;
    public function voiceState(string $user):VoiceState;
    public function roster():VoiceRoster;
    public function matches(MemberActionFence $fence):bool;
    public function close():void;
}
