<?php
declare(strict_types=1);
require_once __DIR__.'/artifact_directory.php';
require dirname(__DIR__).'/bootstrap.php';
require __DIR__.'/fixtures/OperationsRuntimeFixture.php';

use RadioStamp\Core\{Database,FrozenClock,StampPolicy};
use RadioStamp\Discord\{DiscordRestClient,Gateway,HttpAdapter,HttpRequest,HttpResponse,OpeningNoticeMessage,ServerOperationsClient};
use RadioStamp\Operations\OperationsRuntime;

$onChecks=0;
function onSame(mixed $expected,mixed $actual,string $why):void
{
    global $onChecks;++$onChecks;
    if($expected!==$actual){throw new RuntimeException('Opening notice: '.$why.' expected='.json_encode($expected).' actual='.json_encode($actual));}
}
function onCheck(bool $actual,string $why):void{onSame(true,$actual,$why);}
function onStamp(string $at):int{return (new DateTimeImmutable($at,new DateTimeZone('Asia/Tokyo')))->getTimestamp();}

/** The sole HTTP adapter: every unhandled route throws; there is no network fallback. */
final class OpeningFixtureHttp implements HttpAdapter
{
    public RuntimeFixtureHttp $inner;
    public array $requests=[],$noticeOutcomes=[],$roomOutcomes=[];
    public ?Closure $noticeHook=null,$requestHook=null;
    public function __construct(){$this->inner=new RuntimeFixtureHttp();$this->inner->allow='0';$this->inner->deny='1049600';}
    public static function notice(HttpRequest $request):bool
    {
        $payload=json_decode($request->body(),true);$content=$payload['content']??null;
        return $request->method()==='POST'&&parse_url($request->url(),PHP_URL_PATH)==='/api/v10/channels/'.DiscordRestClient::TEXT_CHANNEL_ID.'/messages'
            &&is_string($content)&&str_contains($content,'https://discord.com/channels/'.DiscordRestClient::GUILD_ID.'/'.DiscordRestClient::VOICE_CHANNEL_ID);
    }
    public function exchange(HttpRequest $request):HttpResponse
    {
        $this->requests[]=$request;
        if($this->requestHook!==null){($this->requestHook)($request);}
        $isRoom=$request->method()==='PUT'&&str_contains($request->url(),'/permissions/'.DiscordRestClient::GUILD_ID);
        if($isRoom&&$this->roomOutcomes){$outcome=array_shift($this->roomOutcomes);if($outcome instanceof Throwable){throw $outcome;}return $outcome;}
        if(self::notice($request)){
            if($this->noticeHook!==null){($this->noticeHook)($request);}
            if($this->noticeOutcomes){$outcome=array_shift($this->noticeOutcomes);if($outcome instanceof Throwable){throw $outcome;}return $outcome;}
        }
        return $this->inner->exchange($request);
    }
    public function notices():array{return array_values(array_filter($this->requests,self::notice(...)));}
    public function messagesTo(string $channel):array{return array_values(array_filter($this->requests,static fn(HttpRequest $r):bool=>$r->method()==='POST'&&parse_url($r->url(),PHP_URL_PATH)==='/api/v10/channels/'.$channel.'/messages'));}
}

$onRoot=sys_get_temp_dir().'/radio-opening-notice-'.bin2hex(random_bytes(8));mkdir($onRoot,0700);
$onRoots=[];
function onFixture(string $scheduled='2026-10-22 07:00',string $slot='morning',?int $now=null):array
{
    global $onRoot,$onRoots;
    $root=$onRoot.'/case-'.count($onRoots);mkdir($root,0700);mkdir($root.'/private',0700);mkdir($root.'/public',0700);$onRoots[]=$root;
    $at=onStamp($scheduled);$clock=new FrozenClock('@'.($now??$at-1800));$http=new OpeningFixtureHttp();
    $config=runtimeFixtureConfig($root,'manual');$config['room_baseline']=['allow'=>'0','deny'=>'1049600'];
    $config['schedule']=['daily_times'=>[],'one_off_times'=>[['at'=>$scheduled,'slot_id'=>$slot]]];$config['weekly_ranking_enabled']=false;
    $runtime=new OperationsRuntime($http,fn()=>$http->inner->transport(),$clock);
    $context=$runtime->build($config,static fn():bool=>true);$id=(new DateTimeImmutable('@'.$at))->setTimezone(new DateTimeZone('Asia/Tokyo'))->format('Y-m-d_Hi');
    return compact('root','at','clock','http','config','runtime','context','id');
}
function onCycle(array $f):array{return $f['runtime']->cycle($f['context'],static fn():bool=>true);}
function onDue(array $f,int $offset=0):void{$f['clock']->set('@'.($f['at']-600+$offset));onCycle($f);}
function onEffect(array $f):array
{
    $q=$f['context']->db->pdo()->prepare('SELECT * FROM core_effects WHERE effect_key=?');$q->execute([OpeningNoticeMessage::key($f['id'])]);$row=$q->fetch();$q->closeCursor();return $row?:[];
}
function onSession(array $f):array
{
    $q=$f['context']->db->pdo()->prepare('SELECT * FROM sessions WHERE id=?');$q->execute([$f['id']]);$row=$q->fetch();$q->closeCursor();return $row?:[];
}
function onClaim(array $f):array
{
    $pdo=$f['context']->db->pdo();$key=OpeningNoticeMessage::key($f['id']);
    $q=$pdo->prepare("UPDATE core_effects SET status='pending',cancelled_at=NULL,lease_owner=?,lease_until=0,attempts=1,last_attempt_at=? WHERE effect_key=?");$q->execute([str_repeat('c',32),$f['clock']->now()->getTimestamp(),$key]);
    return json_decode(onEffect($f)['payload_json'],true,32,JSON_THROW_ON_ERROR);
}
function onPayload(HttpRequest $request):array{return json_decode($request->body(),true,32,JSON_THROW_ON_ERROR);}
function onHistory(Database $db):array
{
    $out=[];foreach(['participants','attendance','memorial_awards','achievement_notices','day_achievement_notices','core_interactions']as$table){$out[$table]=$db->pdo()->query('SELECT * FROM '.$table.' ORDER BY rowid')->fetchAll();}return $out;
}

$onFailure=null;
try{(static function():void{
    $slots=['midnight'=>['00:00','🌃',false],'dawn'=>['04:00','🌄',false],'morning'=>['07:00','🌅',true],'midday'=>['12:30','☀️',true],'night'=>['22:00','🌙',true]];
    foreach($slots as$slot=>[$time,$emoji,$here]){
        $f=onFixture('2026-10-22 '.$time,$slot);$history=onHistory($f['context']->db);
        onCycle($f);onSame(0,count($f['http']->notices()),$slot.' opening S-30 does not announce');
        onSame('open',onSession($f)['status'],$slot.' existing opening time remains S-30');
        $old=$f['context']->db->pdo()->prepare('SELECT COUNT(*) FROM core_effects WHERE effect_key=?');$old->execute(['reminder:'.$f['id']]);onSame(0,(int)$old->fetchColumn(),$slot.' no old reminder outbox');$old->closeCursor();
        $f['clock']->set('@'.($f['at']-601));onCycle($f);onSame(0,count($f['http']->notices()),$slot.' one second before S-10 remains silent');
        onDue($f);onSame(1,count($f['http']->notices()),$slot.' due window announces once');$payload=onPayload($f['http']->notices()[0]);
        onSame($here?['everyone']:[],$payload['allowed_mentions']['parse'],$slot.' slot-specific parse');onSame($here?1:0,substr_count($payload['content'],'@here'),$slot.' only intended @here');
        onSame([], $payload['allowed_mentions']['users'],$slot.' no user pings');onSame([],$payload['allowed_mentions']['roles'],$slot.' no role pings');onSame(false,$payload['allowed_mentions']['replied_user'],$slot.' no reply ping');
        onCheck(!str_contains($payload['content'],'@everyone')&&!str_contains($payload['content'],'<@'),$slot.' no unintended mentions');
        onCheck(str_contains($payload['content'],$emoji),$slot.' approved contextual emoji');onCheck(str_contains($payload['content'],'2026-10-22 '.$time.' JST'),$slot.' explicit scheduled JST date');
        onCheck(str_contains($payload['content'],'🚪 VCは開いているよ！参加はこちら → '),$slot.' approved ten-minute notice wording');onCheck(!str_contains($payload['content'],'本日'),$slot.' no ambiguous midnight today label');
        onCheck(str_contains($payload['content'],'https://discord.com/channels/'.DiscordRestClient::GUILD_ID.'/'.DiscordRestClient::VOICE_CHANNEL_ID),$slot.' fixed direct VC link');
        onSame(false,$payload['tts'],$slot.' no TTS');onSame([], $payload['components'],$slot.' no new buttons');onSame('done',onEffect($f)['status'],$slot.' confirmed send marked done');
        $requests=$f['http']->requests;$noticeIndex=array_search($f['http']->notices()[0],$requests,true);$readbackBefore=false;
        for($i=0;$i<$noticeIndex;$i++){if($requests[$i]->method()==='GET'&&parse_url($requests[$i]->url(),PHP_URL_PATH)==='/api/v10/channels/'.DiscordRestClient::VOICE_CHANNEL_ID){$readbackBefore=true;}}
        onCheck($readbackBefore,$slot.' room readback precedes POST');
        onCycle($f);$restart=$f['runtime']->build($f['config'],static fn():bool=>true);$f['runtime']->cycle($restart,static fn():bool=>true);onSame(1,count($f['http']->notices()),$slot.' repeated tick and reconstructed context never repost');
        onSame($history,onHistory($f['context']->db),$slot.' notification preserves participant and award history');
        if($slot==='midnight'){onSame('2026-10-21 23:50',$f['clock']->now()->setTimezone(new DateTimeZone('Asia/Tokyo'))->format('Y-m-d H:i'),'midnight notice occurs on previous calendar day');}
    }
    // Ping selection follows the logical slot, never the wall-clock time of the notice.
    foreach([['2026-10-22 00:20','morning',true],['2026-10-22 12:30','midnight',false]]as[$when,$slot,$here]){
        $f=onFixture($when,$slot);onCycle($f);onDue($f);onSame(1,count($f['http']->notices()),'time replacement still has one due announcement');$p=onPayload($f['http']->notices()[0]);onSame($here?['everyone']:[],$p['allowed_mentions']['parse'],'one-off replacement preserves logical-slot ping policy');onCheck(str_contains($p['content'],$when.' JST'),'one-off copy uses actual scheduled date/time');
    }
    $late=onFixture(now:onStamp('2026-10-22 06:55'));onCycle($late);onSame(1,count($late['http']->notices()),'first worker inside S-10 window may open/readback/announce');
    foreach([0,1,1800]as$offset){$expired=onFixture(now:onStamp('2026-10-22 07:00')+$offset);onCycle($expired);onCycle($expired);onSame(0,count($expired['http']->notices()),'S or later never emits an overdue future-tense notice');}

    // The VC is checked when the notice is due, not merely because an older open-room effect is done.
    $closed=onFixture();onCycle($closed);$closed['http']->inner->allow='0';$closed['http']->inner->deny='1049600';onDue($closed);onSame(0,count($closed['http']->notices()),'closed room at due time prevents notice');onSame(0,(int)onEffect($closed)['attempts'],'closed room does not spend send attempts');onSame(null,onEffect($closed)['lease_owner'],'closed room keeps notification available');
    $closed['http']->inner->allow='1048576';$closed['http']->inner->deny='1024';$closed['clock']->advance(10);onCycle($closed);onSame(1,count($closed['http']->notices()),'known room opening later inside window permits one notice');
    foreach([new HttpResponse(403,[],'{}'),new HttpResponse(0,[],'','fictional timeout')]as$outcome){$room=onFixture(now:onStamp('2026-10-22 06:50'));$room['http']->roomOutcomes=[$outcome];onCycle($room);onSame(0,count($room['http']->notices()),'failed or uncertain room opening never announces success');}

    // Known rejection may retry with backoff, but repeated polling consumes no extra attempt while waiting.
    $retry=onFixture();onCycle($retry);$retry['http']->noticeOutcomes=array_fill(0,5,new HttpResponse(403,[],'{}'));onDue($retry);
    onSame(1,count($retry['http']->notices()),'one known rejection makes one request');onSame(null,onEffect($retry)['lease_owner'],'known rejection releases only the notification claim');
    onCycle($retry);onSame(1,count($retry['http']->notices()),'backoff prevents immediate retry');onSame(1,(int)onEffect($retry)['attempts'],'waiting poll does not consume attempt');
    for($i=2;$i<=5;$i++){$next=(int)onEffect($retry)['lease_until'];$retry['clock']->set('@'.$next);onCycle($retry);onSame($i,count($retry['http']->notices()),'known rejection retries only when available');}
    $retry['clock']->advance(60);onCycle($retry);onSame(5,count($retry['http']->notices()),'known failures stop after five attempts');onCheck(onEffect($retry)['cancelled_at']!==null,'finite known failure stop is persisted');

    $notStarted=onFixture();onCycle($notStarted);$blockNotice=true;
    $noStartGuard=static function()use($notStarted,&$blockNotice):bool{return !$blockNotice||(onEffect($notStarted)['lease_owner']??null)===null;};
    $notStarted['context']=$notStarted['runtime']->build($notStarted['config'],$noStartGuard);onDue($notStarted);
    onSame(0,count($notStarted['http']->notices()),'budget guard before exchange is known not-started');onSame(1,(int)onEffect($notStarted)['attempts'],'known not-started attempt is accounted once');onSame(null,onEffect($notStarted)['lease_owner'],'known not-started releases its own claim');
    $blockNotice=false;$notStarted['clock']->advance(5);onCycle($notStarted);onSame(1,count($notStarted['http']->notices()),'known not-started may later retry once inside window');

    foreach([new HttpResponse(0,[],'','fictional timeout'),new RuntimeException('fictional post throw'),new HttpResponse(200,[],'{"id":"invalid"}')]as$unknown){
        $f=onFixture();onCycle($f);$f['http']->noticeOutcomes=[$unknown];onDue($f);onSame(1,count($f['http']->notices()),'uncertain attempted POST is observed once');onCheck(onEffect($f)['lease_owner']!==null,'uncertain attempt is held with owner');
        $f['clock']->advance(40);$next=$f['runtime']->build($f['config'],static fn():bool=>true);$f['runtime']->cycle($next,static fn():bool=>true);onSame(1,count($f['http']->notices()),'elapsed old lease and restart cannot replay an uncertain POST');
        onCheck(count($f['http']->messagesTo(DiscordRestClient::VOICE_CHANNEL_ID))>=1,'held notice does not prevent ordinary VC guide');
    }
    $save=onFixture();onCycle($save);$save['http']->noticeHook=static function()use($save):void{$save['context']->db->pdo()->exec("CREATE TRIGGER fictional_open_save_failure BEFORE UPDATE OF status ON core_effects WHEN NEW.kind='opening_notice' AND NEW.status='done' BEGIN SELECT RAISE(ABORT,'fictional save failure'); END");};
    onDue($save);onSame(1,count($save['http']->notices()),'confirmed HTTP success happened once before save failure');onCheck(onEffect($save)['lease_owner']!==null,'post-success save failure remains held');$save['clock']->advance(40);onCycle($save);onSame(1,count($save['http']->notices()),'save failure never replays a possibly delivered message');

    $crash=onFixture();onCycle($crash);$pdo=$crash['context']->db->pdo();$q=$pdo->prepare('UPDATE core_effects SET lease_owner=?,attempts=1,last_attempt_at=?,lease_until=? WHERE effect_key=?');$q->execute([str_repeat('d',32),$crash['at']-620,$crash['at']-610,OpeningNoticeMessage::key($crash['id'])]);onDue($crash);onSame(0,count($crash['http']->notices()),'simulated in-flight crash claim is not reclaimed after deadline');

    foreach(['done','pending']as$status){$old=onFixture();onCycle($old);$q=$old['context']->db->pdo()->prepare('INSERT INTO core_effects(effect_key,kind,payload_json,status,attempts,last_attempt_at) VALUES(?,?,?,?,1,?)');$q->execute(['reminder:'.$old['id'],'reminder',json_encode(['session_id'=>$old['id']]),$status,$old['at']-1800]);onDue($old);onSame(0,count($old['http']->notices()),'previous-version sent/attempted reminder blocks duplicate new notice');}

    // Re-enter the real dispatcher while the first fake transport is outstanding.
    $parallel=onFixture();onCycle($parallel);$reentered=false;$parallel['http']->noticeHook=static function()use($parallel,&$reentered):void{if($reentered){return;}$reentered=true;$again=$parallel['runtime']->build($parallel['config'],static fn():bool=>true);$parallel['runtime']->cycle($again,static fn():bool=>true);};onDue($parallel);onSame(1,count($parallel['http']->notices()),'another invocation while sending cannot claim the same notice');

    // Malformed payloads cannot broaden the dedicated @here capability.
    foreach(['everyone','user','role','wrong_slot','wrong_link']as$change){$bad=onFixture();onCycle($bad);$bad['http']->noticeOutcomes=[new HttpResponse(403,[],'{}')];onDue($bad);$snapshot=onClaim($bad);$before=count($bad['http']->notices());
        if($change==='everyone'){$snapshot['payload']['content'].=' @everyone';}elseif($change==='user'){$snapshot['payload']['content'].=' <@800000000000001021>';$snapshot['payload']['allowed_mentions']['users']=['800000000000001021'];}elseif($change==='role'){$snapshot['payload']['allowed_mentions']['roles']=['900000000000000123'];}elseif($change==='wrong_slot'){$snapshot['slot_id']='midnight';}else{$snapshot['payload']['content'].=' https://example.invalid/';}
        $result=$bad['context']->client->postOpeningNotice($snapshot,OpeningNoticeMessage::key($bad['id']),fn():int=>$bad['clock']->now()->getTimestamp());onSame(false,$result->ok,'malformed fixed snapshot is rejected: '.$change);onSame($before,count($bad['http']->notices()),'malformed snapshot never enters fake transport: '.$change);
    }
    // Check the second guard after the public method's first claim/snapshot validation.
    foreach(['expiry','closing','claim_changed','payload_changed']as$change){$f=onFixture();onCycle($f);$f['http']->noticeOutcomes=[new HttpResponse(403,[],'{}')];onDue($f);$snapshot=onClaim($f);$before=count($f['http']->notices());$called=false;
        $guard=static function()use($change,$f,&$called):bool{if($called){return true;}$q=$f['context']->db->pdo()->prepare("SELECT 1 FROM operation_receipts WHERE operation_key=? AND status='sending'");$q->execute([OpeningNoticeMessage::key($f['id'])]);$claimed=$q->fetchColumn()!==false;$q->closeCursor();if(!$claimed){return true;}$called=true;if($change==='expiry'){$f['clock']->set('@'.$f['at']);}elseif($change==='closing'){$f['context']->db->pdo()->prepare("UPDATE sessions SET status='closing' WHERE id=?")->execute([$f['id']]);}elseif($change==='claim_changed'){$f['context']->db->pdo()->prepare('UPDATE core_effects SET lease_owner=? WHERE effect_key=?')->execute([str_repeat('b',32),OpeningNoticeMessage::key($f['id'])]);}else{$f['context']->db->pdo()->prepare('UPDATE core_effects SET payload_json=? WHERE effect_key=?')->execute(['{}',OpeningNoticeMessage::key($f['id'])]);}return true;};
        $client=new ServerOperationsClient($f['context']->db,$f['http'],'fictional_opening_token_000000',['enabled'=>true,'posts_enabled'=>true,'room_permissions_enabled'=>true,'room_baseline'=>$f['config']['room_baseline'],'request_guard'=>$guard]);$result=$client->postOpeningNotice($snapshot,OpeningNoticeMessage::key($f['id']),fn():int=>$f['clock']->now()->getTimestamp());onSame(true,$called,'late boundary hook runs after initial validation and receipt claim');onSame(false,$result->ok,'late boundary guard rejects '.$change);onSame($before,count($f['http']->notices()),'late boundary '.$change.' enters no message transport');
    }

    // The *response latency* of the final room GET may cross the deadline or invalidate the owner.
    foreach(['expiry','claim_changed']as$change){$f=onFixture();onCycle($f);$f['http']->noticeOutcomes=[new HttpResponse(403,[],'{}')];onDue($f);$snapshot=onClaim($f);$before=count($f['http']->notices());$changed=false;
        $f['http']->requestHook=static function(HttpRequest $request)use($f,$change,&$changed):void{
            if($changed||$request->method()!=='GET'||parse_url($request->url(),PHP_URL_PATH)!=='/api/v10/channels/'.DiscordRestClient::VOICE_CHANNEL_ID){return;}
            $q=$f['context']->db->pdo()->prepare("SELECT 1 FROM operation_receipts WHERE operation_key=? AND status='sending'");$q->execute([OpeningNoticeMessage::key($f['id'])]);$claimed=$q->fetchColumn()!==false;$q->closeCursor();if(!$claimed){return;}$changed=true;
            if($change==='expiry'){$f['clock']->set('@'.$f['at']);}else{$f['context']->db->pdo()->prepare('UPDATE core_effects SET lease_owner=? WHERE effect_key=?')->execute([str_repeat('f',32),OpeningNoticeMessage::key($f['id'])]);}
        };
        $result=$f['context']->client->postOpeningNotice($snapshot,OpeningNoticeMessage::key($f['id']),fn():int=>$f['clock']->now()->getTimestamp());
        onSame(true,$changed,'fictional final room GET changes boundary after receipt claim');onSame(false,$result->ok,'GET latency invalidates '.$change);onSame($before,count($f['http']->notices()),'GET latency '.$change.' cannot POST');
    }

    $ordinary=onFixture();$ordinary['context']->client->postText(DiscordRestClient::TEXT_CHANNEL_ID,'literal @here @everyone <@800000000000001021>','fictional:ordinary');$ordinaryPost=$ordinary['http']->messagesTo(DiscordRestClient::TEXT_CHANNEL_ID)[0];onSame(Gateway::ALLOWED_MENTIONS,onPayload($ordinaryPost)['allowed_mentions'],'ordinary message capability retains zero mention permissions');

    // Same production cycle, separate old week and current opening; all transport remains fictional.
    $weekly=onFixture('2026-10-26 07:00','morning');$weekly['config']['weekly_ranking_enabled']=true;$weekly['context']=$weekly['runtime']->build($weekly['config'],static fn():bool=>true);$p=$weekly['context']->db->pdo();$past=onStamp('2026-10-20 07:00');
    $p->exec("INSERT INTO participants(user_id,total_count) VALUES('900000000000000222',1)");$q=$p->prepare("INSERT INTO sessions(id,scheduled_at,count_date,opens_at,unstarted_closes_at,status,slot_id,stamp) VALUES(?,?,?,?,?,'closed','morning','LED')");$q->execute(['2026-10-20_0700',$past,'2026-10-20',$past-1800,$past+1800]);$q=$p->prepare("INSERT INTO attendance(session_id,user_id,count_date,attended_at,ordinal,stamp) VALUES('2026-10-20_0700','900000000000000222','2026-10-20',?,1,'LED')");$q->execute([$past]);
    onCycle($weekly);onDue($weekly);onSame(1,count($weekly['http']->notices()),'opening notice is separate from weekly result');$weekly['clock']->set('@'.$weekly['at']);onCycle($weekly);onSame(1,count($weekly['http']->messagesTo(DiscordRestClient::RECORDS_CHANNEL_ID)),'Monday07 cycle publishes prior week via existing weekly fake route');onSame(1,count($weekly['http']->notices()),'weekly cycle cannot repeat already sent opening notice');onSame(0,count($weekly['http']->inner->memberPatches()),'manual test never cuts or moves members');
})();}catch(Throwable $error){$onFailure=$error->getMessage();}
finally{
    gc_collect_cycles();$owned=realpath($onRoot);$temporary=realpath(sys_get_temp_dir());
    if($owned!==false&&$temporary!==false&&dirname($owned)===$temporary&&str_starts_with(basename($owned),'radio-opening-notice-')){
        $remove=static function(string $dir)use(&$remove):void{foreach(new DirectoryIterator($dir)as$entry){if($entry->isDot()){continue;}if($entry->isLink()){unlink($entry->getPathname());}elseif($entry->isDir()){$remove($entry->getPathname());rmdir($entry->getPathname());}else{unlink($entry->getPathname());}}};$remove($owned);rmdir($owned);
    }
}
if($onFailure!==null){fwrite(STDERR,$onFailure."\n");exit(1);}echo 'Opening notice: '.$onChecks.' assertions passed (fictional HTTP/clock, isolated SQLite, normal runtime; no live changes).'."\n";
