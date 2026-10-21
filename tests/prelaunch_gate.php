<?php
declare(strict_types=1);
require_once __DIR__.'/artifact_directory.php';
require dirname(__DIR__).'/bootstrap.php';
require __DIR__.'/fixtures/OperationsRuntimeFixture.php';

use RadioStamp\Core\{Database,FrozenClock,Schedule,StampPolicy};
use RadioStamp\Discord\DiscordRestClient;
use RadioStamp\Http\{OperationsEndpoint,PostResponseDriver};
use RadioStamp\Operations\{LaunchGate,OperationsCli,OperationsRuntime};

$pgChecks=0;
function pgSame(mixed $expected,mixed $actual,string $why):void{global$pgChecks;$pgChecks++;if($expected!==$actual){throw new RuntimeException('Prelaunch gate: '.$why);}}
function pgStamp(string $at):int{return(new DateTimeImmutable($at,new DateTimeZone('Asia/Tokyo')))->getTimestamp();}
function pgThrows(callable $call,string $why):void{$thrown=false;try{$call();}catch(Throwable){$thrown=true;}pgSame(true,$thrown,$why);}
$pgRoot=sys_get_temp_dir().'/radio-prelaunch-'.bin2hex(random_bytes(8));mkdir($pgRoot,0700);$pgFailure=null;$pgContexts=[];
$pgFixture=static function(string $name)use($pgRoot):array{$root=$pgRoot.'/'.$name;mkdir($root,0700);mkdir($root.'/private',0700);mkdir($root.'/public',0700);return['root'=>$root,'public'=>$root.'/public','path'=>$root.'/private/config.php','gate'=>$root.'/private/launch-gate.json','marker'=>$root.'/private/provider-loaded','db'=>$root.'/private/application.sqlite'];};
$pgWriteGate=static function(array$f,string$first='2026-10-24 04:00'):void{file_put_contents($f['gate'],json_encode(['version'=>1,'first_session_at'=>$first],JSON_THROW_ON_ERROR));chmod($f['gate'],0600);};
try{(static function()use($pgFixture,$pgWriteGate,&$pgContexts):void{
    $spec=Schedule::DEFAULT_SPEC+['not_before'=>'2026-10-24 04:00'];$schedule=new Schedule($spec);$first=pgStamp('2026-10-24 04:00');
    pgSame($first,$schedule->firstSessionAt(),'exact first scheduled time');pgSame(false,$schedule->contains('2026-10-24_0000'),'same-date midnight excluded');
    pgSame(false,$schedule->contains('2026-10-23_2200'),'earlier day excluded');pgSame(true,$schedule->contains('2026-10-24_0400'),'first included');
    pgSame(null,$schedule->initialSessionAt($first-1801),'no initial session one second before opening');pgSame('2026-10-24_0400',$schedule->initialSessionAt($first-1800),'first opens exactly thirty minutes early');
    pgSame('dawn',$schedule->slotForSession('2026-10-24_0400'),'first preserves dawn logical slot');
    $dates=array_map(static fn(DateTimeImmutable$at):string=>$at->format('Y-m-d H:i'),$schedule->between(new DateTimeImmutable('2026-10-24 00:00',new DateTimeZone('Asia/Tokyo')),new DateTimeImmutable('2026-10-25 00:00',new DateTimeZone('Asia/Tokyo'))));
    pgSame(['2026-10-24 04:00','2026-10-24 07:00','2026-10-24 12:30','2026-10-24 22:00','2026-10-25 00:00'],$dates,'all following five slots and next midnight retained');
    pgSame(true,(new Schedule())->contains('2026-10-24_0000'),'legacy schedule unchanged without gate');
    foreach([null,'2026-02-30 04:00','2026-10-24 4:00','2026-10-24 04:00 UTC',0]as$bad){pgThrows(static fn()=>new Schedule(Schedule::DEFAULT_SPEC+['not_before'=>$bad]),'invalid gate rejected');}

    $f=$pgFixture('entry');$pgWriteGate($f);$cfg=runtimeFixtureConfig($f['root'],'manual');$cfg['schedule']=$spec;
    $source="<?php\nfile_put_contents(".var_export($f['marker'],true).",'loaded');\nreturn ".var_export($cfg,true).";\n";file_put_contents($f['path'],$source);
    $clock=new FrozenClock('2026-10-24T03:29:59+09:00');$http=new RuntimeFixtureHttp();$runtime=new OperationsRuntime($http,fn()=>$http->transport(),$clock);
    $driver=new class implements PostResponseDriver{public function available():bool{return true;}public function finish():bool{return true;}};
    $endpoint=new OperationsEndpoint($f['public'],$driver,$runtime);$response=$endpoint->prepare(['REQUEST_METHOD'=>'POST'],'{}',$f['path']);
    pgSame(503,$response->response->status,'HTTP disabled before wall gate');pgSame(['error'=>'operations_not_started'],$response->response->body,'coarse early HTTP result');
    pgSame(false,is_file($f['marker']),'HTTP does not execute provider before opening');pgSame(false,is_file($f['db']),'HTTP does not open/create DB before opening');pgSame([], $http->calls,'HTTP makes no transport call before opening');
    foreach(['--run','--once','--register','--dry-run','--backup','--restore=fictional']as$action){$out='';$exit=(new OperationsCli($runtime))->run([$action,'--config='.$f['path'],'--public-root='.$f['public']],static function(string$text)use(&$out):void{$out.=$text;});pgSame(1,$exit,'CLI action blocked');pgSame('operations_not_started',json_decode($out,true)['code'],'CLI reports not started');}
    pgSame(false,is_file($f['marker']),'all CLI actions avoid provider before opening');pgSame(false,is_file($f['db']),'all CLI actions avoid DB before opening');pgSame([], $http->calls,'CLI avoids transport before opening');
    $gate=LaunchGate::read($f['path'],$f['public']);pgSame(false,$gate->permits($clock),'gate closed one second early');$clock->advance(1);pgSame(true,$gate->permits($clock),'gate opens exactly 03:30');pgSame(true,$gate->matches($cfg),'sidecar matches schedule');
    $badCfg=$cfg;$badCfg['schedule']=Schedule::DEFAULT_SPEC;pgSame(false,$gate->matches($badCfg),'missing schedule bound rejected after load');
    $out='';pgSame(0,(new OperationsCli($runtime))->run(['--dry-run','--config='.$f['path'],'--public-root='.$f['public']],static function(string$text)use(&$out):void{$out.=$text;}),'configuration loads at boundary');pgSame(true,is_file($f['marker']),'provider runs only once eligible');pgSame(false,is_file($f['db']),'dry-run still creates no DB');
    $keys=sodium_crypto_sign_seed_keypair(str_repeat("\x17",32));$body=json_encode(['type'=>1,'application_id'=>DiscordRestClient::APPLICATION_ID],JSON_THROW_ON_ERROR);$stamp=(string)$clock->now()->getTimestamp();
    $response=$endpoint->prepare(['REQUEST_METHOD'=>'POST','HTTP_X_SIGNATURE_TIMESTAMP'=>$stamp,'HTTP_X_SIGNATURE_ED25519'=>bin2hex(sodium_crypto_sign_detached($stamp.$body,sodium_crypto_sign_secretkey($keys)))],$body,$f['path']);pgSame(200,$response->response->status,'signed HTTP ping works at boundary');pgSame(['type'=>1],$response->response->body,'normal signed path preserved');pgSame([], $http->calls,'ping does not cause transport');
    $stopped=$cfg;$stopped['operations_enabled']=false;file_put_contents($f['path'],"<?php\nreturn ".var_export($stopped,true).";\n");
    pgSame(503,$endpoint->prepare(['REQUEST_METHOD'=>'POST'],'{}',$f['path'])->response->status,'existing activation OFF stops new HTTP after launch');$out='';pgSame(1,(new OperationsCli($runtime))->run(['--once','--config='.$f['path'],'--public-root='.$f['public']],static function(string$text)use(&$out):void{$out.=$text;}),'existing activation OFF stops new CLI after launch');pgSame(false,is_file($f['db']),'OFF cannot create DB');pgSame([], $http->calls,'OFF cannot perform transport');
    file_put_contents($f['gate'],'{"version":1,"first_session_at":"not a time"}');pgThrows(static fn()=>LaunchGate::read($f['path'],$f['public']),'malformed sidecar fails closed');file_put_contents($f['gate'],str_repeat('x',513));pgThrows(static fn()=>LaunchGate::read($f['path'],$f['public']),'oversized sidecar fails closed');
    $legacy=$pgFixture('legacy');pgSame(null,LaunchGate::read($legacy['path'],$legacy['public']),'missing sidecar preserves legacy configs');

    $g=$pgFixture('cycle');$clock=new FrozenClock('2026-10-24T03:29:59+09:00');$http=new RuntimeFixtureHttp();$http->allow='0';$http->deny='1049600';$cfg=runtimeFixtureConfig($g['root'],'manual');$cfg['schedule']=$spec;$cfg['room_baseline']=['allow'=>'0','deny'=>'1049600'];
    $db=new Database($cfg['private_db_path']);$p=$db->pdo();$old=pgStamp('2026-10-24 00:00');$p->prepare("INSERT INTO sessions(id,scheduled_at,count_date,opens_at,unstarted_closes_at,status,slot_id,stamp) VALUES(?,?,?,?,?,'planned','midnight',?)")->execute(['2026-10-24_0000',$old,'2026-10-24',$old-1800,$old+1800,StampPolicy::stampForSlot('midnight')]);
    $p->prepare("INSERT INTO core_effects(effect_key,kind,payload_json,status,attempts,lease_until,expires_at) VALUES('old-fixture','reminder',?,'pending',0,0,?)")->execute([json_encode(['session_id'=>'2026-10-24_0000']),$old]);
    $oldRow=$p->query("SELECT * FROM sessions WHERE id='2026-10-24_0000'")->fetch(PDO::FETCH_ASSOC);$oldEffect=$p->query("SELECT * FROM core_effects WHERE effect_key='old-fixture'")->fetch(PDO::FETCH_ASSOC);
    $runtime=new OperationsRuntime($http,fn()=>$http->transport(),$clock);$context=$runtime->build($cfg,static fn():bool=>true);$pgContexts[]=$context;
    $context->service->tick();pgSame([], $http->calls,'service tick has no I/O before first opening');pgSame(false,$context->service->scheduledWorkDueBy($first-1801),'retained old row cannot cause polling');
    pgSame('session_before_launch',$context->service->start('2026-10-24_0000','800000000000001021','fictional-old-start')['code'],'manual old start rejected before presence read');pgSame('session_before_launch',$context->service->attend('2026-10-24_0000','800000000000001021','fictional-old-attend')['code'],'old button rejected before presence read');pgSame([], $http->calls,'old IDs cannot trigger transport');
    foreach(['start','attend']as$op){$id='old-'.$op;$p->prepare("INSERT INTO core_interactions(id,operation,session_id,user_id,result_json,created_at) VALUES(?,?, '2026-10-24_0000','800000000000001021',?,?)")->execute([$id,$op,json_encode(['ok'=>true,'session_id'=>'2026-10-24_0000']),$old]);pgSame('session_before_launch',$context->service->{$op}('2026-10-24_0000','800000000000001021',$id)['code'],'old successful '.$op.' replay cannot bypass gate');}
    $p->prepare("INSERT INTO core_interactions(id,operation,session_id,user_id,result_json,created_at) VALUES('old-receipt','receipt','2026-10-24_0000','800000000000001021',?,?)")->execute([json_encode(['ok'=>true,'session_id'=>'2026-10-24_0000']),$old]);
    pgSame('session_before_launch',$context->service->openAttendance('800000000000001021','old-receipt')['code'],'successful old receipt replay cannot flush old announcement');
    $clock->advance(1);$runtime->cycle($context,static fn():bool=>true);pgSame(true,$context->service->scheduledWorkDueBy($first-1800),'first slot due at 03:30');
    $rooms=array_values(array_filter($http->calls,static fn($request):bool=>$request->method()==='PUT'&&str_contains($request->url(),'/permissions/')));pgSame(1,count($rooms),'first room opens at 03:30');
    $notices=static fn():array=>array_values(array_filter($http->messages,static fn(array$m):bool=>$m['channel_id']===DiscordRestClient::TEXT_CHANNEL_ID&&str_contains($m['content']??'','VCは開いているよ')));
    pgSame(0,count($notices()),'no notification at opening');$clock->set('2026-10-24T03:49:59+09:00');$runtime->cycle($context,static fn():bool=>true);pgSame(0,count($notices()),'no notification one second early');
    $clock->advance(1);$runtime->cycle($context,static fn():bool=>true);pgSame(1,count($notices()),'one notice at 03:50');pgSame([], $notices()[0]['allowed_mentions']['parse'],'first dawn notification has no ping');pgSame(false,str_contains($notices()[0]['content'],'@here'),'first dawn content has no here');
    $runtime->cycle($context,static fn():bool=>true);pgSame(1,count($notices()),'duplicate cycle does not repeat notice');
    pgSame($oldRow,$p->query("SELECT * FROM sessions WHERE id='2026-10-24_0000'")->fetch(PDO::FETCH_ASSOC),'old planned row preserved verbatim');pgSame($oldEffect,$p->query("SELECT * FROM core_effects WHERE effect_key='old-fixture'")->fetch(PDO::FETCH_ASSOC),'old pending effect preserved verbatim');
    pgSame(0,count($http->memberPatches()),'no member movement/cut in gate test');
})();}catch(Throwable$error){$pgFailure=$error->getMessage();}
finally{foreach($pgContexts as$context){$context->roster->close();}unset($context);$pgContexts=[];gc_collect_cycles();$owned=realpath($pgRoot);$temporary=realpath(sys_get_temp_dir());
    if($owned!==false&&$temporary!==false&&dirname($owned)===$temporary&&str_starts_with(basename($owned),'radio-prelaunch-')){$remove=static function(string$dir)use(&$remove):void{foreach(new DirectoryIterator($dir)as$entry){if($entry->isDot()){continue;}if($entry->isLink()){unlink($entry->getPathname());}elseif($entry->isDir()){$remove($entry->getPathname());rmdir($entry->getPathname());}else{unlink($entry->getPathname());}}};$remove($owned);rmdir($owned);}}
if($pgFailure!==null){fwrite(STDERR,$pgFailure."\n");exit(1);}echo 'Prelaunch gate: '.$pgChecks.' assertions passed (fictional clock/HTTP, isolated SQLite; no live operations).' ."\n";
