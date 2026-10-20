<?php
declare(strict_types=1);
namespace RadioStamp\Cards {
    /** Fault injection stays in the test process; production still calls the same native GD API. */
    function imagepng(\GdImage $image):bool
    {
        $GLOBALS['capacity_png_calls']=($GLOBALS['capacity_png_calls']??0)+1;
        if(($GLOBALS['capacity_png_hook']??null) instanceof \Closure){($GLOBALS['capacity_png_hook'])();}
        return match($GLOBALS['capacity_png_fault']??null){
            'false'=>false,
            'throw'=>throw new \RuntimeException('CAPACITY_CANARY_PNG_EXCEPTION /private/secret'),
            'warning'=>capacityWarning(),
            'attachment_oversize'=>capacityAttachmentOversize(),
            default=>\imagepng($image),
        };
    }
    function capacityWarning():bool{trigger_error('CAPACITY_CANARY_PNG_WARNING /private/secret',E_USER_WARNING);return false;}
    function capacityAttachmentOversize():bool{echo "\x89PNG\r\n\x1a\n".str_repeat("\0",1048577-8);return true;}
}
namespace {
require_once __DIR__.'/artifact_directory.php';
require dirname(__DIR__).'/bootstrap.php';
require __DIR__.'/fixtures/OperationsRuntimeFixture.php';

use RadioStamp\Cards\CardRenderer;
use RadioStamp\Core\{FrozenClock,RadioService,Schedule};
use RadioStamp\Discord\DiscordRestClient;
use RadioStamp\Http\{OperationsEndpoint,PostResponseDriver,Response,InteractionWorker,MockFollowupSink};
use RadioStamp\Operations\{OperationsConfig,OperationsRuntime,SafeDiagnostics};

$checks=0;$scenarios=[];$fixtures=[];$sequence=0;$exceptionLogs='';
$same=static function(mixed $expected,mixed $actual,string $why)use(&$checks):void{++$checks;if($expected!==$actual){throw new RuntimeException($why.' expected='.json_encode($expected).' actual='.json_encode($actual));}};
$assert=static function(bool $ok,string $why)use($same):void{$same(true,$ok,$why);};
$fonts=['/usr/share/fonts/ipa-gothic/ipag.ttf','/usr/share/fonts/ipa-pgothic/ipagp.ttf','/usr/share/fonts/ja/TrueType/kochi-gothic-subst.ttf','/usr/share/fonts/vlgothic/VL-Gothic-Regular.ttf','/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc','/usr/share/fonts/ipaexg/ipaexg.ttf','C:/Windows/Fonts/BIZ-UDGothicR.ttc','C:/Windows/Fonts/msgothic.ttc'];
$renderer=new CardRenderer($fonts);
$png=static function(string $bytes,?int $height,string $why)use($assert,$same):string{
    $assert(str_starts_with($bytes,"\x89PNG\r\n\x1a\n"),$why.' PNG signature');$image=imagecreatefromstring($bytes);
    $assert($image instanceof GdImage,$why.' decodes');
    try{$same(1200,imagesx($image),$why.' width');
        if($height===null){$assert(imagesy($image)>1250&&imagesy($image)<=10000&&strlen($bytes)<=8_000_000,$why.' bounded expanded detail PNG');}
        else{$same($height,imagesy($image),$why.' height');}
        $same(0xffffff,imagecolorat($image,0,0),$why.' plain white canvas');}finally{imagedestroy($image);}
    foreach(['CAPACITY_CANARY','/private/','C:/Windows','/usr/share/fonts']as$secret){$same(false,str_contains($bytes,$secret),$why.' no private PNG metadata');}
    return hash('sha256',$bytes);
};
$invalid=static function(array $data,string $why,?int $milestone=null)use($renderer,$assert,$same):void{
    ob_start();$rejected=false;
    try{try{$milestone===null?$renderer->monthly($data):$renderer->memorial($data,$milestone);}catch(InvalidArgumentException $error){$rejected=true;$same(false,str_contains($error->getMessage(),'/'),$why.' no path in error');}}
    finally{$same('',ob_get_clean(),$why.' no warning/output');}
    $assert($rejected,$why.' rejected');
};
final class CapacityDriver implements PostResponseDriver{public function available():bool{return true;}public function finish():bool{return true;}}
$user='900000000000000101';$times=['00:00','04:00','07:00','12:30','22:00'];
$root=str_replace('\\','/',sys_get_temp_dir().'/radio-card-capacity-'.bin2hex(random_bytes(8)));mkdir($root,0700);
$make=static function(string $label,array $schedule)use($root,&$fixtures,$user):array{
    $dir=$root.'/'.$label;mkdir($dir,0700);mkdir($dir.'/private',0700);mkdir($dir.'/public',0700);
    $config=runtimeFixtureConfig($dir,'best_effort',true);
    $config=array_replace($config,['participant_scope'=>'guild','participant_user_ids'=>[],'schedule'=>$schedule,'live_automation_enabled'=>true,'command_registration_enabled'=>false]);
    $path=$dir.'/private/config.php';file_put_contents($path,"<?php\nreturn ".var_export($config,true).";\n");
    $clock=new FrozenClock('2026-10-20T00:00:00+09:00');$http=new RuntimeFixtureHttp();$http->voices=[$user=>DiscordRestClient::VOICE_CHANNEL_ID];
    $runtime=new OperationsRuntime($http,fn()=>$http->transport(),$clock);$context=$runtime->build($config,static fn():bool=>true);
    $f=compact('dir','config','path','clock','http','runtime','context');$fixtures[]=$f;return $f;
};
$send=static function(array $f,string $name,?array $replay=null)use(&$sequence,$user,$same):array{
    $component=str_starts_with($name,'radio:');
    $payload=$replay??['id'=>(string)(900000000000030000+ ++$sequence),'application_id'=>DiscordRestClient::APPLICATION_ID,'guild_id'=>DiscordRestClient::GUILD_ID,
        'channel_id'=>DiscordRestClient::VOICE_CHANNEL_ID,'token'=>'CAPACITY_CANARY_CALLBACK','type'=>$component?3:2,
        'member'=>['permissions'=>'0','nick'=>'架空の容量試験','user'=>['id'=>$user,'username'=>'CAPACITY_CANARY_NAME']],
        'data'=>$component?['component_type'=>2,'custom_id'=>$name]:['type'=>1,'name'=>$name]];
    $body=json_encode($payload,JSON_THROW_ON_ERROR);$timestamp=(string)$f['clock']->now()->getTimestamp();$keys=sodium_crypto_sign_seed_keypair(str_repeat("\x17",32));
    $server=['REQUEST_METHOD'=>'POST','HTTP_X_SIGNATURE_TIMESTAMP'=>$timestamp,'HTTP_X_SIGNATURE_ED25519'=>bin2hex(sodium_crypto_sign_detached($timestamp.$body,sodium_crypto_sign_secretkey($keys)))];
    $driver=new CapacityDriver();$before=count($f['http']->calls);$delivery=(new OperationsEndpoint($f['dir'].'/public',$driver,$f['runtime']))->prepare($server,$body,$f['path']);
    $same($before,count($f['http']->calls),'capacity ingress performs no HTTP before ACK');$same(200,$delivery->response->status,'capacity signed ingress accepted');
    $same(true,$delivery->deliver($driver,static fn(Response $response)=>null),'capacity ACK finish delivered');
    $q=$f['context']->db->pdo()->prepare('SELECT state,result_code,attempts,token_ciphertext FROM interaction_jobs WHERE interaction_id=?');$q->execute([$payload['id']]);$job=$q->fetch();$q->closeCursor();
    return [$job,$payload];
};

try{
    // Monthly stamp art intentionally changes; temporary day-based memorials stay identical.
    // Keep a same-runtime comparison without modifying any frozen baseline or archived art.
    $beforePath=__DIR__.'/fixtures/CardRenderer-before-five-slot.php';
    $beforeSource=file_get_contents($beforePath);$baselineHash=hash('sha256',$beforeSource);
    $beforeSource=str_replace('final class CardRenderer','final class CapacityBaselineRenderer',$beforeSource,$replaced);$same(1,$replaced,'frozen renderer fixture class identified');
    eval(substr($beforeSource,5));$before=new RadioStamp\Cards\CapacityBaselineRenderer($fonts);
    $normal=['user_id'=>$user,'display_name'=>'架空の容量試験','month'=>'2026-10','month_count'=>4,'total_count'=>1000,'month_days'=>1,'total_days'=>365,
        'days'=>['2026-10-20'=>['IC','コンデンサ','LED','IC']],'awards'=>[10,30,60,100,300,365],'milestone_basis'=>'attendance_days_jst'];
    $assert($before->monthly($normal)!==$renderer->monthly($normal),'legacy four-stamp monthly PNG adopts reviewed five-position layout');
    $same($before->memorial($normal,365),$renderer->memorial($normal,365),'normal four-stamp memorial PNG byte-identical');
    $full=$normal;$full['month']='2026-08';$full['month_count']=124;$full['month_days']=31;$full['days']=[];
    for($day=1;$day<=31;$day++){$full['days']['2026-08-'.sprintf('%02d',$day)]=['IC','コンデンサ','LED','IC'];}
    $assert($before->monthly($full)!==$renderer->monthly($full),'legacy 124-stamp monthly PNG adopts reviewed five-position layout');
    $same($before->memorial($full,365),$renderer->memorial($full,365),'normal 124-stamp memorial PNG byte-identical');
    foreach([10,30,60,100,300,365]as$milestone){$same($before->memorial($normal,$milestone),$renderer->memorial($normal,$milestone),'remaining '.$milestone.'-day memorial PNG byte-identical');}
    $invalid($normal,'removed day-one is unavailable even after 365 days',1);$invalid($full,'old normal history cannot reissue day-one',1);
    $scenarios['preserved_semantics']=['monthly_art_intentionally_changed'=>true,'four_and_124_memorial_pngs_byte_identical'=>true,'remaining_six_memorial_pngs_byte_identical'=>true,'baseline_renderer_sha256'=>$baselineHash];

    // No direct attendance writes: all five records go through the real operational service API.
    $five=$make('five',['daily_times'=>$times,'one_off_times'=>[]]);$same(true,OperationsConfig::ready($five['config']),'normal operational five-slot configuration accepted');
    foreach(['posts_enabled','roles_enabled','achievement_notifications_enabled','room_permissions_enabled','member_actions_enabled','periodic_worker_enabled']as$key){$same(true,$five['config'][$key],'all operational features enabled: '.$key);}
    // Nine genuine prior-month participation days make the first current milestone eligible.
    // The October history still contains exactly the five sessions being tested below.
    for($day=1;$day<=9;$day++){
        $five['clock']->set('2026-09-'.sprintf('%02d',$day).'T07:00:00+09:00');$priorOpen=$five['context']->service->openAttendance($user,'capacity-prior-open-'.$day);$same(true,$priorOpen['ok'],'prior-day normal receipt');
        $same(true,$five['context']->service->attend($priorOpen['session_id'],$user,'capacity-prior-attend-'.$day)['ok'],'prior-day normal attendance');
    }
    foreach($times as$index=>$time){
        $five['clock']->set('2026-10-20T'.$time.':00+09:00');$open=$five['context']->service->openAttendance($user,'capacity-five-open-'.$index);$same(true,$open['ok'],'normal five-slot receipt');
        $result=$five['context']->service->attend($open['session_id'],$user,'capacity-five-attend-'.$index);$same(true,$result['ok'],'normal five-slot attendance');
        $same('already_attended',$five['context']->service->attend($open['session_id'],$user,'capacity-five-duplicate-'.$index)['code'],'separate duplicate press does not add a record');
        $same(9+$index+1,$five['context']->service->statusData($user)['total_count'],'one new count per session after duplicate, with nine prior days');
    }
    $data=$five['context']->service->cardData($user,'架空の容量試験','2026-10');
    $same(5,$data['month_count'],'five normal records reach monthly count');$same(5,count($data['days']['2026-10-20']),'five stamps on scheduled day');$same(10,$data['total_days'],'five current sessions plus nine prior days reach ten actual days');
    $same(1,$data['month_days'],'all five current sessions remain one actual day');$same([10],$data['awards'],'first current benefit is ten days, excluding day one');
    $monthlyFive=$renderer->monthly($data);$memorialFive=$renderer->memorial($data,10);$fiveHashes=[$png($monthlyFive,1250,'five monthly'),$png($memorialFive,800,'five memorial')];
    // A schedule edit cannot discard valid old attendance, and both operational worker replies must finish.
    $five['config']['schedule']=['daily_times'=>[],'one_off_times'=>[['at'=>'2026-10-21 21:00','slot_id'=>'night']]];file_put_contents($five['path'],"<?php\nreturn ".var_export($five['config'],true).";\n");
    $history=new RadioService($five['context']->db,new RadioStamp\Discord\ServerOperationsGateway($five['context']->client,$five['clock'],$five['config'],$five['context']->roster),$five['clock'],$five['config']);
    $oldData=$history->cardData($user,'架空の容量試験','2026-10');$same($data,$oldData,'normal schedule still reads all old five-slot history');
    [$job,$cardPayload]=$send($five,'radio-card');$same('done',$job['state'],'five-record card worker finishes');$same('card_ready',$job['result_code'],'five-record card worker result');$same(1,(int)$job['attempts'],'card is not stuck in renderer retry');$same('',$job['token_ciphertext'],'completed card drops callback ciphertext');
    [$job]=$send($five,'radio:memorial:10');$same('done',$job['state'],'five-record ten-day memorial worker finishes');$same('memorial_ready',$job['result_code'],'five-record ten-day memorial worker result');
    $same([true,true],array_column($five['http']->callbacks,'png'),'both actual fake followups contain PNG attachments');
    $replyCount=count($five['http']->callbacks);$send($five,'radio-card',$cardPayload);$same($replyCount,count($five['http']->callbacks),'same card interaction replay does not duplicate a reply');
    $same(14,$five['context']->service->statusData($user)['total_count'],'card and memorial never add attendance');
    $scenarios['five_normal_records']=['month_count'=>5,'stamps_on_scheduled_day'=>5,'month_participation_days'=>1,'participation_days'=>10,'prior_month_days'=>9,'memorial_milestone'=>10,'readable_after_schedule_change'=>true,'worker_codes'=>['card_ready','memorial_ready'],'png_sha256'=>$fiveHashes];

    // 125 normal service records in one month cross the old independent monthly guard.
    $many=$make('monthly125',['daily_times'=>$times,'one_off_times'=>[]]);
    for($day=1;$day<=25;$day++){foreach($times as$index=>$time){
        $many['clock']->set('2026-10-'.sprintf('%02d',$day).'T'.$time.':00+09:00');$open=$many['context']->service->openAttendance($user,'capacity-125-open-'.$day.'-'.$index);$same(true,$open['ok'],'125-history normal receipt');
        $same(true,$many['context']->service->attend($open['session_id'],$user,'capacity-125-attend-'.$day.'-'.$index)['ok'],'125-history normal attendance');
    }}
    $manyData=$many['context']->service->cardData($user,'架空の容量試験','2026-10');$same(125,$manyData['month_count'],'125 monthly records accepted');$same(125,$manyData['total_count'],'total remains distinct from day count');$same(25,$manyData['month_days'],'125 records represent 25 actual days');
    $monthly125=$renderer->monthly($manyData);$memorial125=$renderer->memorial($manyData,10);$manyHashes=[$png($monthly125,1250,'125 monthly'),$png($memorial125,800,'125 memorial')];
    foreach(['radio-card'=>'card_ready','radio:memorial:10'=>'memorial_ready']as$command=>$code){[$job]=$send($many,$command);$same('done',$job['state'],'125-history worker finishes');$same($code,$job['result_code'],'125-history expected worker result');$same(1,(int)$job['attempts'],'125-history worker no renderer retry');}
    $same([true,true],array_column($many['http']->callbacks,'png'),'125-history worker replies attach both PNGs');
    $scenarios['monthly125_normal_records']=['month_count'=>125,'participation_days'=>25,'worker_codes'=>['card_ready','memorial_ready'],'png_sha256'=>$manyHashes];

    // Scheduled month and actual JST participation month remain independent at midnight.
    $cross=$make('cross-month',['daily_times'=>[],'one_off_times'=>[['at'=>'2026-11-01 00:00','slot_id'=>'midnight']]]);$cross['clock']->set('2026-10-31T23:55:00+09:00');
    $open=$cross['context']->service->openAttendance($user,'capacity-cross-open');$same(true,$open['ok'],'cross-month receipt');$cross['clock']->set('2026-10-31T23:59:00+09:00');
    $same(true,$cross['context']->service->attend($open['session_id'],$user,'capacity-cross-attend')['ok'],'cross-month attendance');
    $oct=$cross['context']->service->cardData($user,'架空の容量試験','2026-10');$nov=$cross['context']->service->cardData($user,'架空の容量試験','2026-11');
    $same([0,1,1,0],[$oct['month_count'],$oct['month_days'],$nov['month_count'],$nov['month_days']],'scheduled and actual month accounting');
    $png($renderer->monthly($oct),1250,'cross October');$png($renderer->monthly($nov),1250,'cross November');$invalid($oct,'cross-month first day cannot issue removed benefit',1);
    $same([],$oct['awards'],'cross-month first day has no October benefit');$same([],$nov['awards'],'scheduled November record cannot invent a day benefit');
    $same(['2026-11-01'],array_keys($nov['days']),'scheduled date retained');$scenarios['cross_month_separation']=true;

    // A bounded history ceiling follows minute-precision unique session IDs, not a current schedule cap.
    $capacity=[];
    foreach(['2026-10'=>31,'2027-02'=>28,'2028-02'=>29]as$month=>$days){
        $large=$normal;$large['month']=$month;$large['month_count']=$days*1440;$large['total_count']=1000000;$large['month_days']=$days;$large['days']=[];
        for($day=1;$day<=$days;$day++){$large['days'][$month.'-'.sprintf('%02d',$day)]=array_fill(0,1440,'IC');}
        $png($renderer->monthly($large),null,$month.' maximum capacity');$png($renderer->memorial($large,365),800,$month.' maximum memorial');
        $bad=$large;$bad['month_count']++;$invalid($bad,$month.' monthly bound plus one');
        $capacity[$month]=['days'=>$days,'per_day_limit'=>1440,'monthly_limit'=>$days*1440,'accepted_at_limit'=>true,'rejected_above_limit'=>true];
    }
    $large=$normal;$large['month_count']=1440;$large['total_count']=1000000;$large['days']=['2026-10-20'=>array_fill(0,1440,'IC')];$png($renderer->monthly($large),null,'one-day minute capacity');
    $large['month_count']=1441;$large['days']['2026-10-20'][]='IC';$invalid($large,'one-day bound plus one');
    $unboundedTotal=$normal;$unboundedTotal['total_count']=PHP_INT_MAX;$png($renderer->monthly($unboundedTotal),1250,'cumulative count not limited to monthly capacity');$png($renderer->memorial($unboundedTotal,365),800,'cumulative count above 365 memorial');
    $scenarios['resource_boundary']=$capacity;$scenarios['cumulative_count_above_365_not_clamped']=true;

    // Preserve strict type, calendar, list, value, count and award validation on both renderers.
    $invalidCases=[];
    foreach(['month_count'=>'4','total_count'=>3,'month_days'=>-1,'total_days'=>0,'days'=>'invalid','awards'=>[10,10]]as$key=>$value){$bad=$normal;$bad[$key]=$value;$invalidCases['invalid '.$key]=$bad;}
    foreach(['2026-10-00','2026-10-32','2026-11-01','2026-10-1']as$date){$bad=$normal;$bad['days']=[$date=>['IC','コンデンサ','LED','IC']];$invalidCases['invalid date '.$date]=$bad;}
    $bad=$normal;$bad['days']=['2026-10-20'=>[1=>'IC',2=>'IC',3=>'IC',4=>'IC']];$invalidCases['non-list stamps']=$bad;
    $bad=$normal;$bad['days']=['2026-10-20'=>['IC','LED','コンデンサ','CAPACITY_CANARY_STAMP']];$invalidCases['invalid stamp']=$bad;
    $bad=$normal;$bad['month_count']=5;$invalidCases['count sum mismatch']=$bad;
    $bad=$normal;$bad['days']=array_fill(0,32,[]);$invalidCases['too many day entries']=$bad;
    $bad=$normal;$bad['month']='2027-02';$bad['days']=['2027-02-29'=>['IC','IC','IC','IC']];$invalidCases['non-leap February date']=$bad;
    foreach($invalidCases as$why=>$bad){$invalid($bad,$why);$invalid($bad,$why.' memorial',10);}
    $notReached=$normal;$notReached['total_days']=1;$notReached['awards']=[];$invalid($notReached,'unreached day milestone',10);
    $bad=$normal;$bad['milestone_basis']='attendance_count';$invalid($bad,'wrong milestone basis',365);
    $scenarios['invalid_data_cases']=count($invalidCases)*2+2;

    // Retain the earlier semantic/boundary checks, adding a real ten-day fixture and removed-day-one guards.
    $same(604,$checks,'initial capacity checks plus six-milestone policy checks retained');
    // Extend the same real-service fixture from its preserved 125 records to a normal 31-day five-slot month.
    $expectedKinds=['IC','コンデンサ','LED','プラグ','電池'];
    $same($expectedKinds,$data['days']['2026-10-20'],'each normal slot stores its distinct fixed component');
    for($day=26;$day<=31;$day++){foreach($times as$index=>$time){
        $many['clock']->set('2026-10-'.sprintf('%02d',$day).'T'.$time.':00+09:00');$open=$many['context']->service->openAttendance($user,'capacity-155-open-'.$day.'-'.$index);$same(true,$open['ok'],'155-history normal receipt');
        $same(true,$many['context']->service->attend($open['session_id'],$user,'capacity-155-attend-'.$day.'-'.$index)['ok'],'155-history normal attendance');
    }}
    $normalMonth=$many['context']->service->cardData($user,'架空の容量試験','2026-10');
    $same(155,$normalMonth['month_count'],'five normal slots across all 31 days produce 155 records');$same(31,$normalMonth['month_days'],'155 records remain 31 participation days');
    foreach($normalMonth['days']as$date=>$stamps){$same($expectedKinds,$stamps,'fixed components retained for normal day '.$date);}
    $monthly155=$renderer->monthly($normalMonth);$hash155=$png($monthly155,1250,'155 normal monthly');
    $same(155,$many['context']->service->statusData($user)['total_count'],'rendering a 155-stamp calendar never adds attendance');
    $scenarios['normal155_records']=['month_count'=>155,'participation_days'=>31,'fixed_stamp_order'=>$expectedKinds,'png_sha256'=>$hash155,'height'=>1250];
    // An old persisted award is retained for history, but it cannot authorize a fresh day-one PNG.
    $oneDay=$make('removed-day1',['daily_times'=>[],'one_off_times'=>[['at'=>'2026-10-20 07:00','slot_id'=>'morning']]]);$oneDay['clock']->set('2026-10-20T07:00:00+09:00');
    $oneOpen=$oneDay['context']->service->openAttendance($user,'capacity-removed-day1-open');$same(true,$oneOpen['ok'],'first-day receipt still works');
    $same(true,$oneDay['context']->service->attend($oneOpen['session_id'],$user,'capacity-removed-day1-attend')['ok'],'first attendance still records a stamp');
    $firstData=$oneDay['context']->service->cardData($user,'架空の初日試験','2026-10');$same(1,$firstData['total_days'],'first actual day remains recorded');$same([],$firstData['awards'],'first actual day awards list is empty');$same('⚡ はじめの通電',$firstData['latest_title'],'first count-based lightning title remains');
    $onePdo=$oneDay['context']->db->pdo();$same(0,(int)$onePdo->query('SELECT COUNT(*) FROM memorial_awards WHERE milestone=1')->fetchColumn(),'first attendance creates no day-one award row');$onePdo->prepare('INSERT INTO memorial_awards(user_id,milestone,reached_at) VALUES(?,?,?)')->execute([$user,1,$oneDay['clock']->now()->getTimestamp()-86400]);
    $legacyBefore=$onePdo->query('SELECT * FROM memorial_awards ORDER BY user_id,milestone')->fetchAll();$attendanceBefore=$onePdo->query('SELECT * FROM attendance ORDER BY session_id,user_id')->fetchAll();
    $removedId=(string)(900000000000070000+ ++$sequence);$oneDay['context']->queue->enqueue(['interaction_id'=>$removedId,'action'=>'memorial','target'=>'1','user_id'=>$user,'display_name'=>'架空の初日試験','request_hash'=>hash('sha256','removed-day1'),'scope_mode'=>'operations'],'CAPACITY_CANARY_REMOVED_DAY1_TOKEN',$oneDay['clock']->now()->getTimestamp());
    $oneSink=new MockFollowupSink();$oneWorker=new InteractionWorker($oneDay['context']->queue,$oneDay['context']->service,$renderer,$oneSink,$oneDay['clock'],null,'operations',static fn():array=>['ok'=>true]);$pngCallsBefore=$GLOBALS['capacity_png_calls']??0;$removedResult=$oneWorker->runOnce($removedId);
    $same('done',$removedResult['state'],'removed day-one request reaches a terminal refusal');$same('memorial_locked',$removedResult['code'],'removed day-one request is locked');$same(1,count($oneSink->records),'removed day-one has one refusal reply');$same(0,$oneSink->records[$removedId]['png_bytes'],'removed day-one emits no PNG');$same(false,$oneSink->records[$removedId]['result']['ok'],'removed day-one refusal is not success');$same($pngCallsBefore,$GLOBALS['capacity_png_calls']??0,'removed day-one never invokes PNG encoding');
    $same($legacyBefore,$onePdo->query('SELECT * FROM memorial_awards ORDER BY user_id,milestone')->fetchAll(),'removed day-one leaves historical award row unchanged');$same($attendanceBefore,$onePdo->query('SELECT * FROM attendance ORDER BY session_id,user_id')->fetchAll(),'removed day-one leaves attendance unchanged');$same(1,$oneDay['context']->service->statusData($user)['total_count'],'removed day-one never adds a count');$same([],$oneDay['context']->service->cardData($user,'架空の初日試験','2026-10')['awards'],'historical day-one award never reenters current list');
    $firstCardId=(string)(900000000000070000+ ++$sequence);$oneDay['context']->queue->enqueue(['interaction_id'=>$firstCardId,'action'=>'card','target'=>'2026-10','user_id'=>$user,'display_name'=>'架空の初日試験','request_hash'=>hash('sha256','first-day-card'),'scope_mode'=>'operations'],'CAPACITY_CANARY_FIRST_CARD_TOKEN',$oneDay['clock']->now()->getTimestamp());$firstCardResult=$oneWorker->runOnce($firstCardId);
    $same('done',$firstCardResult['state'],'first-day monthly card still finishes');$same('card_ready',$firstCardResult['code'],'first-day monthly card remains available');$same([],$oneSink->records[$firstCardId]['result']['components'],'first-day monthly card offers no removed benefit button');$same(true,$oneSink->records[$firstCardId]['png_signature_valid'],'first-day monthly card still contains a valid PNG');
    $scenarios['removed_day1']=['current_awards'=>[],'historical_award_preserved'=>true,'attendance_preserved'=>true,'request_code'=>'memorial_locked','new_memorial_pngs'=>0,'first_count_title'=>'⚡ はじめの通電','first_day_monthly_card'=>'card_ready'];
    $initialChecks=$checks;
    $mutations=static fn(array $f):int=>count(array_filter($f['http']->calls,static fn($request):bool=>$request->method()!=='GET'));
    $readJob=static function(array $f,string $id):array{$q=$f['context']->db->pdo()->prepare('SELECT state,result_code,attempts,available_at,token_ciphertext,lease_id FROM interaction_jobs WHERE interaction_id=?');$q->execute([$id]);$row=$q->fetch();$q->closeCursor();return $row;};
    $exceptionFixture=static function(string $label)use($make,$user,$renderer,&$sequence,$same):array{
        $f=$make($label,['daily_times'=>[],'one_off_times'=>[['at'=>'2026-10-20 07:00','slot_id'=>'morning']]]);$f['clock']->set('2026-10-20T07:00:00+09:00');
        $open=$f['context']->service->openAttendance($user,'capacity-fault-open-'.$label);$same(true,$open['ok'],'exception fixture receipt');
        $same(true,$f['context']->service->attend($open['session_id'],$user,'capacity-fault-attend-'.$label)['ok'],'exception fixture normal attendance');
        $id=(string)(900000000000040000+ ++$sequence);$f['context']->queue->enqueue(['interaction_id'=>$id,'action'=>'card','target'=>'2026-10','user_id'=>$user,
            'display_name'=>'CAPACITY_CANARY_FAULT_NAME','request_hash'=>hash('sha256',$label),'scope_mode'=>'operations'],'CAPACITY_CANARY_FAULT_TOKEN',$f['clock']->now()->getTimestamp());
        $sink=new MockFollowupSink();$log=new stdClass();$log->text='';$diag=SafeDiagnostics::fromConfig(['diagnostics_enabled'=>true,'participant_scope'=>'guild','participant_user_ids'=>[]],static function(string $line)use($log):void{$log->text.=$line;});
        $worker=new InteractionWorker($f['context']->queue,$f['context']->service,$renderer,$sink,$f['clock'],null,'operations',static fn():array=>['ok'=>true],$diag);
        return ['fixture'=>$f,'id'=>$id,'sink'=>$sink,'log'=>$log,'worker'=>$worker];
    };
    $checkedRun=static function(array $x,string $why)use($same):?array{
        $level=ob_get_level();ob_start();$capturedLevel=ob_get_level();
        try{$result=$x['worker']->runOnce($x['id']);$same($capturedLevel,ob_get_level(),$why.' renderer returns to caller output-buffer level');}
        finally{$same('',ob_get_clean(),$why.' no warning or partial PNG output');}
        $same($level,ob_get_level(),$why.' original output-buffer level retained');return $result;
    };
    $safeLog=static function(string $log,string $exception,string $why)use($same,$user):array{
        foreach(['CAPACITY_CANARY',$user,'/private/','https://']as$secret){$same(false,str_contains($log,$secret),$why.' private values excluded');}
        $rows=array_map(static fn(string $line):array=>json_decode($line,true,32,JSON_THROW_ON_ERROR),array_filter(explode("\n",trim($log))));
        $same(true,count(array_filter($rows,static fn(array $row):bool=>($row['stage']??null)==='worker_result'&&($row['code']??null)==='exception'&&($row['exception']??null)===$exception))>0,$why.' fixed exception category retained');return $rows;
    };

    $fault=$exceptionFixture('invalid-history');$f=$fault['fixture'];$id=$fault['id'];
    $f['context']->db->pdo()->prepare('UPDATE attendance SET stamp=? WHERE user_id=?')->execute(['CAPACITY_CANARY_INVALID_STAMP',$user]);
    $mutationCount=$mutations($f);$states=[];$waits=[2,4,8,16];
    for($attempt=1;$attempt<=5;$attempt++){
        $now=$f['clock']->now()->getTimestamp();$result=$checkedRun($fault,'invalid-history attempt '.$attempt);$row=$readJob($f,$id);
        $same($attempt,(int)$row['attempts'],'invalid history increments only an actual attempt');
        $same($attempt===5?'done':'retry',$result['state'],'invalid history returns completion only after final failure notice');
        $same($attempt===5?'card_render_failed':'worker_retry',$result['code'],'invalid history returned code is accurate');
        $same($attempt===5?'done':'pending',$row['state'],'invalid history retries then completes its private failure notice');
        $same($attempt===5?'card_render_failed':'worker_retry',$row['result_code'],'invalid history DB preserves image failure rather than success');
        $same(null,$row['lease_id'],'owned failure releases only its lease');
        $same($attempt===5,$row['token_ciphertext']==='','only terminal failure clears callback ciphertext');
        $same($attempt===5?1:0,count($fault['sink']->records),'invalid history sends only one final private failure notice');
        $same($mutationCount,$mutations($f),'card renderer failure performs no HTTP mutation');
        $states[]=['attempt'=>$attempt,'state'=>$row['state'],'code'=>$result['code'],'token_cleared'=>$row['token_ciphertext']===''];
        if($attempt<5){
            $same($now+$waits[$attempt-1],(int)$row['available_at'],'invalid history exponential backoff');
            $same(null,$fault['worker']->runOnce($id),'invalid history cannot retry immediately');
            $f['clock']->advance($waits[$attempt-1]-1);$same(null,$fault['worker']->runOnce($id),'invalid history cannot retry before deadline');
            $f['clock']->advance(1);
        }
    }
    $same(null,$fault['worker']->runOnce($id),'terminal renderer failure is never claimed again');$same(1,$f['context']->service->statusData($user)['total_count'],'renderer fault never adds attendance');
    $same(0,$fault['sink']->records[$id]['png_bytes'],'final renderer failure notice never attaches a partial image');
    $same(['ok'=>false,'code'=>'card_render_failed','components'=>[]],$fault['sink']->records[$id]['result'],'final renderer failure is explicit rather than an image success');
    $rows=$safeLog($fault['log']->text,'InvalidArgumentException','invalid history');
    $same(5,count(array_filter($rows,static fn(array $row):bool=>($row['exception']??null)==='InvalidArgumentException')),'each invalid-history attempt has a class-only diagnostic');
    $same(true,count(array_filter($rows,static fn(array $row):bool=>($row['code']??null)==='card_render_failed'))>0,'terminal image failure has an explicit fixed diagnostic');
    $exceptionLogs.=$fault['log']->text;$scenarios['renderer_invalid_history_failure']=['states'=>$states,'backoff_seconds'=>$waits,'followups'=>1,'final_notice_png_bytes'=>0,'final_code'=>'card_render_failed','http_mutations'=>0,'attendance_unchanged'=>true,'diagnostic_exception'=>'InvalidArgumentException'];

    $pngFailures=[];
    foreach(['false','throw','warning','attachment_oversize']as$mode){
        $x=$exceptionFixture('png-'.$mode);$f=$x['fixture'];$id=$x['id'];$mutationCount=$mutations($f);$GLOBALS['capacity_png_fault']=$mode;
        try{$result=$checkedRun($x,'PNG '.$mode);}finally{unset($GLOBALS['capacity_png_fault']);}
        $same('retry',$result['state'],'PNG '.$mode.' is safely retryable');$same('worker_retry',$result['code'],'PNG '.$mode.' failure code');
        $row=$readJob($f,$id);$same('pending',$row['state'],'PNG '.$mode.' retains pending job');$same(1,(int)$row['attempts'],'PNG '.$mode.' one attempt');$same(false,$row['token_ciphertext']==='','PNG '.$mode.' retains callback only for retry');
        $same(0,count($x['sink']->records),'PNG '.$mode.' emits no partial followup');$same($mutationCount,$mutations($f),'PNG '.$mode.' performs no HTTP mutation');
        $safeLog($x['log']->text,'RuntimeException','PNG '.$mode);$f['clock']->advance(2);$result=$checkedRun($x,'PNG '.$mode.' recovered');
        $same('done',$result['state'],'PNG '.$mode.' recovers after fault removed');$same('card_ready',$result['code'],'PNG '.$mode.' recovery code');
        $row=$readJob($f,$id);$same('done',$row['state'],'PNG '.$mode.' recovery persisted');$same(2,(int)$row['attempts'],'PNG '.$mode.' recovers on second attempt');$same('',$row['token_ciphertext'],'PNG '.$mode.' completed callback cleared');
        $same(1,count($x['sink']->records),'PNG '.$mode.' produces one recovered reply');$same(true,$x['sink']->records[$id]['png_signature_valid'],'PNG '.$mode.' recovered attachment is valid');
        $same(null,$x['worker']->runOnce($id),'PNG '.$mode.' completion cannot repeat');$same(1,$f['context']->service->statusData($user)['total_count'],'PNG fault never re-adds attendance');
        $exceptionLogs.=$x['log']->text;$pngFailures[$mode]=['first'=>'retry','recovered'=>'done','attempts'=>2,'followups'=>1,'partial_output'=>false,'buffer_level_preserved'=>true,'diagnostic_exception'=>'RuntimeException'];
    }
    $scenarios['png_failure_recovery']=$pngFailures;

    $expiry=$exceptionFixture('expired-png');$f=$expiry['fixture'];$id=$expiry['id'];$created=$f['clock']->now()->getTimestamp();$GLOBALS['capacity_png_fault']='false';
    try{$checkedRun($expiry,'expiring PNG fault');}finally{unset($GLOBALS['capacity_png_fault']);}
    $calls=$GLOBALS['capacity_png_calls'];$f['clock']->advance(900);$same(null,$expiry['worker']->runOnce($id),'900-second expired retry is not executed');$row=$readJob($f,$id);
    $same('expired',$row['state'],'expired renderer job is explicitly terminal');$same('interaction_expired',$row['result_code'],'expired renderer job has fixed result');$same('',$row['token_ciphertext'],'expired renderer job clears callback ciphertext');
    $same(1,(int)$row['attempts'],'expired retry never adds an attempt');$same($calls,$GLOBALS['capacity_png_calls'],'no PNG encoding attempted after expiry');$same(0,count($expiry['sink']->records),'expired PNG fault never sends a reply');
    $safeLog($expiry['log']->text,'RuntimeException','expired PNG');$exceptionLogs.=$expiry['log']->text;$scenarios['renderer_retry_expiry']=['expiry_seconds'=>900,'final_state'=>'expired','token_cleared'=>true,'followups'=>0];

    $lost=$exceptionFixture('lost-png');$f=$lost['fixture'];$id=$lost['id'];$otherLease=str_repeat('b',32);
    $GLOBALS['capacity_png_hook']=static function()use($f,$id,$otherLease):void{$f['context']->db->pdo()->prepare("UPDATE interaction_jobs SET lease_id=?,attempts=2 WHERE interaction_id=? AND state='processing'")->execute([$otherLease,$id]);};$GLOBALS['capacity_png_fault']='false';
    try{$result=$checkedRun($lost,'PNG claim lost');}finally{unset($GLOBALS['capacity_png_hook'],$GLOBALS['capacity_png_fault']);}
    $same('lost',$result['state'],'lost renderer claim is not reported as retry');$same('worker_claim_lost',$result['code'],'lost renderer claim has explicit code');$row=$readJob($f,$id);
    $same('processing',$row['state'],'obsolete failure cannot change another worker state');$same($otherLease,$row['lease_id'],'obsolete failure cannot clear another lease');$same(2,(int)$row['attempts'],'obsolete failure cannot replace another attempt');
    $same(false,$row['token_ciphertext']==='','obsolete failure cannot erase another callback');$same(0,count($lost['sink']->records),'lost renderer claim emits no followup');
    $rows=$safeLog($lost['log']->text,'RuntimeException','PNG claim lost');$same(1,count(array_filter($rows,static fn(array $row):bool=>($row['code']??null)==='worker_claim_lost')),'lost renderer claim has one fixed diagnostic');
    $exceptionLogs.=$lost['log']->text;$scenarios['renderer_claim_lost']=['state'=>'lost','code'=>'worker_claim_lost','other_owner_preserved'=>true,'followups'=>0];
    $scenarios['initial_capacity_assertions']=$initialChecks;$scenarios['exception_assertions']=$checks-$initialChecks;

    $stage=getenv('RADIO_STAMP_TEST_ARTIFACT_DIR');
    if(!is_dir($stage)&&!mkdir($stage,0700,true)&&!is_dir($stage)){throw new RuntimeException('capacity_artifact_directory_unavailable');}
    foreach(['monthly-five.png'=>$monthlyFive,'memorial-five.png'=>$memorialFive,'monthly-125.png'=>$monthly125,'memorial-125.png'=>$memorial125,'monthly-normal155.png'=>$monthly155]as$file=>$bytes){file_put_contents($stage.'/'.$file,$bytes);}
    $result=['status'=>'LOCAL_CARD_CAPACITY_VERIFIED_NOT_DEPLOYED','assertions'=>$checks,'real_network_calls'=>0,'server_commands'=>0,'deployed'=>false,
        'full_operational_flags'=>true,'participant_scope'=>'guild','closure_policy'=>'best_effort','command_registration_enabled'=>false,'scenarios'=>$scenarios];
    file_put_contents($stage.'/card-capacity.safe.json',json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
    file_put_contents($stage.'/card-exceptions.safe.jsonl',$exceptionLogs);
    echo "Card capacity: $checks assertions passed (five/125/155 histories, worker PNG replies, bounded validation, failed/lost retry states and injected encoder failures; fake only).\n";
}finally{
    foreach($fixtures as$f){$f['context']->roster->close();}
    unset($GLOBALS['capacity_png_fault'],$GLOBALS['capacity_png_hook'],$GLOBALS['capacity_png_calls']);
    unset($f,$fixtures,$five,$many,$cross,$history,$oneDay,$onePdo,$oneWorker,$oneSink,$make,$send,$context,$runtime,$http,$job,$fault,$x,$expiry,$lost,$exceptionFixture,$checkedRun);gc_collect_cycles();
    $resolved=realpath($root);$temporary=realpath(sys_get_temp_dir());
    if($resolved===false||$temporary===false||!str_starts_with(str_replace('\\','/',$resolved),str_replace('\\','/',$temporary).'/radio-card-capacity-')){throw new RuntimeException('capacity_cleanup_boundary');}
    $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($iterator as$file){if($file->isLink()||$file->isFile()){unlink($file->getPathname());}else{rmdir($file->getPathname());}}rmdir($root);
}
}
