<?php
declare(strict_types=1);
require_once __DIR__.'/artifact_directory.php';
require dirname(__DIR__).'/bootstrap.php';
require __DIR__.'/fixtures/OperationsRuntimeFixture.php';
use RadioStamp\Core\{Schedule,Database,FrozenClock,RadioService};
use RadioStamp\Discord\{MockGateway,HttpAdapter,HttpRequest,HttpResponse,DiscordRestClient};
use RadioStamp\Operations\{OperationsConfig,OperationsRuntime};
use RadioStamp\Http\ValidationControls;
use RadioStamp\Http\{OperationsEndpoint,PostResponseDriver};

final class ScheduleSafetyFaultPDO extends PDO {
    public ?string $failNextPattern=null;
    public function prepare(string $query,array $options=[]):PDOStatement|false {
        if($this->failNextPattern!==null&&str_contains($query,$this->failNextPattern)){$this->failNextPattern=null;throw new PDOException('CANARY_SECRET_SCHEDULE_DB');}
        return parent::prepare($query,$options);
    }
}

function scheduleSafetyConfig(array $spec):array {
    return ['mode'=>'mock','guild_id'=>'fictional-guild','voice_channel_id'=>'fictional-exercise',
        'text_channel_id'=>'fictional-guide','records_channel_id'=>'fictional-records','destination_channel_id'=>'fictional-onsen',
        'participant_commands_enabled'=>true,'member_actions_enabled'=>true,'role_ids'=>[],'schedule'=>$spec];
}
if (($argv[1]??null)==='--receipt-child') {
    $db=new Database($argv[2],5000,false);$clock=new FrozenClock($argv[3]);$gateway=new MockGateway($clock);
    $config=scheduleSafetyConfig(json_decode($argv[4],true,32,JSON_THROW_ON_ERROR));$gateway->setVoiceState('fictional-person',$config['voice_channel_id']);
    $service=new RadioService($db,$gateway,$clock,$config);$result=$service->openAttendance('fictional-person','child-'.$argv[5]);
    echo json_encode(['code'=>$result['code'],'receipt_posts'=>count(array_filter($gateway->actionLog,static fn(array $e):bool=>($e['components']??[])!==[]))],JSON_THROW_ON_ERROR);
    exit(0);
}

$checks=0;$cases=[];$fixtures=[];
$same=static function(mixed $expected,mixed $actual,string $why)use(&$checks):void {
    ++$checks;if($expected!==$actual){throw new RuntimeException('Schedule safety: '.$why.' expected='.json_encode($expected).' actual='.json_encode($actual));}
};
$date=static fn(string $at):DateTimeImmutable=>new DateTimeImmutable($at,new DateTimeZone('Asia/Tokyo'));
$spec=static function(string ...$times):array {
    $slots=['morning','midday','night','dawn','midnight'];$bound=[];
    foreach($times as$index=>$time){$bound[]=['at'=>$time,'slot_id'=>$slots[$index]];}
    return ['daily_times'=>[],'one_off_times'=>$bound];
};
$root=sys_get_temp_dir().'/radio-schedule-safety-'.bin2hex(random_bytes(8));mkdir($root,0700);
$make=static function(string $at,array $schedule,?string $path=null)use(&$fixtures):array {
    $db=new Database($path??':memory:');$clock=new FrozenClock($at);$gateway=new MockGateway($clock);$config=scheduleSafetyConfig($schedule);
    $service=new RadioService($db,$gateway,$clock,$config);$gateway->setVoiceState('fictional-person',$config['voice_channel_id']);
    $value=compact('db','clock','gateway','config','service');$fixtures[]=$value;return $value;
};
$row=static function(Database $db,string $id):array {$q=$db->pdo()->prepare('SELECT * FROM sessions WHERE id=?');$q->execute([$id]);return $q->fetch()?:[];};
$tickCode=static function(array $tick,string $id):string {foreach($tick['sessions']as$result){if($result['session_id']===$id){return $result['code'];}}return 'not-processed';};
$refresh=static function(array $f):void {$f['gateway']->setVoiceState('fictional-person',$f['config']['voice_channel_id']);$f['gateway']->setRoster($f['config']['voice_channel_id'],['fictional-person']);};
$planOther=static function(array $f,string $at)use($spec,$date):RadioService {
    // A changed configuration creates another logical slot; it must not rebind the old slot's promise.
    $otherSpec=['daily_times'=>[],'one_off_times'=>[['at'=>$at,'slot_id'=>'midday']]];
    $other=new RadioService($f['db'],$f['gateway'],$f['clock'],scheduleSafetyConfig($otherSpec));$other->planSessions($date($at),$date($at));return $other;
};
$effects=static fn(MockGateway $gateway):array=>array_values(array_filter($gateway->actionLog,static fn(array $e):bool=>in_array($e['method'],['setRoomOpen','moveMember','disconnectMember'],true)));
$parallel=static function(string $path,string $at,array $schedule,string $label)use($same):array {
    $children=[];
    for($i=0;$i<4;$i++) {
        $command=[PHP_BINARY];if(($ini=php_ini_loaded_file())!==false){array_push($command,'-c',$ini);}
        array_push($command,__FILE__,'--receipt-child',$path,$at,json_encode($schedule,JSON_THROW_ON_ERROR),$label.'-'.$i);
        $pipes=[];$process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);
        if(!is_resource($process)){throw new RuntimeException('Schedule child not started');}fclose($pipes[0]);$children[]=[$process,$pipes];
    }
    $results=[];foreach($children as[$process,$pipes]) {
        $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        $same(0,proc_close($process),'parallel child success');$same('',$err,'parallel child no diagnostics');$results[]=json_decode($out,true,32,JSON_THROW_ON_ERROR);
    }
    return $results;
};

try {
    // Configuration acceptance is shared by the scheduler, ready boundary and component controls.
    $rejected=[
        'old-30-daily'=>['daily_times'=>['14:00','14:30']],
        'daily-69'=>['daily_times'=>['14:00','15:09']],
        'daily-midnight'=>['daily_times'=>['23:40','00:49']],
        'one-off-69'=>$spec('2026-10-21 14:00','2026-10-21 15:09'),
        'one-off-year'=>$spec('2026-12-31 23:40','2027-01-01 00:49'),
        'one-off-leap'=>$spec('2028-02-28 23:40','2028-02-29 00:49'),
        'mixed-forward'=>['daily_times'=>['14:00'],'one_off_times'=>['2026-10-21 15:09']],
        'mixed-backward'=>['daily_times'=>['14:00'],'one_off_times'=>['2026-10-21 12:51']],
        'mixed-midnight'=>['daily_times'=>['00:49'],'one_off_times'=>['2026-10-21 23:40']],
        'duplicate-plus-near'=>['daily_times'=>['14:00','14:00'],'one_off_times'=>['2026-10-21 14:00','2026-10-21 15:09']],
    ];
    $ready=runtimeFixtureConfig($root,'best_effort',true);
    foreach($rejected as$label=>$schedule) {
        $code=null;try{new Schedule($schedule);}catch(InvalidArgumentException $e){$code=$e->getMessage();}
        $same('schedule_too_close',$code,$label.' explicit rejection');
        $same(false,OperationsConfig::ready(array_replace($ready,['schedule'=>$schedule])),$label.' ready refuses');
        $code=null;try{new ValidationControls(new FrozenClock('2026-10-21T14:00:00+09:00'),['schedule'=>$schedule]);}catch(InvalidArgumentException $e){$code=$e->getMessage();}
        $same('schedule_too_close',$code,$label.' component controls share validation');
    }
    $badRuntime=new OperationsRuntime(new RuntimeFixtureHttp(),null,new FrozenClock('2026-10-21T14:00:00+09:00'));
    $failure=null;try{$badRuntime->build(array_replace($ready,['schedule'=>$rejected['daily-69']]),static fn()=>true);}catch(RuntimeException $e){$failure=$e->getMessage();}
    $same('operations_configuration_unavailable',$failure,'ordinary Runtime shares spacing validation');
    $badRoot=$root.'/invalid-entry';mkdir($badRoot,0700);mkdir($badRoot.'/private',0700);mkdir($badRoot.'/public',0700);
    $badConfig=runtimeFixtureConfig($badRoot,'best_effort');$badConfig['schedule']=$rejected['daily-69'];$path=$badRoot.'/private/config.php';file_put_contents($path,"<?php\nreturn ".var_export($badConfig,true).";\n");
    $driver=new class implements PostResponseDriver {public function available():bool{return true;}public function finish():bool{return true;}};
    $entryHttp=new RuntimeFixtureHttp();$entryRuntime=new OperationsRuntime($entryHttp,null,new FrozenClock('2026-10-21T14:00:00+09:00'));
    $delivery=(new OperationsEndpoint($badRoot.'/public',$driver,$entryRuntime))->prepare(['REQUEST_METHOD'=>'POST'],'{}',$path);
    $same(503,$delivery->response->status,'ordinary HTTP endpoint rejects near schedule');$same([],$entryHttp->calls,'invalid schedule endpoint performs no HTTP');
    $same(false,is_file($badConfig['private_db_path']),'invalid schedule endpoint creates no queue DB');
    $accepted=[
        'normal'=>Schedule::DEFAULT_SPEC,
        'daily-70'=>['daily_times'=>['14:00','15:10']],
        'daily-wrap-70'=>['daily_times'=>['23:40','00:50']],
        'one-off-70'=>$spec('2026-10-21 14:00','2026-10-21 15:10'),
        'year-70'=>$spec('2026-12-31 23:40','2027-01-01 00:50'),
        'leap-70'=>$spec('2028-02-28 23:40','2028-02-29 00:50'),
        'mixed-70'=>['daily_times'=>['14:00'],'one_off_times'=>['2026-10-21 15:10']],
        'mixed-reverse-70'=>['daily_times'=>['14:00'],'one_off_times'=>['2026-10-21 12:50']],
        'mixed-wrap-70'=>['daily_times'=>['00:50'],'one_off_times'=>['2026-10-21 23:40']],
        'deduplicated'=>['daily_times'=>['14:00','14:00'],'one_off_times'=>['2026-10-21 14:00','2026-10-21 14:00']],
        'single-daily'=>['daily_times'=>['12:34']],
        'single-one-off'=>$spec('2026-10-21 12:34'),
    ];
    foreach($accepted as$label=>$schedule) {$same(true,(new Schedule($schedule)) instanceof Schedule,$label.' accepted');$same(true,OperationsConfig::ready(array_replace($ready,['schedule'=>$schedule])),$label.' ready');}
    $duplicates=new Schedule($accepted['deduplicated']);$same(1,count($duplicates->between($date('2026-10-21 00:00'),$date('2026-10-21 23:59:59'))),'one instant counted once');
    foreach(['2026-11-01 00:00','2026-12-31 00:00','2028-02-29 00:00']as$at) {
        $s=$date($at);$schedule=new Schedule();$same($s->format('Y-m-d_Hi'),$schedule->initialSessionAt($s->getTimestamp()-1800),'calendar opening inclusive');$same(null,$schedule->initialSessionAt($s->getTimestamp()+1800),'calendar initial cutoff exclusive');
    }
    $cases[]=['case'=>'configuration','rejected'=>array_keys($rejected),'accepted'=>array_keys($accepted),'interval_seconds'=>Schedule::MIN_INTERVAL_SECONDS];

    // A previous legal singleton can leave rows behind after a stopped configuration replacement.
    $f=$make('2026-10-21T13:59:00+09:00',$spec('2026-10-21 14:00'));
    $new=$planOther($f,'2026-10-21 14:30');$f['gateway']->actionLog=[];
    $same('session_schedule_conflict',$f['service']->openAttendance('fictional-person','new-old-conflict')['code'],'future legacy opening refuses first receipt');
    $same(null,$row($f['db'],'2026-10-21_1400')['started_at'],'denied admission never starts');
    $same(0,(int)$f['db']->pdo()->query('SELECT count(*) FROM receipt_announcements')->fetchColumn(),'no denied receipt claim');$same([],$f['gateway']->actionLog,'no denied admission effects');
    $f['clock']->advance(60);$same('session_schedule_conflict',$tickCode($f['service']->tick(),'2026-10-21_1400'),'old process refuses');
    $same('session_schedule_conflict',$tickCode($new->tick(),'2026-10-21_1430'),'new process refuses');$same([],$effects($f['gateway']),'both directions room/member zero');

    // Preserve an already announced old receipt, including repeated commands and the final second.
    $f=$make('2026-10-21T13:59:00+09:00',$spec('2026-10-21 14:00'));
    $opened=$f['service']->openAttendance('fictional-person','promised');$same('receipt_opened',$opened['code'],'existing receipt opened normally');$deadline=$opened['closes_at'];
    $new=$planOther($f,'2026-10-21 14:30');$before=$row($f['db'],$opened['session_id']);$f['gateway']->actionLog=[];
    $f['clock']->advance(60);$refresh($f);
    $same('session_schedule_conflict',$tickCode($f['service']->tick(),$opened['session_id']),'promised receipt process conflicts');
    $same('session_schedule_conflict',$tickCode($new->tick(),'2026-10-21_1430'),'new cannot act on existing active receipt');
    $same($before,$row($f['db'],$opened['session_id']),'conflict does not rewrite receipt session');
    $same('receipt_already_open',$f['service']->openAttendance('fictional-person','reuse')['code'],'existing receipt reuse remains valid');
    $same('receipt_opened',$f['service']->openAttendance('fictional-person','promised')['code'],'same interaction replay preserved');
    $same('attended',$f['service']->attend($opened['session_id'],'fictional-person','attend-during-conflict')['code'],'existing receipt can attend during conflict');
    $same([],$effects($f['gateway']),'preserved receipt produces no room/member effects');
    $f['clock']->set($date('@'.($deadline-1))->format(DATE_ATOM));$f['gateway']->setVoiceState('fictional-last',$f['config']['voice_channel_id']);
    $same('attended',$f['service']->attend($opened['session_id'],'fictional-last','last-second')['code'],'original deadline last second accepted');
    $f['clock']->advance(1);$f['gateway']->setVoiceState('fictional-late',$f['config']['voice_channel_id']);
    $same('session_closed',$f['service']->attend($opened['session_id'],'fictional-late','at-cutoff')['code'],'original cutoff exclusive');
    $same($deadline,(int)$row($f['db'],$opened['session_id'])['closes_at'],'deadline never changed');
    $same('session_schedule_conflict',$tickCode($f['service']->tick(),$opened['session_id']),'expired old side alone stays blocked');
    $newResult=$new->tick();$same('session_active',$tickCode($newResult,'2026-10-21_1430'),'new makes progress after old deadline');
    $same('started',$row($f['db'],$opened['session_id'])['status'],'old not silently closed after conflict');
    foreach($effects($f['gateway'])as$effect){$same(true,str_contains($effect['key'],'2026-10-21_1430'),'only current session can operate gate');}
    $cases[]=['case'=>'legacy-active','fixed_cutoff_preserved'=>true,'attendance_until_cutoff'=>true,'new_progress_after_old_cutoff'=>true];

    $f=$make('2026-10-21T14:29:59+09:00',$spec('2026-10-21 14:00'));
    $late=$f['service']->openAttendance('fictional-person','legacy-69-late');$deadline=$late['closes_at'];$new=$planOther($f,'2026-10-21 15:09');$f['gateway']->actionLog=[];
    $f['clock']->set('2026-10-21T14:39:00+09:00');$refresh($f);
    $same('session_schedule_conflict',$tickCode($f['service']->tick(),$late['session_id']),'legacy 69-minute opening cannot shorten late 600-second receipt');
    $same('session_schedule_conflict',$tickCode($new->tick(),'2026-10-21_1509'),'legacy 69-minute new side also stops');
    $same('attended',$f['service']->attend($late['session_id'],'fictional-person','legacy-69-attend')['code'],'legacy 69-minute original receipt remains usable');
    $same([],$effects($f['gateway']),'legacy 69-minute room/member effects zero');$same($deadline,(int)$row($f['db'],$late['session_id'])['closes_at'],'legacy 69-minute deadline unchanged');
    $f['clock']->advance(59);$same('session_active',$tickCode($new->tick(),'2026-10-21_1509'),'legacy 69-minute current resumes exactly at old cutoff');
    $cases[]=['case'=>'legacy-69-minute-late-receipt','receipt_seconds'=>600,'fixed_cutoff_preserved'=>true,'conflicting_room_member_effects'=>0];

    // Overdue unknown/pending receipts do not poison a normally separated current session.
    foreach(['uncertain','pending','sending']as$status) {
        $f=$make('2026-10-20T14:00:00+09:00',$spec('2026-10-20 14:00'));
        $old=$f['service']->openAttendance('fictional-person','old-'.$status);$id=$old['session_id'];
        $f['db']->pdo()->prepare('UPDATE receipt_announcements SET status=?,expired_at=? WHERE session_id=?')->execute([$status,$status==='pending'?$old['closes_at']:null,$id]);
        $before=$row($f['db'],$id);$notice=$f['db']->pdo()->query('SELECT * FROM receipt_announcements')->fetchAll();
        $f['clock']->set('2026-10-21T14:00:00+09:00');$current=$planOther($f,'2026-10-21 14:30');$f['gateway']->actionLog=[];
        $same($status==='pending'?'receipt_expired_superseded':'session_schedule_conflict',$tickCode($f['service']->tick(),$id),'overdue '.$status.' old side safe before transport');
        $same('session_active',$tickCode($current->tick(),'2026-10-21_1430'),'overdue '.$status.' does not block current');
        $after=$row($f['db'],$id);$same($before['closes_at'],$after['closes_at'],'overdue deadline unchanged');
        if($status==='pending'){$same('closed',$after['status'],'known-unsent expired old safely completes');}
        else{$same($before,$after,'uncertain/sending history unchanged');}
        $same($notice,$f['db']->pdo()->query('SELECT * FROM receipt_announcements')->fetchAll(),'old uncertainty/pending not reset');
        foreach($effects($f['gateway'])as$effect){$same(true,str_contains($effect['key'],'2026-10-21_1430'),'old has no cross-session room/member effect');}
    }

    // Exact 70-minute separation permits the latest ordinary start and normal closure.
    $f=$make('2026-10-21T14:29:59+09:00',$spec('2026-10-21 14:00','2026-10-21 15:10'));
    $opened=$f['service']->openAttendance('fictional-person','late-70');$same('receipt_opened',$opened['code'],'70 min late receipt admitted');$deadline=$opened['closes_at'];
    $same($f['clock']->now()->getTimestamp()+600,$deadline,'late receipt has fixed 600 seconds');
    $f['gateway']->setVoiceState('fictional-elsewhere','fictional-other-vc');
    $f['clock']->advance(540);$refresh($f);$f['service']->tick();$same($deadline-60,(int)$row($f['db'],$opened['session_id'])['warning_sent_at'],'usual warning before exact cutoff');
    $f['clock']->advance(60);$refresh($f);$same('closed',$tickCode($f['service']->tick(),$opened['session_id']),'70 min old closes normally');
    $same('fictional-other-vc',$f['gateway']->voiceState('fictional-guild','fictional-elsewhere')->channelId,'other VC remains unchanged');
    $same(1,count(array_filter($effects($f['gateway']),static fn(array $e):bool=>$e['method']==='disconnectMember')),'one target-only disconnect');
    $f['clock']->advance(1);$refresh($f);$same('session_active',$tickCode($f['service']->tick(),'2026-10-21_1510'),'70-minute next opening accepted');
    $cases[]=['case'=>'exact-70','late_start_seconds'=>1799,'receipt_seconds'=>600,'next_open_one_second_after_cutoff'=>true,'other_vc_mutations'=>0];

    $legacy=$make('2026-10-21T14:29:59+09:00',$spec('2026-10-21 14:00','2026-10-21 15:10'));
    $legacyConfig=$legacy['config'];$legacyConfig['participant_commands_enabled']=false;
    $legacyService=new RadioService($legacy['db'],$legacy['gateway'],$legacy['clock'],$legacyConfig);
    $legacyService->planSessions($date('2026-10-21 14:00'),$date('2026-10-21 14:00'));
    $same('session_schedule_conflict',$legacyService->start('2026-10-21_1400','fictional-person','legacy-900')['code'],'legacy 900-second late start cannot cross next opening');
    $same(null,$row($legacy['db'],'2026-10-21_1400')['started_at'],'rejected legacy start leaves deadline unset');
    $same([],$legacy['gateway']->actionLog,'rejected legacy start has no room/member effects');

    // A worker resuming after the next opening cannot issue an overdue old warning/closure.
    $f=$make('2026-10-21T14:29:59+09:00',$spec('2026-10-21 14:00','2026-10-21 15:10'));
    $old=$f['service']->openAttendance('fictional-person','late-resume');$before=$row($f['db'],$old['session_id']);$f['gateway']->actionLog=[];
    $f['clock']->set('2026-10-21T14:40:00+09:00');$refresh($f);$tick=$f['service']->tick();
    $same('session_schedule_conflict',$tickCode($tick,$old['session_id']),'old stop at new opening');$same('session_active',$tickCode($tick,'2026-10-21_1510'),'new starts despite old residual');
    $same($before,$row($f['db'],$old['session_id']),'late worker preserves old history');
    foreach($f['gateway']->actionLog as$effect){$same(false,str_contains($effect['key'],$old['session_id']),'no old effect after restart boundary');}
    // Reconstructing the service on the same DB preserves the explicit conflict and fixed deadline.
    $restart=new RadioService($f['db'],$f['gateway'],$f['clock'],$f['config']);$same('session_schedule_conflict',$tickCode($restart->tick(),$old['session_id']),'restart does not discard conflict');

    // Four real local processes retain the normal single receipt claim and reject legacy conflict.
    foreach([false,true]as$conflict) {
        $path=$root.'/parallel-'.(int)$conflict.'.sqlite';$schedule=$spec('2026-10-21 14:00');$f=$make('2026-10-21T13:59:00+09:00',$schedule,$path);
        if($conflict){$planOther($f,'2026-10-21 14:30');}
        $results=$parallel($path,'2026-10-21T13:59:00+09:00',$schedule,(string)(int)$conflict);
        $same($conflict?0:1,array_sum(array_column($results,'receipt_posts')),'parallel one claim or no effects');
        if($conflict){$same(['session_schedule_conflict'],array_values(array_unique(array_column($results,'code'))),'all parallel conflicted admissions rejected');}
        else{
            foreach($results as$result){$same(true,in_array($result['code'],['receipt_opened','receipt_already_open','receipt_announcement_unconfirmed'],true),'parallel replies respect sending-in-progress');}
            $stored=$f['db']->pdo()->query("SELECT result_json FROM core_interactions WHERE operation='receipt'")->fetchAll(PDO::FETCH_COLUMN);
            $codes=array_map(static fn(string $json):string=>json_decode($json,true,32,JSON_THROW_ON_ERROR)['code'],$stored);
            $same(1,count(array_filter($codes,static fn(string $code):bool=>$code==='receipt_opened')),'one durable parallel first receipt');
            $same(3,count(array_filter($codes,static fn(string $code):bool=>$code==='receipt_already_open')),'other durable parallel commands reuse');
            $same('receipt_already_open',$f['service']->openAttendance('fictional-person','parallel-retry')['code'],'all claims settled retry reuses');
        }
    }
    $cases[]=['case'=>'multiprocess','workers'=>4,'normal_receipts'=>1,'conflicting_receipts'=>0];

    // Fail the conflict-read boundary itself: no safe answer means no effect, not a permissive fallback.
    foreach(['admission','process']as$entry) {
        $path=$root.'/read-fault-'.$entry.'.sqlite';$f=$make('2026-10-21T14:00:00+09:00',$spec('2026-10-21 14:00'),$path);
        $f['service']->planSessions($date('2026-10-21 14:00'),$date('2026-10-21 14:00'));
        $fault=new ScheduleSafetyFaultPDO('sqlite:'.$path,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        (new ReflectionProperty(Database::class,'connection'))->setValue($f['db'],$fault);$fault->failNextPattern='SELECT 1 FROM sessions WHERE id!=';
        $type=null;try{if($entry==='admission'){$f['service']->openAttendance('fictional-person','read-fault');}else{$f['service']->tick();}}catch(PDOException){$type='PDOException';}
        $same('PDOException',$type,$entry.' DB exception propagates to worker/CLI boundary');$same([],$f['gateway']->actionLog,$entry.' DB read failure performs zero effects');
        $same(null,$row($f['db'],'2026-10-21_1400')['started_at'],$entry.' failure does not start receipt');
        $same(0,(int)$f['db']->pdo()->query('SELECT count(*) FROM receipt_announcements')->fetchColumn(),$entry.' failure leaves no claim');
        $result=$entry==='admission'?$f['service']->openAttendance('fictional-person','read-fault')['code']:$tickCode($f['service']->tick(),'2026-10-21_1400');
        $same($entry==='admission'?'receipt_opened':'session_active',$result,$entry.' retries normally after DB read recovery');
    }
    $cases[]=['case'=>'conflict-read-exception','entries'=>['admission','process'],'exception'=>'PDOException','effects_before_recovery'=>0,'retry_after_recovery'=>true];

    // Same operations adapter with local fake HTTP: a warning I/O crossing the next opening is stopped.
    $dir=$root.'/operations';mkdir($dir,0700);mkdir($dir.'/private',0700);$clock=new FrozenClock('2026-10-21T14:29:59+09:00');$inner=new RuntimeFixtureHttp();
    $user='800000000000001021';$inner->voices=[$user=>DiscordRestClient::VOICE_CHANNEL_ID,'900000000000000199'=>'900000000000009999'];
    $http=new class($inner,$clock) implements HttpAdapter {
        public bool $cross=false,$closeRace=false;public ?PDO $db=null;
        public function __construct(public RuntimeFixtureHttp $inner,private FrozenClock $clock){}
        public function exchange(HttpRequest $request):HttpResponse {
            $result=$this->inner->exchange($request);
            if($this->cross&&$request->method()==='POST'&&str_contains($request->body(),'閉場まであと1分')){$this->cross=false;$this->clock->set('2026-10-21T14:40:00+09:00');}
            if($this->closeRace&&str_ends_with($request->url(),'/gateway/bot')) {
                $this->closeRace=false;$this->db->exec("UPDATE sessions SET status='closed',closed_at=closes_at WHERE id='2026-10-21_1400'");
                $this->clock->set('2026-10-21T14:40:00+09:00');
            }
            return $result;
        }
    };
    $config=runtimeFixtureConfig($dir,'best_effort',true);$config['schedule']=$spec('2026-10-21 14:00','2026-10-21 15:10');
    $runtime=new OperationsRuntime($http,fn()=>$inner->transport(),$clock);$context=$runtime->build($config,static fn()=>true);
    $opened=$context->service->openAttendance($user,'ops-open');$same('receipt_opened',$opened['code'],'ordinary operations receipt');
    $clock->advance(540);$http->cross=true;$cycle=$runtime->cycle($context,static fn()=>true);
    $same(true,in_array('session_schedule_conflict',$cycle['sessions'],true),'mid-I/O next opening is explicit Runtime conflict');
    $same(0,count($inner->memberPatches()),'crossing I/O performs zero member PATCH');$same('started',$row($context->db,$opened['session_id'])['status'],'I/O crossing does not silently close old');
    $same($opened['closes_at'],(int)$row($context->db,$opened['session_id'])['closes_at'],'I/O crossing preserves cutoff');
    $same('900000000000009999',$inner->voices['900000000000000199'],'operations other VC unchanged');$context->roster->close();
    $cases[]=['case'=>'operations-io-crossing','old_member_patches'=>0,'other_vc_mutations'=>0,'fixed_deadline_preserved'=>true];

    // Independent review regression: a second worker closes the old row during roster I/O.
    $dir=$root.'/operations-closed';mkdir($dir,0700);mkdir($dir.'/private',0700);$clock2=new FrozenClock('2026-10-21T14:29:59+09:00');$inner2=new RuntimeFixtureHttp();
    $inner2->voices=[$user=>DiscordRestClient::VOICE_CHANNEL_ID];$wrapper=get_class($http);$http2=new $wrapper($inner2,$clock2);
    $config2=runtimeFixtureConfig($dir,'best_effort');$config2['schedule']=$config['schedule'];$runtime2=new OperationsRuntime($http2,fn()=>$inner2->transport(),$clock2);$context2=$runtime2->build($config2,static fn()=>true);$http2->db=$context2->db->pdo();
    $old=$context2->service->openAttendance($user,'closed-race');$clock2->advance(540);$context2->service->tick();$clock2->advance(60);$http2->closeRace=true;$before=count($inner2->calls);
    $tick=$context2->service->tick();$mutations=array_filter(array_slice($inner2->calls,$before),static fn(HttpRequest $r):bool=>in_array($r->method(),['PUT','PATCH'],true));
    $same(0,count($mutations),'closed row seen by dispatch prevents old room/member mutations');$same(DiscordRestClient::VOICE_CHANNEL_ID,$inner2->voices[$user],'closed race cannot disconnect current occupant');
    $same('closed',$row($context2->db,$old['session_id'])['status'],'other worker completed state preserved');$same($old['closes_at'],(int)$row($context2->db,$old['session_id'])['closes_at'],'closed race original cutoff preserved');
    $next=$context2->service->tick();$same('session_active',$tickCode($next,'2026-10-21_1510'),'current opens after other worker completed old');$same(0,count($inner2->memberPatches()),'no cross-session member PATCH on resume');$context2->roster->close();
    $cases[]=['case'=>'other-worker-closed-during-roster','old_room_mutations'=>0,'old_member_patches'=>0,'current_next_open'=>true];

    $artifact=getenv('RADIO_STAMP_TEST_ARTIFACT_DIR');if($artifact===false||$artifact===''){$artifact=getenv('RADIO_STAMP_TEST_ARTIFACT_DIR');}
    if(!is_dir($artifact)&&!mkdir($artifact,0700,true)&&!is_dir($artifact)){throw new RuntimeException('Cannot create A3 artifact directory');}
    $hashes=[];foreach(['src/Core/Schedule.php','src/Core/RadioService.php','tests/schedule_safety.php']as$path){$hashes[$path]=hash_file('sha256',dirname(__DIR__).'/'.$path);}
    file_put_contents($artifact.'/schedule-safety.safe.json',json_encode(['offline_only'=>true,'real_api_calls'=>0,'assertions'=>$checks,'php'=>PHP_VERSION,
        'sqlite'=>$fixtures[0]['db']->pdo()->query('SELECT sqlite_version()')->fetchColumn(),'source_sha256'=>$hashes,'cases'=>$cases,
        'limits'=>['stopped_schedule_replacement','removed_session_components_remain_rejected','no_atomic_guarantee_across_database_snapshot_and_external_io']],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
    echo "Schedule safety: $checks assertions passed.\n";
} finally {
    // All database/private state belongs to this test's random temporary tree, never an application DB.
    $fixtures=[];
}
