<?php
declare(strict_types=1);
require_once __DIR__.'/artifact_directory.php';
require dirname(__DIR__).'/bootstrap.php';
require __DIR__.'/fixtures/OperationsRuntimeFixture.php';

use RadioStamp\Core\{Database,FrozenClock,WeeklyRanking};
use RadioStamp\Discord\{DiscordRestClient,WeeklyRankingGateway,RestResult,TransportOutcome,HttpAdapter,HttpRequest,HttpResponse,ServerOperationsClient};
use RadioStamp\Operations\OperationsRuntime;
use RadioStamp\Http\{OperationsEndpoint,PostResponseDriver,Response};

$wrChecks=0;
function wrSame(mixed $expected,mixed $actual,string $why):void{global $wrChecks;++$wrChecks;if($expected!==$actual){throw new RuntimeException('Weekly ranking: '.$why.' expected='.json_encode($expected).' actual='.json_encode($actual));}}
function wrCheck(bool $condition,string $why):void{wrSame(true,$condition,$why);}
function wrTime(string $value):int{return (new DateTimeImmutable($value,new DateTimeZone('Asia/Tokyo')))->getTimestamp();}
function wrConfig():array{return ['weekly_ranking_enabled'=>true,'guild_id'=>DiscordRestClient::GUILD_ID,'records_channel_id'=>DiscordRestClient::RECORDS_CHANNEL_ID];}

final class WrGateway implements WeeklyRankingGateway
{
    public array $calls=[],$outcomes=[];
    public ?Closure $hook=null;
    public function __construct(private ?Database $db=null){}
    public function voiceState(string $guildId,string $userId):\RadioStamp\Discord\VoiceState{throw new RuntimeException('weekly must not inspect voice state');}
    public function roster(string $guildId,string $channelId):\RadioStamp\Discord\VoiceRoster{throw new RuntimeException('weekly must not inspect voice roster');}
    public function sendMessage(string $channelId,string $content,string $key,array $components=[]):bool{throw new RuntimeException('weekly must use its dedicated posting capability');}
    public function setRoomOpen(string $channelId,bool $open,string $key):bool{throw new RuntimeException('weekly must not change rooms');}
    public function moveMember(string $guildId,string $userId,string $destinationId,string $key):bool{throw new RuntimeException('weekly must not move people');}
    public function disconnectMember(string $guildId,string $userId,string $key):bool{throw new RuntimeException('weekly must not disconnect people');}
    public function syncDecorationRole(string $guildId,string $userId,?string $roleId,string $key):bool{throw new RuntimeException('weekly must not change roles');}
    public function sendWeeklyRankingResult(string $channel,array $payload,string $key):RestResult
    {
        if($this->db!==null){wrSame(false,$this->db->inTransaction(),'weekly transport never runs inside the core write transaction');}
        $this->calls[]=compact('channel','payload','key');if($this->hook!==null){($this->hook)($channel,$payload,$key);}
        $outcome=array_shift($this->outcomes)??TransportOutcome::Succeeded;
        if($outcome instanceof Throwable){throw $outcome;}
        if($outcome instanceof RestResult){return $outcome;}
        return new RestResult($outcome===TransportOutcome::Succeeded,$outcome->value,$outcome===TransportOutcome::Succeeded?200:0,
            $outcome===TransportOutcome::Succeeded?['id'=>(string)(900000000000030000+count($this->calls)),'channel_id'=>$channel]:[],outcome:$outcome);
    }
}

function wrFixture(string $now='2026-10-26T07:00:00+09:00',?Database $db=null):array
{
    $db??=new Database(':memory:');$clock=new FrozenClock($now);$gateway=new WrGateway($db);$config=wrConfig();
    $ranking=new WeeklyRanking($db,$gateway,$clock,$config);return compact('db','clock','gateway','config','ranking');
}
function wrSeed(Database $db,string $user,array $scheduled,?array $attended=null):void
{
    static $sequence=0;$db->pdo()->prepare('INSERT OR IGNORE INTO participants(user_id,total_count,latest_title) VALUES(?,999,?)')->execute([$user,'FICTIONAL_NAME @everyone']);
    $ordinal=(int)$db->pdo()->query("SELECT COALESCE(MAX(ordinal),0) FROM attendance WHERE user_id='".$user."'")->fetchColumn();
    $session=$db->pdo()->prepare("INSERT INTO sessions(id,scheduled_at,count_date,opens_at,unstarted_closes_at,status) VALUES(?,?,?,?,?,'closed')");
    $attendance=$db->pdo()->prepare('INSERT INTO attendance(session_id,user_id,count_date,attended_at,ordinal,stamp) VALUES(?,?,?,?,?,?)');
    foreach($scheduled as$i=>$at){$id='fictional-week-'.++$sequence;$session->execute([$id,$at,'1900-01-01',$at-1800,$at+1800]);$attendance->execute([$id,$user,'1900-01-01',$attended[$i]??$at,++$ordinal,'IC']);}
}
function wrCounts(Database $db,array $counts,int $weekStart,int $userBase=900000000000040000):array
{
    static $offset=0;$users=[];foreach($counts as$i=>$count){$user=(string)($userBase+$i+1);$users[]=$user;$times=[];for($n=0;$n<$count;$n++){$times[]=$weekStart+3600+60*++$offset;}wrSeed($db,$user,$times);}return $users;
}
function wrWeek(Database $db):array{return $db->pdo()->query('SELECT * FROM weekly_ranking_weeks ORDER BY week_start')->fetchAll();}
function wrParts(Database $db):array{return $db->pdo()->query('SELECT * FROM weekly_ranking_parts ORDER BY week_key,part_no')->fetchAll();}
function wrHistory(Database $db):array
{
    $out=[];foreach(['sessions','participants','attendance','memorial_awards','achievement_notices','day_achievement_notices','core_interactions','core_effects']as$table){$out[$table]=$db->pdo()->query('SELECT * FROM '.$table.' ORDER BY rowid')->fetchAll();}return $out;
}
function wrPayload(array $payload):void
{
    wrSame(['content','tts','allowed_mentions'],array_keys($payload),'weekly payload has only fixed supported fields');
    wrSame(false,$payload['tts'],'no weekly TTS');wrCheck(mb_strlen($payload['content'],'UTF-8')<=2000,'every part stays within2000 characters');
    wrSame([], $payload['allowed_mentions']['parse'],'no everyone/here parser');wrSame([], $payload['allowed_mentions']['roles'],'no role mention');wrSame(false,$payload['allowed_mentions']['replied_user'],'no reply ping');
    $ids=$payload['allowed_mentions']['users'];wrCheck(count($ids)<=100,'at most100 explicitly listed users per part');wrSame($ids,array_values(array_unique($ids)),'mention allowlist is unique');
    preg_match_all('/<@([1-9][0-9]{16,19})>/',$payload['content'],$matches);$mentions=array_values(array_unique($matches[1]));sort($mentions,SORT_STRING);$expected=$ids;sort($expected,SORT_STRING);wrSame($expected,$mentions,'allowed users exactly match this part content');
    wrCheck(!str_contains($payload['content'],'@everyone')&&!str_contains($payload['content'],'@here')&&!str_contains($payload['content'],'<@&'),'no unrelated mass mention syntax');
}

final class WrPostHttp implements HttpAdapter
{
    public array $calls=[];
    public function exchange(HttpRequest $request):HttpResponse
    {
        wrSame('POST',$request->method(),'weekly concrete transport only posts its fixed message');
        wrSame('/api/v10/channels/'.DiscordRestClient::RECORDS_CHANNEL_ID.'/messages',parse_url($request->url(),PHP_URL_PATH),'weekly concrete transport destination fixed');
        $this->calls[]=$request;return new HttpResponse(200,[],json_encode(['id'=>'900000000000062001','channel_id'=>DiscordRestClient::RECORDS_CHANNEL_ID],JSON_THROW_ON_ERROR));
    }
}
function wrClaimed(int $start):array
{
    $f=wrFixture();wrCounts($f['db'],[1],$start,900000000000060000);$f['gateway']->outcomes=[TransportOutcome::NotStarted];$f['ranking']->tick();
    $f['db']->pdo()->exec("UPDATE weekly_ranking_parts SET status='sending',claim_owner='".str_repeat('a',32)."'");
    $part=wrParts($f['db'])[0];$f['payload']=json_decode($part['payload_json'],true,32,JSON_THROW_ON_ERROR);$f['key']=$part['part_key'];$f['http']=new WrPostHttp();
    $f['options']=['enabled'=>true,'posts_enabled'=>true,'weekly_ranking_enabled'=>true,'weekly_ranking_now'=>static fn():int=>$f['clock']->now()->getTimestamp(),'now'=>static fn():float=>(float)$f['clock']->now()->getTimestamp()];return $f;
}

if(($argv[1]??null)==='--weekly-child'){
    $f=wrFixture(db:new Database($argv[2]));$f['ranking']->tick();echo count($f['gateway']->calls);exit(0);
}

$wrRoot=sys_get_temp_dir().'/radio-weekly-ranking-'.bin2hex(random_bytes(8));mkdir($wrRoot,0700);$wrFailure=null;
try{(static function()use($wrRoot):void{
    $start=wrTime('2026-10-19T00:00:00+09:00');$end=wrTime('2026-10-26T00:00:00+09:00');$due=wrTime('2026-10-26T07:00:00+09:00');$cutoff=wrTime('2026-11-02T00:00:00+09:00');
    wrSame(['week_start'=>$start,'week_end'=>$end,'eligible_at'=>$due,'expires_at'=>$cutoff],WeeklyRanking::weekWindow(new DateTimeImmutable('2026-10-25T22:00:00Z')),'UTC Sunday evening resolves the JST Monday window');
    foreach(['2024-03-04T07:00:00+09:00'=>'2024-02-26','2027-01-04T07:00:00+09:00'=>'2026-12-28','2026-02-02T07:00:00+09:00'=>'2026-01-26']as$now=>$monday){$w=WeeklyRanking::weekWindow(new DateTimeImmutable($now));wrSame(wrTime($monday.'T00:00:00+09:00'),$w['week_start'],'leap/month/year boundary uses actual Monday');wrSame(604800,$w['week_end']-$w['week_start'],'week is exactly seven JST days');}
    wrSame('weekly-ranking:'.DiscordRestClient::GUILD_ID.':'.$start,WeeklyRanking::weekKey(DiscordRestClient::GUILD_ID,$start),'stable week key contains guild and actual Monday timestamp');

    // Assignment uses scheduled_at, including opposite attended_at/count_date boundary cases.
    $f=wrFixture();$user='900000000000041001';wrSeed($f['db'],$user,[$start-1,$start,$end-1,$end],[$start,$start-1,$end,$end-1]);$before=wrHistory($f['db']);
    $snapshot=$f['ranking']->snapshot($start,$end);wrSame([['user_id'=>$user,'count'=>2,'rank'=>1]],$snapshot['entries'],'half-open scheduled week ignores attended_at and legacy count_date');
    $f['clock']->set('2026-10-25T21:59:59Z');$f['ranking']->tick();wrSame(0,count($f['gateway']->calls),'not sent one second before07JST');
    wrSeed($f['db'],'900000000000041002',[$start+100]);$f['clock']->set('2026-10-25T22:00:00Z');$f['ranking']->tick();wrSame(1,count($f['gateway']->calls),'due at exact07JST');wrPayload($f['gateway']->calls[0]['payload']);
    $saved=json_decode(wrWeek($f['db'])[0]['snapshot_json'],true,32,JSON_THROW_ON_ERROR);wrSame(2,$saved['total_participants'],'zero/non-attending participants excluded, pre-due late record included');
    $historyAfter=wrHistory($f['db']);wrSame($before['participants'][0],$historyAfter['participants'][0],'weekly ranking does not overwrite cumulative/role data');wrSame($before['attendance'],array_slice($historyAfter['attendance'],0,4),'all original attendance values remain unchanged');
    $f['ranking']->tick();$f['ranking']->tick();wrSame(1,count($f['gateway']->calls),'completed week never reposted by repeated tick');

    // Competition ranks, all fifth-place ties, exact ID mentions and deterministic tie order.
    $r=wrFixture();$users=wrCounts($r['db'],[12,10,10,8,6,6,5,0],$start);$history=wrHistory($r['db']);$s=$r['ranking']->snapshot($start,$end);
    wrSame([1,2,2,4,5,5],array_column($s['entries'],'rank'),'competition1,2,2,4 and fifth-place ties');wrSame(array_slice($users,0,6),array_column($s['entries'],'user_id'),'rank7 and zero omitted, fifth-place tie not truncated');wrSame(7,$s['total_participants'],'total excludes zero-count participant');
    wrSame([12,10,10,8,6,6],array_column($s['entries'],'count'),'participation counts do not use cumulative totals');$r['ranking']->tick();wrSame($history,wrHistory($r['db']),'ranking tick leaves all historic/cumulative/award/effect records intact');
    wrSame(array_slice($users,0,6),$r['gateway']->calls[0]['payload']['allowed_mentions']['users'],'only ranked recipients may be mentioned');wrPayload($r['gateway']->calls[0]['payload']);
    $small=wrFixture();wrCounts($small['db'],[1,1,1],$start,900000000000042000);wrSame([1,1,1],array_column($small['ranking']->snapshot($start,$end)['entries'],'rank'),'fewer than five, same rank remains same');
    $numeric=wrFixture();wrSeed($numeric['db'],'1000000000000000000',[$start+300]);wrSeed($numeric['db'],'90000000000000000',[$start+301]);wrSame(['90000000000000000','1000000000000000000'],array_column($numeric['ranking']->snapshot($start,$end)['entries'],'user_id'),'ties with different ID lengths use stable numeric ordering');
    $jump=wrFixture();wrCounts($jump['db'],[2,2,2,2,2,1],$start,900000000000043000);wrSame(5,count($jump['ranking']->snapshot($start,$end)['entries']),'five first-place ties push next competitor to rank6');
    $zero=wrFixture();$zero['db']->pdo()->prepare('INSERT INTO participants(user_id,total_count) VALUES(?,999)')->execute(['900000000000044001']);$zero['ranking']->tick();wrSame('skipped',wrWeek($zero['db'])[0]['status'],'no-participation week is durably skipped');wrSame(0,count($zero['gateway']->calls),'zero week creates no post');
    wrSeed($zero['db'],'900000000000044001',[$start+100]);$zero['ranking']->tick();wrSame(0,count($zero['gateway']->calls),'late record does not silently resurrect snapshotted skipped week');

    // Explicit OFF/guard and queue deadlines never start a ranking transport.
    $off=wrFixture();wrCounts($off['db'],[1],$start,900000000000045000);$cfg=$off['config'];unset($cfg['weekly_ranking_enabled']);(new WeeklyRanking($off['db'],$off['gateway'],$off['clock'],$cfg))->tick();wrSame(0,count($off['gateway']->calls),'ranking defaults OFF');wrSame([],wrWeek($off['db']),'OFF creates no weekly snapshot');
    $off['ranking']->tick(static fn():bool=>false);wrSame([],wrWeek($off['db']),'false first guard creates no weekly state');wrSame(0,count($off['gateway']->calls),'false guard performs no transport');
    $checks=0;$off['ranking']->tick(static function()use(&$checks):bool{return ++$checks<2;});wrSame(0,count($off['gateway']->calls),'guard changed before transport stops send');$parts=wrParts($off['db']);if($parts!==[]){wrSame(0,(int)$parts[0]['attempts'],'proven not-started guard does not consume an attempt');}

    // Large ties are all retained across bounded stable parts; one tick sends one part.
    $large=wrFixture();$largeUsers=wrCounts($large['db'],array_fill(0,250,1),$start,900000000000046000);$initial=$large['ranking']->snapshot($start,$end);$expectedParts=WeeklyRanking::parts($initial);wrCheck(count($expectedParts)>1,'large tie creates multiple parts');
    foreach($expectedParts as$payload){wrPayload($payload);wrCheck(WeeklyRanking::validPayload($payload),'builder emits valid bounded payload');}
    $large['ranking']->tick();wrSame(1,count($large['gateway']->calls),'one tick sends at most one large-tie part');$frozen=wrWeek($large['db'])[0]['snapshot_json'];
    wrSeed($large['db'],'900000000000049999',array_map(static fn(int $i):int=>$start+200000+$i,range(1,50)));
    $cfg=$large['config'];$cfg['records_channel_id']='900000000000049998';$changed=new WeeklyRanking($large['db'],$large['gateway'],$large['clock'],$cfg);
    for($i=1;$i<count($expectedParts);$i++){$beforeCount=count($large['gateway']->calls);$changed->tick();wrSame($beforeCount+1,count($large['gateway']->calls),'each subsequent tick sends exactly one saved part');}
    wrSame($frozen,wrWeek($large['db'])[0]['snapshot_json'],'late attendance cannot change saved week snapshot');wrSame($expectedParts,array_column($large['gateway']->calls,'payload'),'saved parts remain identical after late data/config change');
    wrSame([DiscordRestClient::RECORDS_CHANNEL_ID],array_values(array_unique(array_column($large['gateway']->calls,'channel'))),'retry/remaining parts retain original saved destination');
    $mentions=[];foreach($large['gateway']->calls as$call){$mentions=[...$mentions,...$call['payload']['allowed_mentions']['users']];}wrSame($largeUsers,$mentions,'all250 tied recipients appear once in stable ID order');
    $changed->tick();wrSame(count($expectedParts),count($large['gateway']->calls),'large week completed, no duplicate part');
    foreach($large['gateway']->calls as$i=>$call){wrSame(WeeklyRanking::weekKey(DiscordRestClient::GUILD_ID,$start).':part:'.($i+1),$call['key'],'one-based part key stable');}

    $good=$r['gateway']->calls[0]['payload'];$invalid=[];$bad=$good;$bad['tts']=true;$invalid[]=$bad;$bad=$good;$bad['allowed_mentions']['parse']=['everyone'];$invalid[]=$bad;
    $bad=$good;$bad['allowed_mentions']['roles']=['900000000000044444'];$invalid[]=$bad;$bad=$good;$bad['allowed_mentions']['replied_user']=true;$invalid[]=$bad;
    $bad=$good;$bad['allowed_mentions']['users'][]='900000000000044444';$invalid[]=$bad;$bad=$good;$bad['content'].=' <@900000000000044444>';$invalid[]=$bad;
    $bad=$good;$bad['content'].=str_repeat('x',2001);$invalid[]=$bad;$bad=$good;$bad['embeds']=[];$invalid[]=$bad;
    foreach($invalid as$payload){wrSame(false,WeeklyRanking::validPayload($payload),'malformed/mass/unlisted/oversize payload refused');}

    // Only proven no-send outcomes have bounded retries; ambiguous delivery is terminal automatically.
    $retry=wrFixture();wrCounts($retry['db'],[1],$start,900000000000047000);$retry['gateway']->outcomes=[TransportOutcome::NotStarted,TransportOutcome::Rejected,TransportOutcome::Succeeded];
    $retry['ranking']->tick();$retry['ranking']->tick();wrSame(1,count($retry['gateway']->calls),'known no-send retries respect persisted backoff');wrSame(1,(int)wrParts($retry['db'])[0]['attempts'],'backoff tick does not consume another attempt');$retry['clock']->advance(3600);
    for($i=0;$i<3;$i++){$retry['ranking']->tick();$retry['clock']->advance(3600);}wrSame(3,count($retry['gateway']->calls),'known no-send retries reach success only once');wrSame('sent',wrParts($retry['db'])[0]['status'],'confirmed success persists');
    $finite=wrFixture();wrCounts($finite['db'],[1],$start,900000000000048000);$finite['gateway']->outcomes=array_fill(0,8,TransportOutcome::NotStarted);
    for($i=0;$i<8;$i++){$finite['ranking']->tick();$finite['clock']->advance(3600);}wrSame(5,count($finite['gateway']->calls),'known no-send maximum is five attempts');wrSame('failed',wrParts($finite['db'])[0]['status'],'exhaustion retained as failed');
    foreach([TransportOutcome::Uncertain,new RuntimeException('FICTIONAL_AFTER_SEND_EXCEPTION'),new RestResult(true,'missing_id',200),new RestResult(true,'wrong_channel',200,['id'=>'900000000000061001','channel_id'=>'900000000000061002'],outcome:TransportOutcome::Succeeded)]as$outcome){$uncertain=wrFixture();wrCounts($uncertain['db'],[1],$start,900000000000050000);$uncertain['gateway']->outcomes=[$outcome];$uncertain['ranking']->tick();
        $uncertain['clock']->advance(3600);(new WeeklyRanking($uncertain['db'],$uncertain['gateway'],$uncertain['clock'],$uncertain['config']))->tick();wrSame(1,count($uncertain['gateway']->calls),'ambiguous/throw/missing receipt never automatically repost');wrCheck(in_array(wrParts($uncertain['db'])[0]['status'],['hold','sending'],true),'ambiguous delivery visibly held');}
    $crash=wrFixture();wrCounts($crash['db'],[1],$start,900000000000051000);$crash['gateway']->outcomes=[TransportOutcome::NotStarted];$crash['ranking']->tick();$crash['db']->pdo()->exec("UPDATE weekly_ranking_parts SET status='sending',attempts=1,claim_owner='fictional-dead-worker'");$crash['clock']->advance(86400);$crash['ranking']->tick();wrSame(1,count($crash['gateway']->calls),'lease expiry never reclaims possibly sent part');
    $save=wrFixture();wrCounts($save['db'],[1],$start,900000000000052000);$save['gateway']->hook=static function()use($save):void{$save['db']->pdo()->exec("CREATE TRIGGER fictional_sent_save_failure BEFORE UPDATE OF status ON weekly_ranking_parts WHEN NEW.status='sent' BEGIN SELECT RAISE(ABORT,'fictional_save_failure'); END");};$save['ranking']->tick();
    $save['gateway']->hook=null;$save['db']->pdo()->exec('DROP TRIGGER fictional_sent_save_failure');$save['clock']->advance(3600);$save['ranking']->tick();wrSame(1,count($save['gateway']->calls),'successful transport with failed persistence cannot repeat mention');wrCheck(in_array(wrParts($save['db'])[0]['status'],['hold','sending'],true),'save failure remains held');

    // Catchup is only the last completed week; retained old rows are ineligible at next Monday00.
    $late=wrFixture('2026-10-28T15:00:00+09:00');wrCounts($late['db'],[1],$start,900000000000053000);wrCounts($late['db'],[9],$start-604800,900000000000054000);$late['ranking']->tick();$lateSnapshot=json_decode(wrWeek($late['db'])[0]['snapshot_json'],true,32,JSON_THROW_ON_ERROR);wrSame($start,$lateSnapshot['week_start'],'long downtime selects only immediately preceding week');wrSame(1,$lateSnapshot['entries'][0]['count'],'older high counts not mixed into catchup');wrSame(1,count($late['gateway']->calls),'no old-week burst');
    $expiry=wrFixture();wrCounts($expiry['db'],[1],$start,900000000000055000);$expiry['gateway']->outcomes=[TransportOutcome::NotStarted];$expiry['ranking']->tick();$expiry['clock']->set('2026-11-02T00:00:00+09:00');$expiry['ranking']->tick();wrSame(1,count($expiry['gateway']->calls),'next Monday00 is too late to retry prior pending week');

    // Four real local workers claim the same single-part week once.
    $raceDb=new Database($wrRoot.'/race.sqlite');wrCounts($raceDb,[1],$start,900000000000056000);$children=[];
    for($i=0;$i<4;$i++){$args=[PHP_BINARY];if(($ini=php_ini_loaded_file())!==false){array_push($args,'-c',$ini);}array_push($args,'-d','memory_limit=128M',__FILE__,'--weekly-child',$wrRoot.'/race.sqlite');$pipes=[];$process=proc_open($args,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);wrCheck(is_resource($process),'launch isolated ranking worker');fclose($pipes[0]);$children[]=[$process,$pipes];}
    $sends=0;foreach($children as[$process,$pipes]){$out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);wrSame(0,proc_close($process),'race child exit');wrSame('',$error,'race child warning-free');wrCheck(preg_match('/\A[01]\z/D',$out)===1,'race child bounded result');$sends+=(int)$out;}wrSame(1,$sends,'four workers make one ranking attempt');wrSame('sent',wrParts($raceDb)[0]['status'],'shared part records single completion');
    $busyDb=new Database($wrRoot.'/busy.sqlite',0);$busy=wrFixture(db:$busyDb);wrCounts($busyDb,[1],$start,900000000000057000);$lock=new PDO('sqlite:'.$wrRoot.'/busy.sqlite');$lock->exec('BEGIN IMMEDIATE');try{$busy['ranking']->tick();wrSame(0,count($busy['gateway']->calls),'busy DB defers before any ranking transport');}finally{$lock->exec('ROLLBACK');unset($lock);}

    // Real REST/outbox boundary, including source changes after its first ledger check.
    $post=wrClaimed($start);$client=new ServerOperationsClient($post['db'],$post['http'],'FICTIONAL_WEEKLY_BOT_TOKEN',$post['options']);$response=$client->postWeeklyRanking($post['payload'],$post['key']);
    wrSame(true,$response->ok,'concrete weekly POST confirmed');wrSame('900000000000062001',$response->data['id'],'concrete POST normalizes confirmed message ID for core ledger');wrSame(1,count($post['http']->calls),'concrete weekly part sends once');
    $wire=json_decode($post['http']->calls[0]->body(),true,32,JSON_THROW_ON_ERROR);wrSame(true,$wire['enforce_nonce'],'weekly outbox enforces stable nonce');wrSame(substr(hash('sha256',$post['key']),0,25),$wire['nonce'],'weekly nonce is stable by part key');unset($wire['nonce'],$wire['enforce_nonce']);wrSame($post['payload'],$wire,'concrete transport preserves exact fixed mentions and content');
    $again=new ServerOperationsClient($post['db'],$post['http'],'FICTIONAL_WEEKLY_BOT_TOKEN',$post['options']);$again->postWeeklyRanking($post['payload'],$post['key']);wrSame(1,count($post['http']->calls),'another client cannot repost a sent outbox part');
    $bad=$post['payload'];$bad['content'].=' changed';$again->postWeeklyRanking($bad,$post['key']);wrSame(1,count($post['http']->calls),'same part key refuses changed payload');
    foreach(['enabled','posts_enabled','weekly_ranking_enabled','missing_weekly_flag']as$flag){$gated=wrClaimed($start);$options=$gated['options'];if($flag==='missing_weekly_flag'){unset($options['weekly_ranking_enabled']);}else{$options[$flag]=false;}
        $result=(new ServerOperationsClient($gated['db'],$gated['http'],'FICTIONAL_WEEKLY_BOT_TOKEN',$options))->postWeeklyRanking($gated['payload'],$gated['key']);wrSame(false,$result->ok,'every concrete OFF gate refuses post');wrSame(0,count($gated['http']->calls),'concrete OFF gate is HTTP-free');}
    foreach(['expiry','claim_changed','hold']as$change){$latePost=wrClaimed($start);$options=$latePost['options'];$guardCalls=0;
        $options['request_guard']=static function()use(&$guardCalls,$change,$latePost,$cutoff):bool{if(++$guardCalls===2){if($change==='expiry'){$latePost['clock']->set((new DateTimeImmutable('@'.$cutoff))->format(DATE_ATOM));}elseif($change==='claim_changed'){$latePost['db']->pdo()->exec("UPDATE weekly_ranking_parts SET claim_owner='".str_repeat('b',32)."'");}else{$latePost['db']->pdo()->exec("UPDATE weekly_ranking_parts SET status='hold'");}}return true;};
        $result=(new ServerOperationsClient($latePost['db'],$latePost['http'],'FICTIONAL_WEEKLY_BOT_TOKEN',$options))->postWeeklyRanking($latePost['payload'],$latePost['key']);
        wrCheck($guardCalls>=2,'late boundary mutation occurs after first scope read');wrSame(TransportOutcome::NotStarted,$result->outcome,$change.' is known not-started at final boundary');wrSame(0,count($latePost['http']->calls),$change.' before inner HTTP prevents all transport');}

    // Normal production composition: signed ACK,07JST participation/card and closure remain separate.
    $normal=$wrRoot.'/normal';mkdir($normal,0700);mkdir($normal.'/private',0700);mkdir($normal.'/public',0700);
    $cfg=runtimeFixtureConfig($normal,'manual');$cfg['weekly_ranking_enabled']=true;$cfg['font_candidates']=\RadioStamp\Validation\OneJoin\Trial::fonts();
    $clock=new FrozenClock('2026-10-26T07:00:00+09:00');$http=new RuntimeFixtureHttp();$runtime=new OperationsRuntime($http,fn()=>$http->transport(),$clock);$context=$runtime->build($cfg,static fn():bool=>true);
    wrCheck($context->weeklyRanking instanceof WeeklyRanking,'normal runtime constructs the ranking only as a separate capability');
    wrCounts($context->db,[2],$start,900000000000058000);$owner='800000000000001021';$other='900000000000000102';$http->voices=[$owner=>DiscordRestClient::VOICE_CHANNEL_ID,$other=>DiscordRestClient::VOICE_CHANNEL_ID];
    $path=$normal.'/private/config.php';file_put_contents($path,"<?php\nreturn ".var_export($cfg,true).";\n");
    $driver=new class implements PostResponseDriver {public function available():bool{return true;}public function finish():bool{return true;}};
    $send=static function(string $name,string $user,string $kind='command')use($clock,$runtime,$normal,$path,$driver):void{static $id=900000000000059000;$data=['id'=>(string)++$id,'application_id'=>DiscordRestClient::APPLICATION_ID,'guild_id'=>DiscordRestClient::GUILD_ID,'channel_id'=>DiscordRestClient::VOICE_CHANNEL_ID,'token'=>'FICTIONAL_WEEKLY_CALLBACK_TOKEN','type'=>$kind==='button'?3:2,'member'=>['user'=>['id'=>$user,'username'=>'FICTIONAL_WEEKLY_OWNER']],
        'data'=>$kind==='button'?['component_type'=>2,'custom_id'=>'radio:attend:2026-10-26_0700']:['type'=>1,'name'=>$name]];$raw=json_encode($data,JSON_THROW_ON_ERROR);$stamp=(string)$clock->now()->getTimestamp();$keys=sodium_crypto_sign_seed_keypair(str_repeat("\x17",32));
        $delivery=(new OperationsEndpoint($normal.'/public',$driver,$runtime))->prepare(['REQUEST_METHOD'=>'POST','HTTP_X_SIGNATURE_TIMESTAMP'=>$stamp,'HTTP_X_SIGNATURE_ED25519'=>bin2hex(sodium_crypto_sign_detached($stamp.$raw,sodium_crypto_sign_secretkey($keys)))],$raw,$path);
        wrSame(200,$delivery->response->status,'normal signed interaction accepted');wrSame(64,$delivery->response->body['data']['flags'],'normal ACK remains private');wrSame(true,$delivery->deliver($driver,static function(Response $response):void{}),'normal after-ACK work completes');};
    $send('radio-attend',$owner);$send('',$owner,'button');$send('',$owner,'button');$send('radio-card',$owner);wrSame(1,(int)$context->db->pdo()->query('SELECT COUNT(*) FROM attendance WHERE user_id=\''.$owner.'\'')->fetchColumn(),'normal duplicate click counts once');
    $rankingMessages=static fn():array=>array_values(array_filter($http->messages,static fn(array $m):bool=>$m['channel_id']===DiscordRestClient::RECORDS_CHANNEL_ID));
    wrSame(0,count($rankingMessages()),'afterResponse never emits weekly ranking');wrCheck($http->callbacks[array_key_last($http->callbacks)]['png'],'normal private card remains PNG');
    $runtime->cycle($context,static fn():bool=>true);wrSame(1,count($rankingMessages()),'normal cycle sends the prior week after current session/queue work');$published=json_decode(wrWeek($context->db)[0]['snapshot_json'],true,32,JSON_THROW_ON_ERROR);wrSame('900000000000058001',$published['entries'][0]['user_id'],'Monday07 participant never enters ended week');
    $clock->advance(540);$runtime->cycle($context,static fn():bool=>true);$session=$context->db->pdo()->query("SELECT * FROM sessions WHERE id='2026-10-26_0700'")->fetch();wrSame($clock->now()->getTimestamp(),(int)$session['warning_sent_at'],'normal nine-minute warning remains on time');
    $clock->advance(60);$runtime->cycle($context,static fn():bool=>true);wrSame('closed',$context->db->pdo()->query("SELECT status FROM sessions WHERE id='2026-10-26_0700'")->fetchColumn(),'normal ten-minute closure remains on time');wrSame(1,count($rankingMessages()),'ordinary cycles do not repeat ranking');wrSame(0,count($http->memberPatches()),'manual coexistence never moves or disconnects members');$context->roster->close();
    wrSame('pending',wrParts($expiry['db'])[0]['status'],'expired pending row remains preserved as history without replay');
})();}catch(Throwable $error){$wrFailure=$error->getMessage();}
finally{gc_collect_cycles();$owned=realpath($wrRoot);$temporary=realpath(sys_get_temp_dir());if($owned!==false&&$temporary!==false&&dirname($owned)===$temporary&&str_starts_with(basename($owned),'radio-weekly-ranking-')){$remove=static function(string $dir)use(&$remove):void{foreach(new DirectoryIterator($dir)as$entry){if($entry->isDot()){continue;}if($entry->isLink()){unlink($entry->getPathname());}elseif($entry->isDir()){$remove($entry->getPathname());rmdir($entry->getPathname());}else{unlink($entry->getPathname());}}};$remove($owned);rmdir($owned);}}
if($wrFailure!==null){fwrite(STDERR,$wrFailure."\n");exit(1);}echo 'Weekly ranking: '.$wrChecks.' assertions passed (fictional HTTP/clock, isolated SQLite and normal runtime only; no live changes).'."\n";
