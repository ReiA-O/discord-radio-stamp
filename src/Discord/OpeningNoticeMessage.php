<?php
declare(strict_types=1);
namespace RadioStamp\Discord;

use RadioStamp\Core\StampPolicy;

/** No supplied names, URLs or text may enter the one @here-capable message. */
final class OpeningNoticeMessage
{
    public static function key(string $sessionId):string { return 'opening-notice:'.$sessionId; }

    public static function snapshot(array $session):array
    {
        $id=$session['id']??null;$at=$session['scheduled_at']??null;$slot=$session['slot_id']??null;
        if(!is_string($id)||!is_int($at)||$at<=0||!is_string($slot)||!isset(StampPolicy::SLOT_STAMPS[$slot])
            ||($session['stamp']??null)!==StampPolicy::SLOT_STAMPS[$slot]){throw new \InvalidArgumentException('opening_notice_scope_invalid');}
        $scheduled=(new \DateTimeImmutable('@'.$at))->setTimezone(new \DateTimeZone('Asia/Tokyo'));
        if($scheduled->format('Y-m-d_Hi')!==$id||$scheduled->format('s')!=='00'){throw new \InvalidArgumentException('opening_notice_scope_invalid');}
        $here=in_array($slot,['morning','midday','night'],true);
        $time=$scheduled->format('G:i');
        $intro=match($slot){
            'morning'=>'🌅 @here おはよう！'.$time.'からラジオ体操が始まるよ！一緒に体を起こそう🙌',
            'midday'=>'☀️ @here '.$time.'からラジオ体操が始まるよ！お昼の気分転換に、みんなでひと伸びしよう🙆',
            'night'=>'🌙 @here 今日もおつかれさま！'.$time.'からラジオ体操が始まるよ！一日の締めに体をほぐそう✨',
            'midnight'=>'🌃 夜更かし組のみんな、こんばんは！'.$time.'からラジオ体操が始まるよ！作業の合間にひと伸びしよう🦉',
            'dawn'=>'🌄 早起きさんも夜更かしさんも！'.$time.'からラジオ体操が始まるよ！一緒に体を動かそう🐓',
        };
        $content=$intro."\n開催：".$scheduled->format('Y-m-d H:i')." JST\n🚪 VCは開いているよ！参加はこちら → https://discord.com/channels/".DiscordRestClient::GUILD_ID.'/'.DiscordRestClient::VOICE_CHANNEL_ID;
        return ['session_id'=>$id,'scheduled_at'=>$at,'slot_id'=>$slot,'stamp'=>StampPolicy::SLOT_STAMPS[$slot],
            'channel_id'=>DiscordRestClient::TEXT_CHANNEL_ID,
            'payload'=>['content'=>$content,'components'=>[],'allowed_mentions'=>['parse'=>$here?['everyone']:[],'users'=>[],'roles'=>[],'replied_user'=>false],'tts'=>false]];
    }
}
