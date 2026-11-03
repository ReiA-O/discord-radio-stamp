<?php
declare(strict_types=1);
require_once __DIR__.'/artifact_directory.php';
require dirname(__DIR__).'/bootstrap.php';
require __DIR__.'/fixtures/OperationsRuntimeFixture.php';

use RadioStamp\Core\FrozenClock;
use RadioStamp\Discord\{DiscordRestClient,Gateway,HttpAdapter,HttpRequest,HttpResponse,OpeningNoticeMessage};
use RadioStamp\Operations\{OperationsConfig,OperationsRuntime};

$aoChecks=0;
function aoSame(mixed $expected,mixed $actual,string $why):void
{
    global $aoChecks;++$aoChecks;
    if($expected!==$actual){throw new RuntimeException('Always open: '.$why.' expected='.json_encode($expected).' actual='.json_encode($actual));}
}
function aoCheck(bool $actual,string $why):void{aoSame(true,$actual,$why);}
function aoStamp(string $at):int{return (new DateTimeImmutable($at,new DateTimeZone('Asia/Tokyo')))->getTimestamp();}

/** Every route is handled by this wrapper or the existing fictional runtime adapter. */
final class AlwaysOpenFixtureHttp implements HttpAdapter
{
    public RuntimeFixtureHttp $inner;
    public array $requests=[],$noticeOutcomes=[],$guideOutcomes=[],$receiptOutcomes=[],$memberOutcomes=[];
    public ?HttpResponse $roomResponse=null;
    public ?Closure $requestHook=null;
    public function __construct(){$this->inner=new RuntimeFixtureHttp();$this->inner->allow='1049600';$this->inner->deny='0';}
    public static function roomRead(HttpRequest $request):bool
    {
        return $request->method()==='GET'&&parse_url($request->url(),PHP_URL_PATH)==='/api/v10/channels/'.DiscordRestClient::VOICE_CHANNEL_ID;
    }
    public static function notice(HttpRequest $request):bool
    {
        return $request->method()==='POST'&&parse_url($request->url(),PHP_URL_PATH)==='/api/v10/channels/'.DiscordRestClient::OPENING_NOTICE_CHANNEL_ID.'/messages';
    }
    public static function guide(HttpRequest $request):bool
    {
        if($request->method()!=='POST'||parse_url($request->url(),PHP_URL_PATH)!=='/api/v10/channels/'.DiscordRestClient::VOICE_CHANNEL_ID.'/messages'){return false;}
        $payload=json_decode($request->body(),true);
        return ($payload['components']??[])===[];
    }
    public static function receipt(HttpRequest $request):bool
    {
        if($request->method()!=='POST'||parse_url($request->url(),PHP_URL_PATH)!=='/api/v10/channels/'.DiscordRestClient::VOICE_CHANNEL_ID.'/messages'){return false;}
        $payload=json_decode($request->body(),true);
        return ($payload['components']??[])!==[];
    }
    public function exchange(HttpRequest $request):HttpResponse
    {
        $this->requests[]=$request;
        if($this->requestHook!==null){($this->requestHook)($request);}
        if(self::roomRead($request)&&$this->roomResponse!==null){return $this->roomResponse;}
        if(self::notice($request)&&$this->noticeOutcomes){$outcome=array_shift($this->noticeOutcomes);if($outcome instanceof Throwable){throw $outcome;}return $outcome;}
        if(self::guide($request)&&$this->guideOutcomes){$outcome=array_shift($this->guideOutcomes);if($outcome instanceof Throwable){throw $outcome;}return $outcome;}
        if(self::receipt($request)&&$this->receiptOutcomes){$outcome=array_shift($this->receiptOutcomes);if($outcome instanceof Throwable){throw $outcome;}return $outcome;}
        if($request->method()==='PATCH'&&preg_match('~/guilds/[0-9]+/members/[0-9]+$~',$request->url())===1&&$this->memberOutcomes){
            $outcome=array_shift($this->memberOutcomes);if($outcome instanceof Throwable){throw $outcome;}if($outcome instanceof HttpResponse){return $outcome;}
        }
        $response=$this->inner->exchange($request);
        if(self::roomRead($request)){
            $data=json_decode($response->body,true,32,JSON_THROW_ON_ERROR)+['guild_id'=>DiscordRestClient::GUILD_ID,'type'=>2];
            return new HttpResponse($response->status,$response->headers,json_encode($data,JSON_THROW_ON_ERROR));
        }
        return $response;
    }
    public function notices():array{return array_values(array_filter($this->requests,self::notice(...)));}
    public function permissionWrites():array{return array_values(array_filter($this->requests,static fn(HttpRequest $r):bool=>$r->method()==='PUT'&&str_contains($r->url(),'/permissions/')));}
    public function memberPatches():array{return array_values(array_filter($this->requests,static fn(HttpRequest $r):bool=>$r->method()==='PATCH'&&preg_match('~/guilds/[0-9]+/members/[0-9]+$~',$r->url())===1));}
}

$aoRoot=sys_get_temp_dir().'/radio-always-open-'.bin2hex(random_bytes(8));mkdir($aoRoot,0700);
$aoFixtures=[];
function aoFixture(string $scheduled='2026-10-22 07:00',string $slot='morning',string $policy='manual',?int $now=null):array
{
    global $aoRoot,$aoFixtures;
    $root=$aoRoot.'/case-'.count($aoFixtures);mkdir($root,0700);mkdir($root.'/private',0700);mkdir($root.'/public',0700);
    $at=aoStamp($scheduled);$clock=new FrozenClock('@'.($now??$at-1800));$http=new AlwaysOpenFixtureHttp();
    $config=runtimeFixtureConfig($root,$policy);$config['room_access_mode']='always_open';$config['room_permissions_enabled']=false;$config['room_baseline']=null;
    $config['schedule']=['daily_times'=>[],'one_off_times'=>[['at'=>$scheduled,'slot_id'=>$slot]]];$config['weekly_ranking_enabled']=false;
    $runtime=new OperationsRuntime($http,fn()=>$http->inner->transport(),$clock);$context=$runtime->build($config,static fn():bool=>true);
    $id=(new DateTimeImmutable('@'.$at))->setTimezone(new DateTimeZone('Asia/Tokyo'))->format('Y-m-d_Hi');
    $fixture=compact('root','at','clock','http','config','runtime','context','id');$aoFixtures[]=$fixture;return $fixture;
}
function aoCycle(array $f):array{return $f['runtime']->cycle($f['context'],static fn():bool=>true);}
function aoDue(array $f,int $offset=0):void{$f['clock']->set('@'.($f['at']-600+$offset));aoCycle($f);}
function aoEffect(array $f):array
{
    $q=$f['context']->db->pdo()->prepare('SELECT * FROM core_effects WHERE effect_key=?');$q->execute([OpeningNoticeMessage::key($f['id'])]);$row=$q->fetch();$q->closeCursor();return $row?:[];
}
function aoSession(array $f):array
{
    $q=$f['context']->db->pdo()->prepare('SELECT * FROM sessions WHERE id=?');$q->execute([$f['id']]);$row=$q->fetch();$q->closeCursor();return $row?:[];
}
function aoNoRoomEffects(array $f,string $why):void
{
    aoSame(0,count($f['http']->permissionWrites()),$why.' sends no permission PUT');
    aoSame(0,(int)$f['context']->db->pdo()->query("SELECT COUNT(*) FROM core_effects WHERE kind IN ('room_open','room_close')")->fetchColumn(),$why.' creates no room mutation effects');
    aoCheck(!in_array(4,array_column($f['http']->inner->gateway?->sent??[],'op'),true),$why.' never sends Bot voice join opcode');
}
function aoClaim(array $f):array
{
    $q=$f['context']->db->pdo()->prepare("UPDATE core_effects SET status='pending',cancelled_at=NULL,lease_owner=?,lease_until=0,attempts=1,last_attempt_at=? WHERE effect_key=?");
    $q->execute([str_repeat('c',32),$f['clock']->now()->getTimestamp(),OpeningNoticeMessage::key($f['id'])]);
    return json_decode(aoEffect($f)['payload_json'],true,32,JSON_THROW_ON_ERROR);
}

$aoFailure=null;
try{(static function():void{
    $config=aoFixture()['config'];
    aoSame(true,OperationsConfig::ready($config),'always-open manual configuration accepts null baseline');
    $withoutBaseline=$config;unset($withoutBaseline['room_baseline']);aoSame(true,OperationsConfig::ready($withoutBaseline),'always-open baseline may be omitted');
    foreach(['invalid_mode','enabled_mutations','time_gated_without_mutations','non_string_mode']as$case){
        $bad=$config;
        if($case==='invalid_mode'){$bad['room_access_mode']='automatic';}
        elseif($case==='enabled_mutations'){$bad['room_permissions_enabled']=true;}
        elseif($case==='time_gated_without_mutations'){$bad['room_access_mode']='time_gated';$bad['room_baseline']=['allow'=>'1024','deny'=>'0'];}
        else{$bad['room_access_mode']=false;}
        aoSame(false,OperationsConfig::ready($bad),'configuration rejects '.$case);
    }
    $legacy=$config;unset($legacy['room_access_mode']);$legacy['room_permissions_enabled']=true;$legacy['room_baseline']=['allow'=>'1024','deny'=>'0'];
    aoSame(true,OperationsConfig::ready($legacy),'omitted mode preserves valid time-gated configuration');

    // Five logical slots retain the existing timing with no recruitment mentions.
    foreach(['midnight'=>'00:00','dawn'=>'04:00','morning'=>'07:00','midday'=>'12:30','night'=>'22:00']as$slot=>$time){
        $f=aoFixture('2026-10-22 '.$time,$slot);aoCycle($f);
        aoSame('open',aoSession($f)['status'],$slot.' local session opens at S-30');aoSame(0,count($f['http']->notices()),$slot.' no early invitation');
        aoSame(1,count(array_filter($f['http']->requests,AlwaysOpenFixtureHttp::guide(...))),$slot.' VC guide remains');
        $guide=array_values(array_filter($f['http']->requests,AlwaysOpenFixtureHttp::guide(...)))[0];$guidePayload=json_decode($guide->body(),true,32,JSON_THROW_ON_ERROR);
        aoCheck(str_starts_with($guidePayload['content'],'この回の体操案内です。'),$slot.' always-open guide identifies the session without claiming a room mutation');
        $f['clock']->set('@'.($f['at']-601));aoCycle($f);aoSame(0,count($f['http']->notices()),$slot.' S-10 minus one second remains silent');
        aoDue($f);aoSame(1,count($f['http']->notices()),$slot.' S-10 invitation succeeds without room-open effect');
        $request=$f['http']->notices()[0];$payload=json_decode($request->body(),true,32,JSON_THROW_ON_ERROR);
        aoSame(Gateway::ALLOWED_MENTIONS,$payload['allowed_mentions'],$slot.' recruitment disables every mention class');
        aoSame(0,substr_count($payload['content'],'@here'),$slot.' recruitment has no @here text');
        aoSame([],$payload['allowed_mentions']['users'],$slot.' no user pings');aoSame([],$payload['allowed_mentions']['roles'],$slot.' no role pings');aoSame(false,$payload['allowed_mentions']['replied_user'],$slot.' no reply ping');
        aoCheck(!str_contains($payload['content'],'@everyone')&&!str_contains($payload['content'],'<@'),$slot.' no broader mention text');
        aoCheck(str_contains($payload['content'],'2026-10-22 '.$time.' JST'),$slot.' exact scheduled JST time');
        aoCheck(str_contains($payload['content'],'https://discord.com/channels/'.DiscordRestClient::GUILD_ID.'/'.DiscordRestClient::VOICE_CHANNEL_ID),$slot.' direct VC link');
        aoSame([], $payload['components'],$slot.' invitation adds no buttons');aoSame(false,$payload['tts'],$slot.' no TTS');
        $noticeIndex=array_search($request,$f['http']->requests,true);$before=array_slice($f['http']->requests,0,$noticeIndex);
        aoCheck(count(array_filter($before,AlwaysOpenFixtureHttp::roomRead(...)))>0,$slot.' fresh VC readback precedes invitation');
        aoCycle($f);$next=$f['runtime']->build($f['config'],static fn():bool=>true);$f['runtime']->cycle($next,static fn():bool=>true);
        aoSame(1,count($f['http']->notices()),$slot.' repeated worker/restart never duplicates invitation');aoSame('done',aoEffect($f)['status'],$slot.' successful notice is persisted');
        aoNoRoomEffects($f,$slot.' lifecycle');
    }

    // A guide write is an independent effect, so its failure cannot suppress the scheduled invitation.
    $guide=aoFixture();$guide['http']->guideOutcomes=array_fill(0,6,new HttpResponse(403,[],'{}'));aoCycle($guide);aoDue($guide);
    aoSame(1,count($guide['http']->notices()),'guide POST rejection still allows verified scheduled invitation');
    aoSame('done',aoEffect($guide)['status'],'notice finishes while guide remains pending');
    aoSame('pending',$guide['context']->db->pdo()->query("SELECT status FROM core_effects WHERE kind='guide'")->fetchColumn(),'guide failure remains independently pending');
    aoNoRoomEffects($guide,'guide failure');

    // A known-open channel needs explicit View Channel and Connect, with neither denied.
    foreach(['no_connect','no_view','deny_connect','deny_view','unknown_overwrites','wrong_guild','wrong_type','duplicate_everyone','read_403','read_timeout']as$case){
        $f=aoFixture();aoCycle($f);
        if($case==='no_connect'){$f['http']->inner->allow='1024';}
        elseif($case==='no_view'){$f['http']->inner->allow='1048576';}
        elseif($case==='deny_connect'){$f['http']->inner->deny='1048576';}
        elseif($case==='deny_view'){$f['http']->inner->deny='1024';}
        elseif($case==='unknown_overwrites'){$f['http']->roomResponse=new HttpResponse(200,[],json_encode(['id'=>DiscordRestClient::VOICE_CHANNEL_ID,'permission_overwrites'=>[]]));}
        elseif(in_array($case,['wrong_guild','wrong_type','duplicate_everyone'],true)){
            $row=['id'=>DiscordRestClient::GUILD_ID,'type'=>0,'allow'=>'1049600','deny'=>'0'];
            $data=['id'=>DiscordRestClient::VOICE_CHANNEL_ID,'guild_id'=>DiscordRestClient::GUILD_ID,'type'=>2,'permission_overwrites'=>[$row]];
            if($case==='wrong_guild'){$data['guild_id']='900000000000000999';}elseif($case==='wrong_type'){$data['type']=0;}else{$data['permission_overwrites'][]=$row;}
            $f['http']->roomResponse=new HttpResponse(200,[],json_encode($data));
        }
        elseif($case==='read_403'){$f['http']->roomResponse=new HttpResponse(403,[],'{}');}
        else{$f['http']->roomResponse=new HttpResponse(0,[],'','fictional timeout');}
        aoDue($f);aoSame(0,count($f['http']->notices()),$case.' prevents an open invitation');aoSame(0,(int)aoEffect($f)['attempts'],$case.' spends no POST attempt');aoSame(null,aoEffect($f)['lease_owner'],$case.' keeps notice unclaimed');
        $f['http']->inner->allow='1049600';$f['http']->inner->deny='0';$f['http']->roomResponse=null;$f['clock']->advance(10);aoCycle($f);
        aoSame(1,count($f['http']->notices()),$case.' recovery within window sends once');aoNoRoomEffects($f,$case);
    }
    foreach([0,1,1800]as$offset){$f=aoFixture(now:aoStamp('2026-10-22 07:00')+$offset);aoCycle($f);aoCycle($f);aoSame(0,count($f['http']->notices()),'S plus '.$offset.' never sends expired future-tense invitation');aoNoRoomEffects($f,'expired notice');}

    $old=aoFixture();aoCycle($old);$p=$old['context']->db->pdo();
    $q=$p->prepare("INSERT INTO core_effects(effect_key,kind,payload_json,status,attempts,last_attempt_at,lease_owner,lease_until,expires_at) VALUES(?,?,?,'pending',2,?,?,?,?)");
    foreach(['open-room:'=>'room_open','close-room:'=>'room_close']as$prefix=>$kind){$q->execute([$prefix.$old['id'],$kind,json_encode(['session_id'=>$old['id']]),$old['at']-1800,str_repeat($kind==='room_open'?'a':'b',32),$old['at']-1200,$old['at']+1800]);}
    $before=$p->query("SELECT * FROM core_effects WHERE kind IN ('room_open','room_close') ORDER BY effect_key")->fetchAll();aoDue($old);
    aoSame(1,count($old['http']->notices()),'old pending room mutation cannot suppress verified invitation');
    aoSame($before,$p->query("SELECT * FROM core_effects WHERE kind IN ('room_open','room_close') ORDER BY effect_key")->fetchAll(),'old room effects remain unchanged instead of fabricated as done');
    aoSame(0,count($old['http']->permissionWrites()),'old pending room effects never dispatch in always-open mode');
    $old['clock']->set('@'.$old['at']);$old['http']->inner->voices['800000000000001021']=DiscordRestClient::VOICE_CHANNEL_ID;
    aoSame('receipt_opened',$old['context']->service->openAttendance('800000000000001021','always-open-old-room-effects')['code'],'receipt may start beside old room effects');
    $old['clock']->advance(540);aoCycle($old);$old['clock']->advance(60);aoCycle($old);aoSame('closed',aoSession($old)['status'],'receipt closes independently of old room effects');
    aoSame($before,$p->query("SELECT * FROM core_effects WHERE kind IN ('room_open','room_close') ORDER BY effect_key")->fetchAll(),'closure preserves all old room status/attempt/owner/deadline fields');
    aoSame(0,count($old['http']->permissionWrites()),'closure never dispatches retained old room effects');

    $cancelled=aoFixture();aoCycle($cancelled);$p=$cancelled['context']->db->pdo();
    $p->prepare('UPDATE core_effects SET cancelled_at=? WHERE effect_key=?')->execute([$cancelled['clock']->now()->getTimestamp(),OpeningNoticeMessage::key($cancelled['id'])]);$before=aoEffect($cancelled);
    aoDue($cancelled);aoCycle($cancelled);$next=$cancelled['runtime']->build($cancelled['config'],static fn():bool=>true);$cancelled['runtime']->cycle($next,static fn():bool=>true);
    aoSame(0,count($cancelled['http']->notices()),'previously cancelled opening notice is never replayed');
    aoSame($before,aoEffect($cancelled),'cancelled opening notice snapshot/status/history remain exactly unchanged');aoNoRoomEffects($cancelled,'cancelled notice');

    $retry=aoFixture();aoCycle($retry);$retry['http']->noticeOutcomes=[new HttpResponse(403,[],'{}')];aoDue($retry);
    aoSame(1,count($retry['http']->notices()),'known rejection makes one POST');aoSame(null,aoEffect($retry)['lease_owner'],'known rejection releases claim');
    aoCycle($retry);aoSame(1,count($retry['http']->notices()),'backoff prevents immediate retry');
    $retry['clock']->set('@'.aoEffect($retry)['lease_until']);aoCycle($retry);aoCycle($retry);aoSame(2,count($retry['http']->notices()),'known rejection retries once then deduplicates');
    foreach([new HttpResponse(0,[],'','fictional timeout'),new RuntimeException('fictional notice exception')]as$unknown){
        $f=aoFixture();aoCycle($f);$f['http']->noticeOutcomes=[$unknown];aoDue($f);aoSame(1,count($f['http']->notices()),'uncertain notice is attempted once');aoCheck(aoEffect($f)['lease_owner']!==null,'uncertain notice stays held');
        $f['clock']->advance(40);$next=$f['runtime']->build($f['config'],static fn():bool=>true);$f['runtime']->cycle($next,static fn():bool=>true);aoSame(1,count($f['http']->notices()),'restart never replays uncertain invitation');
    }

    // Preserve the final readback/TTL/owner guard even without a room-open done prerequisite.
    foreach(['expiry','closing','claim_changed','payload_changed']as$change){
        $f=aoFixture();aoCycle($f);$f['http']->noticeOutcomes=[new HttpResponse(403,[],'{}')];aoDue($f);$snapshot=aoClaim($f);$before=count($f['http']->notices());$changed=false;
        $f['http']->requestHook=static function(HttpRequest $request)use($f,$change,&$changed):void{
            if($changed||!AlwaysOpenFixtureHttp::roomRead($request)){return;}
            $q=$f['context']->db->pdo()->prepare("SELECT 1 FROM operation_receipts WHERE operation_key=? AND status='sending'");$q->execute([OpeningNoticeMessage::key($f['id'])]);$claimed=$q->fetchColumn()!==false;$q->closeCursor();if(!$claimed){return;}$changed=true;
            if($change==='expiry'){$f['clock']->set('@'.$f['at']);}
            elseif($change==='closing'){$f['context']->db->pdo()->prepare("UPDATE sessions SET status='closing' WHERE id=?")->execute([$f['id']]);}
            elseif($change==='claim_changed'){$f['context']->db->pdo()->prepare('UPDATE core_effects SET lease_owner=? WHERE effect_key=?')->execute([str_repeat('f',32),OpeningNoticeMessage::key($f['id'])]);}
            else{$f['context']->db->pdo()->prepare('UPDATE core_effects SET payload_json=? WHERE effect_key=?')->execute(['{}',OpeningNoticeMessage::key($f['id'])]);}
        };
        $result=$f['context']->client->postOpeningNotice($snapshot,OpeningNoticeMessage::key($f['id']),fn():int=>$f['clock']->now()->getTimestamp());
        aoSame(true,$changed,$change.' hook runs after receipt claim');aoSame(false,$result->ok,$change.' final guard fails');aoSame($before,count($f['http']->notices()),$change.' cannot reach POST');
    }

    // The first receipt still defines nine-minute warning and ten-minute closure; room entry stays available.
    $owner='800000000000001021';$other='900000000000000102';$outside='900000000000000199';
    $f=aoFixture(policy:'best_effort');aoSame(true,OperationsConfig::ready($f['config']),'always-open best-effort configuration is valid');aoCycle($f);$f['clock']->set('@'.$f['at']);
    $f['http']->inner->voices=[$owner=>DiscordRestClient::VOICE_CHANNEL_ID,$other=>DiscordRestClient::VOICE_CHANNEL_ID,$outside=>'900000000000009999'];
    $opened=$f['context']->service->openAttendance($owner,'always-open-first');aoSame('receipt_opened',$opened['code'],'participant command opens receipt');aoSame($f['at']+600,$opened['closes_at'],'first receipt fixes ten-minute deadline');
    aoSame('attended',$f['context']->service->attend($f['id'],$owner,'always-open-attend')['code'],'participant button still records attendance');
    $f['clock']->advance(540);aoCycle($f);aoSame($f['at']+540,(int)aoSession($f)['warning_sent_at'],'nine-minute warning remains');aoSame(0,count($f['http']->inner->memberPatches()),'warning does not disconnect members');
    $warnings=array_values(array_filter($f['http']->inner->messages,static fn(array $message):bool=>str_starts_with($message['content'],'この回はあと1分で終了です。')));aoSame(1,count($warnings),'receipt warning says this session ends in one minute');
    aoCheck(str_contains($warnings[0]['content']??'','VCは常時利用できるため、再入室はできます。')&&str_contains($warnings[0]['content']??'','終了時に残っている方は参加登録の有無にかかわらず切断します。'),'receipt warning explains one-time disconnect and continued room entry');
    $f['clock']->advance(59);aoCycle($f);aoSame('started',aoSession($f)['status'],'receipt remains active until exact cutoff');
    $f['clock']->advance(1);aoCycle($f);aoSame('closed',aoSession($f)['status'],'receipt closes at ten minutes');aoSame(null,$f['http']->inner->voices[$owner],'registered occupant disconnects');aoSame(null,$f['http']->inner->voices[$other],'non-clicking occupant disconnects');
    aoSame('900000000000009999',$f['http']->inner->voices[$outside],'other VC occupant remains');aoSame(2,count($f['http']->inner->memberPatches()),'each scoped occupant disconnects once');
    foreach($f['http']->inner->memberPatches()as$request){aoSame(null,json_decode($request->body(),true)['channel_id'],'closure never moves to another VC');}
    aoSame('session_closed',$f['context']->service->attend($f['id'],$other,'always-open-too-late')['code'],'late attendance is rejected');
    $f['http']->inner->voices[$owner]=DiscordRestClient::VOICE_CHANNEL_ID;$f['http']->inner->gateway?->change($owner,DiscordRestClient::VOICE_CHANNEL_ID);
    $f['clock']->advance(20);aoCycle($f);aoCycle($f);aoSame(2,count($f['http']->inner->memberPatches()),'returning after completed closure never triggers a continuing kick');aoSame(DiscordRestClient::VOICE_CHANNEL_ID,$f['http']->inner->voices[$owner],'re-entered occupant remains after completed closure');
    $connections=$f['http']->inner->connections;$next=$f['runtime']->build($f['config'],static fn():bool=>true);$f['runtime']->cycle($next,static fn():bool=>true);
    aoSame($connections,$f['http']->inner->connections,'fresh worker never reconnects roster for closed receipt');aoSame(2,count($f['http']->memberPatches()),'fresh worker never disconnects returning occupant');
    aoSame('session_closed',$f['context']->service->openAttendance($owner,'always-open-reopen')['code'],'returning occupant cannot reopen closed receipt');
    aoSame(1,(int)$f['context']->db->pdo()->query('SELECT COUNT(*) FROM attendance')->fetchColumn(),'closure and re-entry never add attendance');
    aoSame('1049600',$f['http']->inner->allow,'View/Connect stay open after closure');aoSame('0',$f['http']->inner->deny,'closure adds no Connect deny');aoNoRoomEffects($f,'best-effort closure');

    $manual=aoFixture();aoCycle($manual);$manual['clock']->set('@'.$manual['at']);$manual['http']->inner->voices[$owner]=DiscordRestClient::VOICE_CHANNEL_ID;
    aoSame('receipt_opened',$manual['context']->service->openAttendance($owner,'always-open-manual')['code'],'manual policy receipt still opens');
    $manual['clock']->advance(540);aoCycle($manual);$manual['clock']->advance(60);aoCycle($manual);aoSame('closed',aoSession($manual)['status'],'manual policy closes only receipt state');aoSame(0,count($manual['http']->inner->memberPatches()),'manual policy never disconnects');aoNoRoomEffects($manual,'manual closure');

    // A closure pass is permanently latched before roster/PATCH work; partial failure gets manual follow-up.
    $partial=aoFixture(policy:'best_effort');aoCycle($partial);$partial['clock']->set('@'.$partial['at']);
    $partial['http']->inner->voices=[$owner=>DiscordRestClient::VOICE_CHANNEL_ID,$other=>DiscordRestClient::VOICE_CHANNEL_ID];
    aoSame('receipt_opened',$partial['context']->service->openAttendance($owner,'always-open-partial')['code'],'partial case opens receipt');
    $partial['clock']->advance(540);aoCycle($partial);$partial['clock']->advance(60);$partial['http']->memberOutcomes=[new HttpResponse(403,[],'{}'),null];$cycle=aoCycle($partial);
    aoCheck(in_array('manual_member_actions_required',$cycle['sessions'],true),'partial disconnect failure requests manual member action');
    aoSame('closed',aoSession($partial)['status'],'partial disconnect still completes local receipt closure');aoSame(2,count($partial['http']->memberPatches()),'one initial pass attempts each captured occupant once');
    aoSame(DiscordRestClient::VOICE_CHANNEL_ID,$partial['http']->inner->voices[$owner],'failed disconnect occupant remains for manual follow-up');aoSame(null,$partial['http']->inner->voices[$other],'other initial occupant may disconnect successfully');
    $latch=$partial['context']->db->pdo()->prepare('SELECT kind,status FROM core_effects WHERE effect_key=?');$latch->execute(['closure-once:'.$partial['id']]);$row=$latch->fetch();$latch->closeCursor();
    aoSame('closure_once',$row['kind']??null,'one-pass latch is persisted');aoSame('done',$row['status']??null,'completed one-pass latch cannot be inserted again');
    $partial['clock']->advance(20);aoCycle($partial);$next=$partial['runtime']->build($partial['config'],static fn():bool=>true);$partial['runtime']->cycle($next,static fn():bool=>true);
    aoSame(2,count($partial['http']->memberPatches()),'ticks and restart never retry partially failed closure');aoNoRoomEffects($partial,'partial closure');

    $arriving=aoFixture(policy:'best_effort');aoCycle($arriving);$arriving['clock']->set('@'.$arriving['at']);
    $arriving['http']->inner->voices=[$owner=>DiscordRestClient::VOICE_CHANNEL_ID,$other=>'900000000000009999'];
    aoSame('receipt_opened',$arriving['context']->service->openAttendance($owner,'always-open-arriving')['code'],'new-arrival case opens receipt');
    $arriving['clock']->advance(540);aoCycle($arriving);$arriving['clock']->advance(60);$joined=false;
    $arriving['http']->requestHook=static function(HttpRequest $request)use($arriving,$other,&$joined):void{
        if($joined||$request->method()!=='PATCH'||preg_match('~/guilds/[0-9]+/members/[0-9]+$~',$request->url())!==1){return;}$joined=true;
        $arriving['http']->inner->voices[$other]=DiscordRestClient::VOICE_CHANNEL_ID;$arriving['http']->inner->gateway?->change($other,DiscordRestClient::VOICE_CHANNEL_ID);
    };
    aoCycle($arriving);aoSame(true,$joined,'new occupant arrives during original disconnect pass');aoSame('closed',aoSession($arriving)['status'],'initial pass closes receipt without sweeping new arrivals');
    aoSame(1,count($arriving['http']->memberPatches()),'only captured initial occupant gets a PATCH');aoSame(DiscordRestClient::VOICE_CHANNEL_ID,$arriving['http']->inner->voices[$other],'new arrival remains in open room');
    $connections=$arriving['http']->inner->connections;$arriving['clock']->advance(20);$next=$arriving['runtime']->build($arriving['config'],static fn():bool=>true);$arriving['runtime']->cycle($next,static fn():bool=>true);
    aoSame(1,count($arriving['http']->memberPatches()),'fresh worker does not sweep new arrival');aoSame($connections,$arriving['http']->inner->connections,'fresh worker does not reconnect for new arrival');aoNoRoomEffects($arriving,'new arrival');

    $unknown=aoFixture(policy:'best_effort');aoCycle($unknown);$unknown['clock']->set('@'.$unknown['at']);$unknown['http']->inner->voices[$owner]=DiscordRestClient::VOICE_CHANNEL_ID;
    aoSame('receipt_opened',$unknown['context']->service->openAttendance($owner,'always-open-unknown-roster')['code'],'unknown-roster case opens receipt');
    $unknown['clock']->advance(540);aoCycle($unknown);$unknown['clock']->advance(60);$unknown['http']->inner->failConnect=true;$cycle=aoCycle($unknown);
    aoCheck(in_array('manual_member_actions_required',$cycle['sessions'],true),'unknown roster requests manual follow-up');aoSame('closed',aoSession($unknown)['status'],'unknown roster completes local receipt closure');aoSame(0,count($unknown['http']->memberPatches()),'unknown roster never guesses occupants');
    $connections=$unknown['http']->inner->connections;$unknown['http']->inner->failConnect=false;$unknown['clock']->advance(20);$next=$unknown['runtime']->build($unknown['config'],static fn():bool=>true);$unknown['runtime']->cycle($next,static fn():bool=>true);
    aoSame($connections,$unknown['http']->inner->connections,'unknown first roster never gets a fresh closure session');aoSame(0,count($unknown['http']->memberPatches()),'unknown first roster never triggers delayed disconnects');aoNoRoomEffects($unknown,'unknown roster');

    $crash=aoFixture(policy:'best_effort');aoCycle($crash);$crash['clock']->set('@'.$crash['at']);$crash['http']->inner->voices[$owner]=DiscordRestClient::VOICE_CHANNEL_ID;
    aoSame('receipt_opened',$crash['context']->service->openAttendance($owner,'always-open-crash')['code'],'crash case opens receipt');
    $crash['clock']->advance(540);aoCycle($crash);$crash['clock']->advance(60);$p=$crash['context']->db->pdo();
    $p->prepare("UPDATE sessions SET status='closing' WHERE id=?")->execute([$crash['id']]);
    $p->prepare("INSERT INTO core_effects(effect_key,kind,payload_json,status,lease_owner,lease_until,attempts,last_attempt_at) VALUES(?,'closure_once',?,'pending',?,0,1,?)")
        ->execute(['closure-once:'.$crash['id'],json_encode(['session_id'=>$crash['id']]),str_repeat('a',32),$crash['clock']->now()->getTimestamp()]);
    $next=$crash['runtime']->build($crash['config'],static fn():bool=>true);$cycle=$crash['runtime']->cycle($next,static fn():bool=>true);
    aoCheck(in_array('manual_member_actions_required',$cycle['sessions'],true),'interrupted held pass requests manual follow-up');aoSame('closed',aoSession($crash)['status'],'held pass completes receipt state on restart');
    aoSame(0,count($crash['http']->memberPatches()),'held latch forbids a new disconnect pass');aoSame(0,$crash['http']->inner->connections,'held pass does not even refresh roster');
    $crash['clock']->advance(60);aoCycle($crash);aoSame(0,count($crash['http']->memberPatches()),'held pass never becomes reclaimable by age');aoNoRoomEffects($crash,'held closure');

    // Without a receipt command, the scheduled session still warns at S+29m and closes at S+30m.
    $unstarted=aoFixture(policy:'best_effort');aoCycle($unstarted);$unstarted['http']->inner->voices=[$owner=>DiscordRestClient::VOICE_CHANNEL_ID,$other=>DiscordRestClient::VOICE_CHANNEL_ID];
    $unstarted['clock']->set('@'.($unstarted['at']+1740));aoCycle($unstarted);
    aoSame($unstarted['at']+1740,(int)aoSession($unstarted)['warning_sent_at'],'unstarted session warns at scheduled plus29 minutes');
    $warnings=array_values(array_filter($unstarted['http']->inner->messages,static fn(array $message):bool=>str_starts_with($message['content'],'この回はあと1分で終了です。')));aoSame(1,count($warnings),'unstarted warning says this session ends in one minute');
    aoCheck(str_contains($warnings[0]['content']??'','VCは常時利用できるため、再入室はできます。')&&str_contains($warnings[0]['content']??'','終了時に残っている方は参加登録の有無にかかわらず切断します。'),'unstarted warning explains one-time disconnect and continued room entry');
    aoSame(null,aoSession($unstarted)['started_at'],'unstarted warning does not fabricate receipt start');aoSame(0,count($unstarted['http']->memberPatches()),'unstarted warning never disconnects early');
    $unstarted['clock']->advance(59);aoCycle($unstarted);aoSame('open',aoSession($unstarted)['status'],'unstarted session remains open through scheduled plus29m59s');
    $unstarted['clock']->advance(1);aoCycle($unstarted);aoSame('closed',aoSession($unstarted)['status'],'unstarted session closes at scheduled plus30 minutes');
    aoSame(2,count($unstarted['http']->memberPatches()),'unstarted first roster disconnects each scoped occupant once');aoSame(null,$unstarted['http']->inner->voices[$owner],'unstarted original occupant disconnects');aoSame(null,$unstarted['http']->inner->voices[$other],'unstarted other occupant disconnects');
    aoSame(0,(int)$unstarted['context']->db->pdo()->query('SELECT COUNT(*) FROM receipt_announcements')->fetchColumn(),'unstarted closure fabricates no receipt');aoSame(0,(int)$unstarted['context']->db->pdo()->query('SELECT COUNT(*) FROM attendance')->fetchColumn(),'unstarted closure fabricates no attendance');
    $unstarted['http']->inner->voices[$owner]=DiscordRestClient::VOICE_CHANNEL_ID;$unstarted['http']->inner->gateway?->change($owner,DiscordRestClient::VOICE_CHANNEL_ID);
    $connections=$unstarted['http']->inner->connections;$unstarted['clock']->advance(60);$next=$unstarted['runtime']->build($unstarted['config'],static fn():bool=>true);$unstarted['runtime']->cycle($next,static fn():bool=>true);aoCycle($unstarted);
    aoSame(2,count($unstarted['http']->memberPatches()),'unstarted completed closure never kicks a returning occupant');aoSame($connections,$unstarted['http']->inner->connections,'unstarted completed closure never reconnects a roster');aoSame(DiscordRestClient::VOICE_CHANNEL_ID,$unstarted['http']->inner->voices[$owner],'unstarted returning occupant remains');aoNoRoomEffects($unstarted,'unstarted closure');

    $unsent=aoFixture(policy:'best_effort');aoCycle($unsent);$unsent['clock']->set('@'.$unsent['at']);$unsent['http']->inner->voices[$owner]=DiscordRestClient::VOICE_CHANNEL_ID;$unsent['http']->receiptOutcomes=[new HttpResponse(403,[],'{}')];
    aoSame('receipt_announcement_unconfirmed',$unsent['context']->service->openAttendance($owner,'always-open-unsent')['code'],'known receipt rejection stays unconfirmed');
    $receiptAttempts=count(array_filter($unsent['http']->requests,AlwaysOpenFixtureHttp::receipt(...)));aoSame(1,$receiptAttempts,'known rejection attempted receipt only once');
    $unsent['clock']->advance(600);aoCycle($unsent);aoSame('closed',aoSession($unsent)['status'],'expired known-unsent receipt completes local closure');
    $receipt=$unsent['context']->db->pdo()->query('SELECT status,expired_at FROM receipt_announcements')->fetch();aoCheck($receipt['expired_at']!==null,'known-unsent receipt records expiry');
    aoSame($receiptAttempts,count(array_filter($unsent['http']->requests,AlwaysOpenFixtureHttp::receipt(...))),'expired known-unsent receipt never posts again');aoSame(0,count($unsent['http']->memberPatches()),'known-unsent expiry never disconnects without announcement');aoSame(0,$unsent['http']->inner->connections,'known-unsent expiry never opens a roster');
    aoSame(DiscordRestClient::VOICE_CHANNEL_ID,$unsent['http']->inner->voices[$owner],'known-unsent expiry leaves occupant in open room');aoSame('1049600',$unsent['http']->inner->allow,'known-unsent expiry preserves View and Connect');aoSame('0',$unsent['http']->inner->deny,'known-unsent expiry adds no deny');aoNoRoomEffects($unsent,'known-unsent expiry');
})();}catch(Throwable $error){$aoFailure=$error->getMessage();}
finally{
    foreach($aoFixtures as$f){$f['context']->roster->close();}unset($f);$aoFixtures=[];gc_collect_cycles();
    $owned=realpath($aoRoot);$temporary=realpath(sys_get_temp_dir());
    if($owned!==false&&$temporary!==false&&dirname($owned)===$temporary&&str_starts_with(basename($owned),'radio-always-open-')){
        $remove=static function(string $dir)use(&$remove):void{foreach(new DirectoryIterator($dir)as$entry){if($entry->isDot()){continue;}if($entry->isLink()){unlink($entry->getPathname());}elseif($entry->isDir()){$remove($entry->getPathname());rmdir($entry->getPathname());}else{unlink($entry->getPathname());}}};$remove($owned);rmdir($owned);
    }
}
if($aoFailure!==null){fwrite(STDERR,$aoFailure."\n");exit(1);}echo 'Always open: '.$aoChecks.' assertions passed (fictional HTTP/clock, isolated SQLite, normal runtime; no live changes).'."\n";
