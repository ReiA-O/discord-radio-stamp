<?php
declare(strict_types=1);
require_once __DIR__.'/artifact_directory.php';
require dirname(__DIR__).'/bootstrap.php';

use RadioStamp\Core\{Database,FrozenClock,RadioService,Schedule,StampPolicy,TitlePolicy,ParticipationDays};
use RadioStamp\Discord\MockGateway;

function fiveSlotConfig(?array $schedule=null,bool $receipt=true):array
{
    $config=['mode'=>'mock','guild_id'=>'fictional-five-guild','voice_channel_id'=>'fictional-five-voice',
        'text_channel_id'=>'fictional-five-guide','records_channel_id'=>'fictional-five-notices',
        'participant_commands_enabled'=>$receipt,'member_actions_enabled'=>false,'role_ids'=>[],
        'live_automation_enabled'=>false,'achievement_notifications_enabled'=>false];
    if($schedule!==null){$config['schedule']=$schedule;}
    return $config;
}
function fiveSlotService(Database $db,FrozenClock $clock,?array $schedule=null,bool $receipt=true):array
{
    $gateway=new MockGateway($clock);$service=new RadioService($db,$gateway,$clock,fiveSlotConfig($schedule,$receipt));
    return [$service,$gateway];
}

// Children use only the parent-created disposable database and the injected MockGateway.
if(($argv[1]??null)==='--plan-child'){
    $db=new Database($argv[2],5000,false);$at=new DateTimeImmutable($argv[3],new DateTimeZone('Asia/Tokyo'));
    $spec=['daily_times'=>[],'one_off_times'=>[['at'=>$at->format('Y-m-d H:i'),'slot_id'=>'morning']]];
    [$service]=fiveSlotService($db,new FrozenClock($at),$spec);
    try{$service->planSessions($at,$at);$code='planned';}catch(RuntimeException $error){$code=$error->getMessage();}
    echo json_encode(['code'=>$code],JSON_THROW_ON_ERROR);exit(0);
}

$checks=0;$scenarios=[];
$same=static function(mixed $expected,mixed $actual,string $why)use(&$checks):void{
    ++$checks;if($expected!==$actual){throw new RuntimeException('Five slots: '.$why.' expected='.json_encode($expected,JSON_UNESCAPED_UNICODE).' actual='.json_encode($actual,JSON_UNESCAPED_UNICODE));}
};
$assert=static function(bool $actual,string $why)use($same):void{$same(true,$actual,$why);};
$at=static fn(string $value):DateTimeImmutable=>new DateTimeImmutable($value,new DateTimeZone('Asia/Tokyo'));
$expected=[
    'midnight'=>['time'=>'00:00','stamp'=>'IC'],
    'dawn'=>['time'=>'04:00','stamp'=>'コンデンサ'],
    'morning'=>['time'=>'07:00','stamp'=>'LED'],
    'midday'=>['time'=>'12:30','stamp'=>'プラグ'],
    'night'=>['time'=>'22:00','stamp'=>'電池'],
];
$root=sys_get_temp_dir().'/radio-five-slot-policy-'.bin2hex(random_bytes(8));mkdir($root,0700);
$row=static function(Database $db,string $id):array{$query=$db->pdo()->prepare('SELECT * FROM sessions WHERE id=?');$query->execute([$id]);return $query->fetch()?:[];};
$oneOff=static fn(string $instant,string $slot):array=>['daily_times'=>[],'one_off_times'=>[['at'=>$instant,'slot_id'=>$slot]]];
$attend=static function(RadioService $service,MockGateway $gateway,FrozenClock $clock,string $instant,string $user,string $label)use($same):array{
    $clock->set($instant);$gateway->setVoiceState($user,'fictional-five-voice');
    $receipt=$service->openAttendance($user,'five-open-'.$label);$same('receipt_opened',$receipt['code'],$label.' opens a fixed receipt');
    $record=$service->attend($receipt['session_id'],$user,'five-attend-'.$label);$same('attended',$record['code'],$label.' registers once');
    return $record;
};

try{
    $schedule=new Schedule();$normal=$schedule->between($at('2026-10-21 00:00'),$at('2026-10-21 23:59:59'));
    $same(array_column($expected,'time'),array_map(static fn(DateTimeImmutable $instant):string=>$instant->format('H:i'),$normal),'five approved daily times');
    $same(array_combine(array_keys($expected),array_column($expected,'stamp')),StampPolicy::SLOT_STAMPS,'five approved slot to component assignments');
    foreach($normal as$index=>$instant){
        $slot=array_keys($expected)[$index];$id=$instant->format('Y-m-d_Hi');
        $same($slot,$schedule->slotForSession($id),$slot.' has stable logical identity');
        $same(['slot_id'=>$slot,'stamp'=>$expected[$slot]['stamp']],$schedule->bindingForSession($id),$slot.' binding is explicit');
        $same($id,$schedule->initialSessionAt($instant->getTimestamp()-1800),$slot.' opening is inclusive');
        $same(null,$schedule->initialSessionAt($instant->getTimestamp()-1801),$slot.' before opening is unavailable');
        $same(null,$schedule->initialSessionAt($instant->getTimestamp()+1800),$slot.' initial end is exclusive');
    }
    $same(null,$schedule->slotForSession('2026-10-21_1231'),'unknown minute has no invented slot');
    $same(null,$schedule->bindingForSession('2026-02-30_1230'),'invalid date has no binding');
    $same('2026-10-21_1230',$schedule->initialSessionAt($at('2026-10-21 12:00')->getTimestamp()),'lunch opens exactly at 12:00');
    $same(null,$schedule->initialSessionAt($at('2026-10-21 13:00')->getTimestamp()),'unstarted lunch admission ends exactly at 13:00');
    $scenarios['normal_five_schedule']=true;

    // Independent literal expectations, not a rotation derived from the attendance ordinal.
    $db=new Database(':memory:');$clock=new FrozenClock('2026-10-21 00:00:00+09:00');[$service,$gateway]=fiveSlotService($db,$clock);
    $service->planSessions($at('2026-10-21 00:00'),$at('2026-10-21 23:59:59'));
    foreach($expected as$slot=>$value){
        $planned=$row($db,'2026-10-21_'.str_replace(':','',$value['time']));
        $same($slot,$planned['slot_id'],$slot.' logical ID is saved before anyone attends');
        $same($value['stamp'],$planned['stamp'],$slot.' component is saved before anyone attends');
        $same(0,(int)$db->pdo()->query("SELECT COUNT(*) FROM attendance WHERE session_id='".$planned['id']."'")->fetchColumn(),$slot.' snapshot check actually precedes attendance');
        $record=$attend($service,$gateway,$clock,'2026-10-21 '.$value['time'].':00+09:00','fictional-all-five',$slot);
        $same($value['stamp'],$record['stamp'],$slot.' has its own component');$same(1,$record['total_days'],$slot.' is still one participation day');
        $same(null,$record['new_day_milestone'],$slot.' first-day record grants no day milestone');$same(null,$record['new_memorial'],$slot.' compatibility alias grants no first-day memorial');
        $snapshot=$row($db,$record['session_id']);$same($slot,$snapshot['slot_id'],$slot.' logical ID is persisted');$same($value['stamp'],$snapshot['stamp'],$slot.' component is persisted before attendance');
    }
    $data=$service->cardData('fictional-all-five','架空の5枠参加者','2026-10');
    $same(5,$data['total_count'],'five sessions yield five attendance records');$same(1,$data['total_days'],'five same-day sessions yield one day');
    $same(['IC','コンデンサ','LED','プラグ','電池'],$data['days']['2026-10-21'],'saved card history contains all five components');
    $same('⚡ はじめの通電',$data['latest_title'],'first title remains independent of the five component kinds');
    $same([],$data['awards'],'five attendances on the first day expose no day-benefit button');
    $scenarios['five_attendance_one_day']=true;

    // Missing earlier slots cannot shift the component earned at night or on the following day.
    $missingDb=new Database(':memory:');$missingClock=new FrozenClock('2026-10-21 22:00:00+09:00');[$missing,$missingGateway]=fiveSlotService($missingDb,$missingClock);
    $record=$attend($missing,$missingGateway,$missingClock,'2026-10-21 22:00:00+09:00','fictional-skips','skip-to-night');
    $same('電池',$record['stamp'],'first attendance at night is a battery, never a cyclic first IC');
    $same('⚡ はじめの通電',$record['latest_title'],'initial lightning title remains at the first count');
    $record=$attend($missing,$missingGateway,$missingClock,'2026-10-22 00:00:00+09:00','fictional-skips','following-midnight');
    $same('IC',$record['stamp'],'second attendance at midnight is IC');$same(2,$record['total_days'],'crossing actual JST day adds one day');
    $scenarios['missing_slots_never_rotate_components']=true;

    // The same logical identities survive changing only their configured times and input ordering.
    $shiftedTimes=['midnight'=>'01:15','dawn'=>'05:20','morning'=>'08:30','midday'=>'14:00','night'=>'20:30'];
    $shiftedEntries=[];foreach(array_reverse(array_keys($expected))as$slot){$shiftedEntries[]=['time'=>$shiftedTimes[$slot],'slot_id'=>$slot];}
    $shiftedSpec=['daily_times'=>$shiftedEntries,'one_off_times'=>[]];$shiftedSchedule=new Schedule($shiftedSpec);
    $shiftedDb=new Database(':memory:');$shiftedClock=new FrozenClock('2026-10-21 01:15:00+09:00');[$shifted,$shiftedGateway]=fiveSlotService($shiftedDb,$shiftedClock,$shiftedSpec);
    foreach($expected as$slot=>$value){
        $instant='2026-10-21 '.$shiftedTimes[$slot].':00+09:00';$id=$at($instant)->format('Y-m-d_Hi');
        $same($slot,$shiftedSchedule->slotForSession($id),$slot.' survives sorting changed times');
        $record=$attend($shifted,$shiftedGateway,$shiftedClock,$instant,'fictional-shifted',$slot.'-shifted');$same($value['stamp'],$record['stamp'],$slot.' changed time keeps its component');
    }
    $same($data['days']['2026-10-21'],$shifted->cardData('fictional-shifted','架空の時刻変更','2026-10')['days']['2026-10-21'],'normal and shifted business paths store the same five component sequence');
    $scenarios['all_five_shifted_without_rebinding']=true;

    // One-off replacement is keyed by logical slot and JST day, not its position among times.
    $replacementSpec=Schedule::DEFAULT_SPEC;$replacementSpec['one_off_times']=[['at'=>'2026-10-21 08:15','slot_id'=>'morning']];
    $replacement=new Schedule($replacementSpec);$replacementDay=$replacement->between($at('2026-10-21 00:00'),$at('2026-10-21 23:59:59'));
    $same(['00:00','04:00','08:15','12:30','22:00'],array_map(static fn(DateTimeImmutable $instant):string=>$instant->format('H:i'),$replacementDay),'one-off replaces exactly one daily occurrence');
    $same(false,$replacement->contains('2026-10-21_0700'),'original occurrence is suppressed on replacement day');
    $same(true,$replacement->contains('2026-10-21_0815'),'replacement occurrence is admitted');
    $same('LED',$replacement->bindingForSession('2026-10-21_0815')['stamp'],'replacement keeps the original slot component');
    $same(true,$replacement->contains('2026-10-20_0700'),'previous day remains unchanged');$same(true,$replacement->contains('2026-10-22_0700'),'following day remains unchanged');
    foreach(['2026-11-01','2026-12-31','2028-02-29']as$day){
        $spec=Schedule::DEFAULT_SPEC;$spec['one_off_times']=[['at'=>$day.' 00:07','slot_id'=>'midnight']];$calendar=new Schedule($spec);
        $same(false,$calendar->contains($day.'_0000'),$day.' original midnight suppressed');$same('IC',$calendar->bindingForSession($day.'_0007')['stamp'],$day.' calendar replacement preserves midnight identity');
    }
    $scenarios['daily_one_off_replacement_and_calendar_boundaries']=true;

    foreach([
        ['daily_times'=>[['time'=>'07:00','slot_id'=>'invented']]],
        ['daily_times'=>[['time'=>'07:00','slot_id'=>12]]],
        ['daily_times'=>[['time'=>'07:00','slot_id'=>'morning','extra'=>true]]],
        ['daily_times'=>[['time'=>'07:00','slot_id'=>'morning'],['time'=>'08:20','slot_id'=>'morning']]],
        ['daily_times'=>[['time'=>'07:00','slot_id'=>'morning'],['time'=>'07:00','slot_id'=>'midday']]],
        ['daily_times'=>[],'one_off_times'=>[['at'=>'2026-10-21 07:00','slot_id'=>'morning'],['at'=>'2026-10-21 08:20','slot_id'=>'morning']]],
        ['daily_times'=>[],'one_off_times'=>[['at'=>'2026-10-21 07:00','slot_id'=>'morning'],['at'=>'2026-10-21 07:00','slot_id'=>'midday']]],
    ]as$index=>$bad){$rejected=false;try{new Schedule($bad);}catch(InvalidArgumentException){$rejected=true;}$same(true,$rejected,'invalid or ambiguous slot configuration '.$index.' rejected');}
    foreach(['IC','コンデンサ','LED','プラグ','電池']as$kind){$same(true,StampPolicy::validStamp($kind),'supported saved component '.$kind);}
    foreach(['⚡','IC ',null,123,'invented']as$kind){$same(false,StampPolicy::validStamp($kind),'unsupported component is never silently converted');}
    $scenarios['strict_identity_validation']=true;

    // An unknown legacy one-off remains parseable, but cannot create an unbound operational session.
    $unknownSpec=['daily_times'=>[],'one_off_times'=>['2026-10-21 14:12']];$unknownSchedule=new Schedule($unknownSpec);
    $same(null,$unknownSchedule->bindingForSession('2026-10-21_1412'),'legacy arbitrary time has no guessed logical identity');
    $unknownDb=new Database(':memory:');$unknownClock=new FrozenClock('2026-10-21 14:12:00+09:00');[$unknown,$unknownGateway]=fiveSlotService($unknownDb,$unknownClock,$unknownSpec);
    $code=null;try{$unknown->planSessions($at('2026-10-21 14:12'),$at('2026-10-21 14:12'));}catch(RuntimeException $error){$code=$error->getMessage();}
    $same('slot_binding_required',$code,'new unbound plan fails with a fixed cause');$same(0,(int)$unknownDb->pdo()->query('SELECT COUNT(*) FROM sessions')->fetchColumn(),'unbound plan leaves no partial session');
    $unknownGateway->setVoiceState('fictional-unknown','fictional-five-voice');$result=$unknown->openAttendance('fictional-unknown','unknown-receipt');
    $same('slot_binding_required',$result['code'],'unbound receipt fails before external side effects');
    $same([],$unknownGateway->actionLog,'unknown binding sends no message or room/member action');$same(0,(int)$unknownDb->pdo()->query('SELECT COUNT(*) FROM attendance')->fetchColumn(),'unknown binding records no attendance');
    $scenarios['unknown_legacy_time_fails_before_effects']=true;

    // A persisted binding cannot be silently replaced by a new configuration at the same datetime.
    $boundDb=new Database(':memory:');$boundClock=new FrozenClock('2026-10-21 07:00:00+09:00');[$bound,$boundGateway]=fiveSlotService($boundDb,$boundClock,$oneOff('2026-10-21 07:00','morning'));
    $boundGateway->setVoiceState('fictional-bound','fictional-five-voice');$opened=$bound->openAttendance('fictional-bound','bound-open');$same('receipt_opened',$opened['code'],'bound receipt opens normally');
    $beforeBinding=$row($boundDb,'2026-10-21_0700');[$wrong,$wrongGateway]=fiveSlotService($boundDb,$boundClock,$oneOff('2026-10-21 07:00','midday'));
    $wrongGateway->setVoiceState('fictional-bound','fictional-five-voice');$wrong->planSessions($at('2026-10-21 07:00'),$at('2026-10-21 07:00'));
    $same($beforeBinding,$row($boundDb,'2026-10-21_0700'),'existing datetime snapshot is never overwritten');
    $same('session_slot_binding_conflict',$wrong->openAttendance('fictional-bound','wrong-open')['code'],'mismatched receipt cannot reuse a different promise');
    $same('session_slot_binding_conflict',$wrong->attend('2026-10-21_0700','fictional-bound','wrong-attend')['code'],'mismatched attendance cannot obtain a different component');
    [$wrongStart,$wrongStartGateway]=fiveSlotService($boundDb,$boundClock,$oneOff('2026-10-21 07:00','midday'),false);
    $wrongStartGateway->setVoiceState('fictional-bound','fictional-five-voice');
    $same('session_slot_binding_conflict',$wrongStart->start('2026-10-21_0700','fictional-bound','wrong-start')['code'],'mismatched legacy-start route cannot bypass the shared binding guard');
    $same([],$wrongStartGateway->actionLog,'mismatched start route causes no effect');
    $tick=$wrong->tick();$tickCodes=[];foreach($tick['sessions']as$entry){$tickCodes[$entry['session_id']]=$entry['code'];}
    $same('session_slot_binding_conflict',$tickCodes['2026-10-21_0700'],'mismatched process stops before effects');
    $same([],$wrongGateway->actionLog,'mismatch sends no external effect');$same(0,(int)$boundDb->pdo()->query('SELECT COUNT(*) FROM attendance')->fetchColumn(),'mismatch adds no record');
    $same($beforeBinding,$row($boundDb,'2026-10-21_0700'),'mismatch preserves cutoff and original binding');
    $same('LED',$bound->attend('2026-10-21_0700','fictional-bound','original-attend')['stamp'],'original owner configuration still uses the persisted component');
    $boundDb->pdo()->exec("UPDATE sessions SET stamp='電池' WHERE id='2026-10-21_0700'");
    $corruptSnapshot=$row($boundDb,'2026-10-21_0700');$boundGateway->actionLog=[];$boundGateway->setVoiceState('fictional-corrupt','fictional-five-voice');
    $same('session_slot_binding_conflict',$bound->openAttendance('fictional-corrupt','corrupt-open')['code'],'correct slot ID with a different saved kind cannot reuse a receipt');
    $same('session_slot_binding_conflict',$bound->attend('2026-10-21_0700','fictional-corrupt','corrupt-attend')['code'],'a valid but inconsistent saved kind fails before attendance');
    $corruptTick=$bound->tick();$corruptCodes=[];foreach($corruptTick['sessions']as$entry){$corruptCodes[$entry['session_id']]=$entry['code'];}
    $same('session_slot_binding_conflict',$corruptCodes['2026-10-21_0700'],'inconsistent saved kind also stops the process route');
    $same($corruptSnapshot,$row($boundDb,'2026-10-21_0700'),'guard never repairs a mismatched saved kind silently');
    $same([],$boundGateway->actionLog,'inconsistent saved kind causes no external side effect');
    $same([['stamp'=>'LED']],$boundDb->pdo()->query('SELECT stamp FROM attendance')->fetchAll(),'inconsistent session metadata never rewrites the existing attendance kind');
    $scenarios['snapshot_conflict_is_fail_closed']=true;

    // Stopped time replacement may remove the old ID, but never invalidates its exact saved replay.
    $replayDb=new Database(':memory:');$replayClock=new FrozenClock('2026-10-21 07:00:00+09:00');
    [$original,$originalGateway]=fiveSlotService($replayDb,$replayClock,$oneOff('2026-10-21 07:00','morning'),false);
    $original->planSessions($at('2026-10-21 07:00'),$at('2026-10-21 07:00'));$originalGateway->setVoiceState('fictional-replay','fictional-five-voice');
    $savedStart=$original->start('2026-10-21_0700','fictional-replay','saved-start');$same('started',$savedStart['code'],'replay fixture starts once');
    $savedAttendance=$original->attend('2026-10-21_0700','fictional-replay','saved-attend');$same('attended',$savedAttendance['code'],'replay fixture attends once');
    $replayBefore=[];foreach(['sessions','attendance','participants','core_interactions','core_effects']as$table){$replayBefore[$table]=$replayDb->pdo()->query('SELECT * FROM '.$table)->fetchAll();}
    $replayClock->set('2026-10-21 09:00:00+09:00');[$removed,$removedGateway]=fiveSlotService($replayDb,$replayClock,$oneOff('2026-10-21 08:30','morning'),false);
    $same($savedStart,$removed->start('2026-10-21_0700','fictional-replay','saved-start'),'exact saved start replays after its time is removed');
    $same($savedAttendance,$removed->attend('2026-10-21_0700','fictional-replay','saved-attend'),'exact saved attendance replays after its time is removed');
    $same('interaction_conflict',$removed->attend('2026-10-21_0700','fictional-other','saved-attend')['code'],'saved result stays bound to its original actor');
    $same('interaction_conflict',$removed->start('2026-10-21_0700','fictional-replay','saved-attend')['code'],'saved result stays bound to its original operation');
    $same('session_not_found',$removed->start('2026-10-21_0700','fictional-replay','removed-new-start')['code'],'new removed-ID start is rejected');
    $same('session_not_found',$removed->attend('2026-10-21_0700','fictional-replay','removed-new-attend')['code'],'new removed-ID attendance is rejected');
    $same([],$removedGateway->actionLog,'replays and removed-ID refusals cause no external side effects');
    foreach($replayBefore as$table=>$records){$same($records,$replayDb->pdo()->query('SELECT * FROM '.$table)->fetchAll(),'removed-ID replay/refusal leaves '.$table.' unchanged');}
    $scenarios['removed_ids_preserve_exact_replays_only']=true;

    // Expiry is local cleanup: a missing/currently-invalid binding must not keep an expired lease alive.
    foreach(['null','mismatch','removed']as$case){
        $expiryDb=new Database(':memory:');$expiryClock=new FrozenClock('2026-10-21 07:00:00+09:00');$now=$expiryClock->now()->getTimestamp();
        [$expiryOriginal]=fiveSlotService($expiryDb,$expiryClock,$oneOff('2026-10-21 07:00','morning'));
        $expiryOriginal->planSessions($at('2026-10-21 07:00'),$at('2026-10-21 07:00'));
        if($case==='null'){$expiryDb->pdo()->exec("UPDATE sessions SET slot_id=NULL,stamp=NULL");}
        if($case==='mismatch'){$expiryDb->pdo()->exec("UPDATE sessions SET stamp='電池'");}
        [$expiryService,$expiryGateway]=fiveSlotService($expiryDb,$expiryClock,$oneOff($case==='removed'?'2026-10-21 08:30':'2026-10-21 07:00','morning'));
        $expiryDb->pdo()->prepare("UPDATE sessions SET status='started',started_at=?,closes_at=?")->execute([$now-600,$now-1]);
        $expirySession=$row($expiryDb,'2026-10-21_0700');$key='five-expired-'.$case;$payload=json_encode(['session_id'=>'2026-10-21_0700'],JSON_THROW_ON_ERROR);
        $expiryDb->pdo()->prepare("INSERT INTO core_effects(effect_key,kind,payload_json,status,attempts,lease_until,lease_owner,expires_at) VALUES(?,'warning',?,'pending',2,?,'fictional-held',?)")->execute([$key,$payload,$now+100,$now-1]);
        $calls=0;$send=static function(string $unused)use(&$calls):bool{++$calls;return true;};$dispatch=new ReflectionMethod(RadioService::class,'dispatch');
        $same(false,$dispatch->invoke($expiryService,$key,$send),$case.' expired effect returns false without dispatch');
        $expired=$expiryDb->pdo()->query('SELECT * FROM core_effects')->fetch();$same($now,(int)$expired['cancelled_at'],$case.' expired effect is cancelled locally');
        $same(0,(int)$expired['lease_until'],$case.' expired effect lease is cleared');$same(null,$expired['lease_owner'],$case.' expired effect owner is cleared');
        $same(2,(int)$expired['attempts'],$case.' expired effect adds no delivery attempt');$same(0,$calls,$case.' expired effect never invokes transport');
        $same($expirySession,$row($expiryDb,'2026-10-21_0700'),$case.' expiry never rewrites session metadata');
        $doneKey='five-done-'.$case;$expiryDb->pdo()->prepare("INSERT INTO core_effects(effect_key,kind,payload_json,status,completed_at,expires_at) VALUES(?,'warning',?,'done',?,?)")->execute([$doneKey,$payload,$now-100,$now-1]);
        $doneBefore=$expiryDb->pdo()->query("SELECT * FROM core_effects WHERE status='done'")->fetch();
        $same(true,$dispatch->invoke($expiryService,$doneKey,$send),$case.' completed effect keeps its known success');
        $same($doneBefore,$expiryDb->pdo()->query("SELECT * FROM core_effects WHERE status='done'")->fetch(),$case.' completed effect is not cancelled or retried');$same(0,$calls,$case.' completed effect never resends');
        $expiryDb->pdo()->prepare("INSERT INTO receipt_announcements(session_id,status,created_at) VALUES('2026-10-21_0700','pending',?)")->execute([$now-600]);
        $flush=new ReflectionMethod(RadioService::class,'flushReceiptAnnouncement');$same(false,$flush->invoke($expiryService,'2026-10-21_0700'),$case.' expired known-unsent receipt returns no-send');
        $receiptRow=$expiryDb->pdo()->query('SELECT * FROM receipt_announcements')->fetch();$same($now,(int)$receiptRow['expired_at'],$case.' expired known-unsent receipt is marked locally');
        $same('pending',$receiptRow['status'],$case.' expired no-send does not invent transport success');
        $same(null,$receiptRow['attempted_at'],$case.' expired no-send never claims transport');$same([],$expiryGateway->actionLog,$case.' local cleanup causes no gateway side effect');
        $same($expirySession,$row($expiryDb,'2026-10-21_0700'),$case.' receipt cleanup preserves original fixed cutoff');
    }
    $scenarios['expired_and_done_local_cleanup_survives_binding_changes']=true;

    // Migrate an actual old schema locally; never rewrite its saved kinds, IDs, or old session values.
    $legacyPath=$root.'/old-schema.sqlite';$old=new PDO('sqlite:'.$legacyPath,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $old->exec("CREATE TABLE sessions(id TEXT PRIMARY KEY,scheduled_at INTEGER NOT NULL UNIQUE,count_date TEXT NOT NULL,opens_at INTEGER NOT NULL,unstarted_closes_at INTEGER NOT NULL,status TEXT NOT NULL,started_at INTEGER,started_by TEXT,closes_at INTEGER,warning_sent_at INTEGER,closed_at INTEGER);
CREATE TABLE participants(user_id TEXT PRIMARY KEY,total_count INTEGER NOT NULL DEFAULT 0,latest_threshold INTEGER,latest_title TEXT);
CREATE TABLE attendance(session_id TEXT NOT NULL,user_id TEXT NOT NULL,count_date TEXT NOT NULL,attended_at INTEGER NOT NULL,ordinal INTEGER NOT NULL,stamp TEXT NOT NULL,PRIMARY KEY(session_id,user_id),UNIQUE(user_id,ordinal));
CREATE TABLE memorial_awards(user_id TEXT NOT NULL,milestone INTEGER NOT NULL,reached_at INTEGER NOT NULL,PRIMARY KEY(user_id,milestone));
CREATE TABLE core_interactions(id TEXT PRIMARY KEY,operation TEXT NOT NULL,session_id TEXT NOT NULL,user_id TEXT NOT NULL,result_json TEXT NOT NULL,created_at INTEGER NOT NULL);");
    $old->exec("INSERT INTO participants VALUES('fictional-legacy',3,1,'legacy saved title')");
    foreach(['IC','コンデンサ','LED']as$index=>$kind){$instant=$at('2026-10-'.sprintf('%02d',$index+1).' 07:00');$id=$instant->format('Y-m-d_Hi');$seconds=$instant->getTimestamp();$day=$instant->format('Y-m-d');
        $old->prepare("INSERT INTO sessions VALUES(?,?,?,?,?,'closed',?,'legacy-owner',?,?,?)")->execute([$id,$seconds,$day,$seconds-1800,$seconds+1800,$seconds,$seconds+600,$seconds+540,$seconds+600]);
        $old->prepare('INSERT INTO attendance VALUES(?,?,?,?,?,?)')->execute([$id,'fictional-legacy',$day,$seconds,$index+1,$kind]);
    }
    $legacyFuture=$at('2026-10-21 07:00')->getTimestamp();$old->prepare("INSERT INTO sessions(id,scheduled_at,count_date,opens_at,unstarted_closes_at,status) VALUES('2026-10-21_0700',?,'2026-10-21',?,?,'planned')")->execute([$legacyFuture,$legacyFuture-1800,$legacyFuture+1800]);
    $old->exec("INSERT INTO memorial_awards VALUES('fictional-legacy',30,1000),('fictional-legacy',1,900)");
    $oldDayOneResult=['ok'=>true,'code'=>'attended','session_id'=>'2026-10-01_0700','count_date'=>'2026-10-01','stamp'=>'IC',
        'total_count'=>1,'latest_title'=>'⚡ はじめの通電','title_basis'=>'attendance_count','new_memorial'=>1,'new_day_milestone'=>1,
        'total_days'=>1,'month_days'=>1,'participation_date'=>'2026-10-01'];
    $old->prepare("INSERT INTO core_interactions VALUES('fictional-legacy-day-one','attend','2026-10-01_0700','fictional-legacy',?,900)")->execute([json_encode($oldDayOneResult,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
    $oldRows=[];foreach(['sessions','participants','attendance','memorial_awards','core_interactions']as$table){$oldRows[$table]=$old->query('SELECT * FROM '.$table)->fetchAll();}$old=null;
    $legacyDb=new Database($legacyPath);$legacyClock=new FrozenClock('2026-10-21 07:00:00+09:00');[$legacyService,$legacyGateway]=fiveSlotService($legacyDb,$legacyClock);
    $sessionColumns='id,scheduled_at,count_date,opens_at,unstarted_closes_at,status,started_at,started_by,closes_at,warning_sent_at,closed_at';
    $same($oldRows['sessions'],$legacyDb->pdo()->query('SELECT '.$sessionColumns.' FROM sessions')->fetchAll(),'schema migration keeps every old session field and ID');
    foreach(['participants','attendance','memorial_awards','core_interactions']as$table){$same($oldRows[$table],$legacyDb->pdo()->query('SELECT * FROM '.$table)->fetchAll(),'migration preserves legacy '.$table.' rows');}
    $same(4,(int)$legacyDb->pdo()->query('SELECT COUNT(*) FROM sessions WHERE slot_id IS NULL AND stamp IS NULL')->fetchColumn(),'old rows are not backfilled from present configuration');
    $legacyService->planSessions($at('2026-10-21 07:00'),$at('2026-10-21 07:00'));$legacyGateway->setVoiceState('fictional-new','fictional-five-voice');
    $same('slot_binding_required',$legacyService->openAttendance('fictional-new','old-unbound')['code'],'old unbound future row cannot grant a guessed component');
    [$legacyStart,$legacyStartGateway]=fiveSlotService($legacyDb,$legacyClock,null,false);$legacyStartGateway->setVoiceState('fictional-new','fictional-five-voice');
    $same('slot_binding_required',$legacyStart->start('2026-10-21_0700','fictional-new','old-unbound-start')['code'],'old unbound start also refuses a guessed component');
    $same([],$legacyStartGateway->actionLog,'unbound start causes no effect');
    $same([],$legacyGateway->actionLog,'old unbound future session causes no effect');
    $saved=$legacyService->cardData('fictional-legacy','架空の旧履歴','2026-10');$same(['2026-10-01'=>['IC'],'2026-10-02'=>['コンデンサ'],'2026-10-03'=>['LED']],$saved['days'],'legacy stored kinds remain their original kinds despite current slots');
    $same([],$saved['awards'],'old stored milestone 1 and 30 records cannot invent a current day benefit');
    $same($oldDayOneResult,$legacyService->attend('2026-10-01_0700','fictional-legacy','fictional-legacy-day-one'),'exact historical result keeps its old first-day milestone without reissuing it');
    $same([],$legacyService->cardData('fictional-legacy','架空の旧履歴','2026-10')['awards'],'historical replay never restores the removed benefit in current eligibility');
    foreach(['participants','attendance','memorial_awards']as$table){$same($oldRows[$table],$legacyDb->pdo()->query('SELECT * FROM '.$table)->fetchAll(),'reads and rejected admission preserve legacy '.$table.' rows');}
    $same($oldRows['core_interactions'],$legacyDb->pdo()->query("SELECT * FROM core_interactions WHERE id='fictional-legacy-day-one'")->fetchAll(),'legacy first-day interaction result stays byte-for-byte unchanged in its own record');
    $same($oldRows['sessions'],$legacyDb->pdo()->query('SELECT '.$sessionColumns.' FROM sessions')->fetchAll(),'legacy rejected admission never rewrites an old session');
    $scenarios['old_schema_and_history_preserved']=true;

    // Concurrent stopped configurations cannot create two new occurrences for one logical slot/day.
    $racePath=$root.'/plan-race.sqlite';$raceDb=new Database($racePath);$children=[];
    foreach(['2026-10-21 07:00','2026-10-21 08:20','2026-10-21 07:00','2026-10-21 08:20']as$instant){
        $command=[PHP_BINARY];if(($ini=php_ini_loaded_file())!==false){array_push($command,'-c',$ini);}array_push($command,__FILE__,'--plan-child',$racePath,$instant);
        $pipes=[];$process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);
        if(!is_resource($process)){throw new RuntimeException('Five-slot local planner child unavailable.');}fclose($pipes[0]);$children[]=[$process,$pipes];
    }
    $raceCodes=[];foreach($children as[$process,$pipes]){$out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$same(0,proc_close($process),'local planner exits normally');$same('',$error,'local planner emits no diagnostics');$raceCodes[]=json_decode($out,true,16,JSON_THROW_ON_ERROR)['code'];}
    $same(1,(int)$raceDb->pdo()->query("SELECT COUNT(*) FROM sessions WHERE count_date='2026-10-21' AND slot_id='morning'")->fetchColumn(),'only one occurrence is persisted per logical slot and day');
    $same(2,count(array_filter($raceCodes,static fn(string $code):bool=>$code==='planned')),'one chosen datetime and its same-ID retry succeed');
    $same(2,count(array_filter($raceCodes,static fn(string $code):bool=>$code==='session_slot_binding_conflict')),'competing datetime planners fail explicitly');
    $same('LED',$raceDb->pdo()->query('SELECT stamp FROM sessions')->fetchColumn(),'planner race cannot change fixed component');
    $scenarios['parallel_planning_preserves_one_logical_occurrence']=true;

    $same([1=>'⚡ はじめの通電',40=>'🔌 接続完了',80=>'⚙️ 暖機運転',120=>'💻 起動完了',160=>'🔋 安定稼働',200=>'🔧 最適化中',240=>'📡 同期完了',280=>'🚀 定格突破',320=>'🌟 高効率運転',365=>'🏆 永年稼働'],TitlePolicy::TITLES,'approved count title thresholds remain unchanged');
    $same([10,30,60,100,300,365],ParticipationDays::MILESTONES,'latest approved six day milestones exclude the removed first-day benefit');
    $same([],ParticipationDays::reached(1),'first day remains counted with no new day benefit');
    $scenarios['title_and_day_thresholds_unchanged']=true;
    $output=getenv('RADIO_STAMP_TEST_ARTIFACT_DIR');
    if(!is_dir($output)&&!mkdir($output,0700,true)&&!is_dir($output)){throw new RuntimeException('Five-slot artifact directory unavailable.');}
    $sources=[];foreach(['src/Core/StampPolicy.php','src/Core/Schedule.php','src/Core/RadioService.php','src/Core/Database.php','tests/five_slot_policy.php']as$path){$sources[$path]=hash_file('sha256',dirname(__DIR__).'/'.$path);}
    file_put_contents($output.'/five-slot-policy.safe.json',json_encode(['status'=>'LOCAL_FIVE_SLOT_POLICY_VERIFIED_NOT_DEPLOYED','assertions'=>$checks,
        'php'=>PHP_VERSION,'sqlite'=>$db->pdo()->query('SELECT sqlite_version()')->fetchColumn(),'expected_slots'=>$expected,'source_sha256'=>$sources,
        'scenarios'=>$scenarios,'real_network_calls'=>0,'server_actions'=>0,'discord_mutations'=>0,'deployed'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
    echo "Five slot policy: $checks assertions passed (fixed components, shifted identities, replacements, old-schema history and concurrent planning; local only).\n";
}finally{
    // Fixtures remain in this unique temporary tree on failure; never traverse application storage.
}
