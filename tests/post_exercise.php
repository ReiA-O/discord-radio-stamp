<?php
declare(strict_types=1);
require_once __DIR__.'/artifact_directory.php';
require dirname(__DIR__).'/bootstrap.php';
use RadioStamp\Core\{Database,FrozenClock,RadioService};
use RadioStamp\Discord\{MockGateway,Gateway,ParticipantCommand,ReceiptMessage,DiscordRestClient};
use RadioStamp\Http\{PrivateConfig,InteractionQueue,InteractionController,InteractionWorker,MockFollowupSink};
use RadioStamp\Security\TokenCipher;

$checks=0;
$same=static function(mixed $expected,mixed $actual,string $why)use(&$checks):void{++$checks;if($expected!==$actual){throw new RuntimeException('Post-exercise: '.$why.' expected='.json_encode($expected).' actual='.json_encode($actual));}};
function receiptFixture(string $time='2026-10-19T07:08:00+09:00',string $path=':memory:',bool $memberActions=false):array {
    $config=require dirname(__DIR__).'/config/admin-validation.example.php';
    $config['participant_commands_enabled']=true;$config['member_actions_enabled']=$memberActions;
    $config['destination_channel_id']='900000000000000090';
    $db=new Database($path);$clock=new FrozenClock($time);$gateway=new MockGateway($clock);
    return [$db,$clock,$gateway,new RadioService($db,$gateway,$clock,$config),$config];
}
function receiptMessages(MockGateway $gateway,string $id='2026-10-19_0700'):array {
    return array_values(array_filter($gateway->actionLog,static fn(array $a):bool=>$a['method']==='sendMessage'&&$a['key']==='receipt:'.$id));
}
function receiptRow(Database $db,string $id='2026-10-19_0700'):array {
    $q=$db->pdo()->prepare('SELECT * FROM sessions WHERE id=?');$q->execute([$id]);return $q->fetch();
}
function receiptActions(MockGateway $gateway):array {
    return array_values(array_filter($gateway->actionLog,static fn(array $a):bool=>in_array($a['method'],['moveMember','disconnectMember'],true)));
}
if(($argv[1]??'')==='--receipt-child') {
    [$db,$clock,$gateway,$service,$config]=receiptFixture('2026-10-19T07:08:00+09:00',$argv[2]);
    $user=$argv[3];$gateway->setVoiceState($user,$config['voice_channel_id']);
    $result=$service->openAttendance($user,'child-'.$user);
    echo json_encode(['code'=>$result['code'],'sends'=>count(receiptMessages($gateway))],JSON_THROW_ON_ERROR);exit;
}
$owner='900000000000000101';$id='2026-10-19_0700';
$same('radio-attend',ParticipantCommand::definition()['name'],'participant command name');
$same([],ParticipantCommand::definition()['options'],'caller cannot choose another session/user');
$same(false,in_array(ParticipantCommand::NAME,array_column(DiscordRestClient::validationCommandDefinitions(),'name'),true),'absent from live validation registrar');
$realConfig=require dirname(__DIR__).'/config/admin-validation.example.php';
$same(false,$realConfig['participant_commands_enabled'],'participant flag defaults OFF');
$realConfig['mode']='admin_validation';$realConfig['participant_commands_enabled']=true;
$same(false,PrivateConfig::validationScope($realConfig),'general participant flag cannot enable fixed-owner REST');
$realConfig=array_replace($realConfig,['application_id'=>PrivateConfig::APPLICATION_ID,'guild_id'=>PrivateConfig::GUILD_ID,
    'admin_user_ids'=>[PrivateConfig::OWNER_ID],'text_channel_id'=>PrivateConfig::GUIDE_ID,
    'records_channel_id'=>PrivateConfig::RECORDS_ID,'voice_channel_id'=>PrivateConfig::VOICE_ID,
    'participant_commands_enabled'=>false,'member_actions_enabled'=>false,'live_automation_enabled'=>false,
    'achievement_notifications_enabled'=>false,'role_ids'=>[],'timezone'=>'Asia/Tokyo']);
$same(true,PrivateConfig::validationScope($realConfig),'complete fixed-owner baseline passes before mutation');
$realConfig['participant_commands_enabled']=true;
$same(false,PrivateConfig::validationScope($realConfig),'new flag alone rejects an otherwise valid REST scope');

[$db,$clock,$gateway,$service,$config]=receiptFixture('2026-10-19T06:29:59+09:00');
$gateway->setVoiceState($owner,$config['voice_channel_id']);
$same('session_not_open',$service->openAttendance($owner,'too-early')['code'],'before scheduled opening refused');
$same(0,(int)$db->pdo()->query('SELECT COUNT(*) FROM sessions')->fetchColumn(),'early command creates no session');
$clock->set('2026-10-19T06:30:00+09:00');$service->tick();$service->tick();
$guides=array_values(array_filter($gateway->actionLog,static fn(array $a):bool=>$a['method']==='sendMessage'&&$a['key']==='guide:2026-10-19_0700'));
$same(1,count($guides),'scheduled opening guide only once');
$same([],$guides[0]['components'],'pre-exercise guide has no attendance/start buttons');
$same(true,str_contains($guides[0]['content'],'/radio-attend'),'guide explains post-exercise command');
$reminders=array_values(array_filter($gateway->actionLog,static fn(array $a):bool=>$a['method']==='sendMessage'&&$a['key']==='reminder:2026-10-19_0700'));
$same(1,count($reminders),'thirty-minute advance reminder retained');
$same(0,(int)$db->pdo()->query('SELECT COUNT(*) FROM receipt_announcements')->fetchColumn(),'opening is not receipt start');

// Full signed local HTTP path with ten ordinary IDs outside the administrator allowlist.
$clock->set('2026-10-19T07:08:00+09:00');$keys=sodium_crypto_sign_keypair();$secret=sodium_crypto_sign_secretkey($keys);
$config['admin_validation_enabled']=true;$config['public_key_hex']=bin2hex(sodium_crypto_sign_publickey($keys));
$config['token_encryption_key_hex']=str_repeat('42',32);$config['private_db_path']=':memory:';
$config['admin_user_ids']=['900000000000000199'];
$queue=new InteractionQueue($db->pdo(),new TokenCipher($config['token_encryption_key_hex']));
$controller=new InteractionController($config,$queue,$clock);$sink=new MockFollowupSink();
$worker=new InteractionWorker($queue,$service,static function(){throw new RuntimeException('receipt/status must not render');},$sink,$clock);
$sequence=0;
$send=static function(string $user,string $action,array $override=[])use(&$sequence,$config,$clock,$secret,$controller):array {
    $data=['id'=>(string)(900000000000002000+ ++$sequence),'application_id'=>$config['application_id'],'guild_id'=>$config['guild_id'],
        'channel_id'=>$config['voice_channel_id'],'token'=>'fictional_receipt_callback_000000','type'=>$action==='button'?3:2,
        'member'=>['nick'=>'同名 @everyone <@900000000000000199>','user'=>['id'=>$user,'username'=>'fictional']],
        'data'=>$action==='button'?['component_type'=>2,'custom_id'=>'radio:attend:2026-10-19_0700']:['type'=>1,'name'=>$action]];
    $data=array_replace_recursive($data,$override);$body=json_encode($data,JSON_THROW_ON_ERROR);$at=(string)$clock->now()->getTimestamp();
    return [$controller->handle('POST',['x-signature-timestamp'=>$at,'x-signature-ed25519'=>bin2hex(sodium_crypto_sign_detached($at.$body,$secret))],$body),$data];
};
$gateway->setVoiceState($owner,$config['voice_channel_id']);
[$ack,$original]=$send($owner,'radio-attend');
$same(['type'=>5,'data'=>['flags'=>64]],$ack->body,'ordinary caller gets private defer');
$same(0,count(receiptMessages($gateway)),'no shared posting before worker/ACK boundary');
$same('receipt_opened',$worker->runOnce()['code'],'first command begins receipt');
$receipt=receiptRow($db);$origin=$clock->now()->getTimestamp();$deadline=$origin+600;
$same($origin,(int)$receipt['started_at'],'first command time stored');
$same($deadline,(int)$receipt['closes_at'],'exact ten-minute deadline');
$posts=receiptMessages($gateway);$same(1,count($posts),'one shared receipt message');
$same($config['voice_channel_id'],$posts[0]['channel_id'],'shared message in VC chat');
$same(true,str_contains($posts[0]['content'],'参加受付を開始しました！今から10分後に閉場します。参加した方はボタンを押してね'),'requested announcement text');
$same(true,str_contains($posts[0]['content'],'2026-10-19 07:18:00 JST'),'actual close time visible');
$same(Gateway::ALLOWED_MENTIONS,$posts[0]['allowed_mentions'],'announcement cannot mention anybody');
$same(false,$posts[0]['tts'],'announcement has no TTS');
$same(false,str_contains($posts[0]['content'],'@everyone'),'display name cannot inject mention/text');
$same(['参加','カード表示'],array_column($posts[0]['components'][0]['components'],'label'),'shared controls omit legacy start');
$same('use_receipt_command',$service->start($id,$owner,'legacy-start-bypass')['code'],'new flow rejects legacy start bypass');
foreach([2,10]as$i) {
    $clock->advance(10);$user=(string)(900000000000000100+$i);$gateway->setVoiceState($user,$config['voice_channel_id']);
    [$ack]=$send($user,'radio-attend');$same(200,$ack->status,'ordinary duplicate command accepted');
    $same('receipt_already_open',$worker->runOnce()['code'],'duplicate joins existing receipt');
    $same($deadline,(int)receiptRow($db)['closes_at'],'duplicate never extends timer');
    $same(1,count(receiptMessages($gateway)),'duplicate never posts shared notice again');
}
for($i=1;$i<=10;$i++) {
    $clock->advance(1);$user=(string)(900000000000000100+$i);$gateway->setVoiceState($user,$config['voice_channel_id']);
    [$ack]=$send($user,'button');$same(200,$ack->status,'ordinary participant can press shared button');
    $same('attended',$worker->runOnce()['code'],'each participant recorded');
    $same(1,$service->statusData($user)['total_count'],'individual cumulative count');
    $same(1,$service->statusData($user)['total_days'],'individual JST day');
}
$same(10,(int)$db->pdo()->query('SELECT COUNT(*) FROM attendance')->fetchColumn(),'ten distinct participants');
$gateway->setVoiceState($owner,$config['voice_channel_id']);[$ack]=$send($owner,'button');
$same('already_attended',$worker->runOnce()['code'],'another interaction cannot double-count');
$same(10,(int)$db->pdo()->query('SELECT COUNT(*) FROM attendance')->fetchColumn(),'duplicate preserves count');
$same(1,(int)$db->pdo()->query('SELECT COUNT(*) FROM receipt_announcements')->fetchColumn(),'duplicate creates no receipt row');
$restartGateway=new MockGateway($clock);$restartGateway->setVoiceState($owner,$config['voice_channel_id']);
$restart=new RadioService($db,$restartGateway,$clock,$config);
$same('receipt_already_open',$restart->openAttendance($owner,'after-restart')['code'],'restart reuses persisted receipt');
$same(0,count(receiptMessages($restartGateway)),'restart does not repost confirmed announcement');
$same($deadline,(int)receiptRow($db)['closes_at'],'restart does not extend deadline');
$same(400,$send($owner,'button',['data'=>['custom_id'=>'radio:start:'.$id]])[0]->status,'participant route rejects legacy start');
$same(400,$send($owner,'radio-check')[0]->status,'participant route cannot run owner diagnostics');
$same(403,$send($owner,'radio-attend',['guild_id'=>'900000000000000198'])[0]->status,'wrong guild rejected');
$same(4,$send($owner,'radio-attend',['channel_id'=>$config['text_channel_id']])[0]->body['type'],'wrong chat receives private refusal');
$same(400,$send($owner,'radio-attend',['data'=>['options'=>[['type'=>3,'name'=>'session','value'=>'forged']]]])[0]->status,'no arbitrary session argument');
$gateway->voiceStates=[];[$ack]=$send($owner,'radio-status',['channel_id'=>$config['text_channel_id']]);
$same('status_ready',$worker->runOnce()['code'],'ordinary self status works outside voice without adding attendance');
$same(10,(int)$db->pdo()->query('SELECT COUNT(*) FROM attendance')->fetchColumn(),'status remains read-only');
$clock->set('2026-10-19T07:16:59+09:00');$service->tick();
$warnings=static fn()=>array_values(array_filter($gateway->actionLog,static fn(array $a):bool=>$a['method']==='sendMessage'&&str_starts_with($a['key'],'warning:'.$id.':started:')));
$same(0,count($warnings()),'no warning before nine minutes');
$clock->advance(1);$service->tick();$service->tick();
$same(1,count($warnings()),'one warning at nine minutes');
$same(0,count(receiptActions($gateway)),'no member action before deadline');
$clock->set('2026-10-19T07:18:00+09:00');$gateway->setVoiceState($owner,$config['voice_channel_id']);
$same('session_closed',$service->attend($id,$owner,'exact-close')['code'],'attendance stops exactly at ten minutes before tick');
$same('session_closed',$service->openAttendance($owner,'command-at-close')['code'],'same slot cannot be restarted at close');
$same($deadline,(int)receiptRow($db)['closes_at'],'closed command does not extend time');

// A late first command overrides the unused planned+30-minute deadline, once only.
[$lateDb,$lateClock,$lateGateway,$late,$lateConfig]=receiptFixture('2026-10-19T07:29:59+09:00');
$lateGateway->setVoiceState($owner,$lateConfig['voice_channel_id']);$lateResult=$late->openAttendance($owner,'late-first');
$same('receipt_opened',$lateResult['code'],'first command still accepted before unused cutoff');
$same($lateClock->now()->getTimestamp()+600,$lateResult['closes_at'],'late first receipt gets full ten minutes');
$lateClock->set('2026-10-19T07:35:00+09:00');$lateGateway->setVoiceState($owner,$lateConfig['voice_channel_id']);
$same('receipt_already_open',$late->openAttendance($owner,'late-duplicate')['code'],'duplicate beyond original cutoff reuses active session');
$same(1,(int)$lateDb->pdo()->query('SELECT COUNT(*) FROM sessions')->fetchColumn(),'late duplicate makes no new session');
$same(1,count(receiptMessages($lateGateway)),'late duplicate makes no new notice');
[$cutDb,$cutClock,$cutGateway,$cut,$cutConfig]=receiptFixture('2026-10-19T07:30:00+09:00');
$cutGateway->setVoiceState($owner,$cutConfig['voice_channel_id']);
$same('session_not_open',$cut->openAttendance($owner,'unused-cutoff')['code'],'unused slot cannot start at scheduled+30');
$same(0,(int)$cutDb->pdo()->query('SELECT COUNT(*) FROM sessions')->fetchColumn(),'cutoff command creates nothing');
[$unusedDb,$unusedClock,$unusedGateway,$unused,$unusedConfig]=receiptFixture('2026-10-19T06:30:00+09:00',':memory:',true);
$unused->tick();$unusedClock->set('2026-10-19T07:29:00+09:00');$unused->tick();
$unusedClock->set('2026-10-19T07:30:00+09:00');$unusedGateway->setRoster($unusedConfig['voice_channel_id'],[]);$unused->tick();
$same('closed',receiptRow($unusedDb)['status'],'never-commanded slot still closes at scheduled plus thirty minutes');
$same(0,(int)$unusedDb->pdo()->query('SELECT COUNT(*) FROM receipt_announcements')->fetchColumn(),'unused closure never creates receipt');
$same(0,count(receiptMessages($unusedGateway)),'unused closure never emits ten-minute announcement');

// Failure/unknown presence and send uncertainty fail closed without automatic reposts.
foreach(['unknown','outside','stale']as$case) {
    [$badDb,$badClock,$badGateway,$bad,$badConfig]=receiptFixture();
    if($case==='outside'){$badGateway->setVoiceState($owner,'900000000000000099');}
    if($case==='stale'){$badGateway->setVoiceState($owner,$badConfig['voice_channel_id'],true,$badClock->now()->getTimestamp()-6);}
    $same(false,$bad->openAttendance($owner,'bad-'.$case)['ok'],'unverified initial presence refused');
    $same(0,(int)$badDb->pdo()->query('SELECT COUNT(*) FROM sessions')->fetchColumn(),'bad presence creates no session');
}
[$failedDb,$failedClock,$failedGateway,$failed,$failedConfig]=receiptFixture('2026-10-19T07:08:00+09:00',':memory:',true);
$failedGateway->setVoiceState($owner,$failedConfig['voice_channel_id']);$failedGateway->setResult('receipt:'.$id,false);
$same('receipt_announcement_unconfirmed',$failed->openAttendance($owner,'failed-send')['code'],'uncertain send reported privately');
$same('receipt_announcement_unconfirmed',$failed->openAttendance($owner,'another-failed-send')['code'],'another command does not retry uncertain send');
$same('receipt_announcement_unconfirmed',$failed->attend($id,$owner,'failed-button')['code'],'unconfirmed announcement cannot accept attendance');
$same(1,count(receiptMessages($failedGateway)),'one attempt despite repeats');
$same('uncertain',$failedDb->pdo()->query('SELECT status FROM receipt_announcements')->fetchColumn(),'uncertainty retained');
$failedClock->advance(700);$failed->tick();
$same(1,count(receiptMessages($failedGateway)),'late worker does not repost');
$same([],receiptActions($failedGateway),'uncertain announcement cannot trigger move/disconnect');
$failedDb->pdo()->exec("UPDATE receipt_announcements SET status='sending'");
$crashGateway=new MockGateway($failedClock);$crashGateway->setVoiceState($owner,$failedConfig['voice_channel_id']);
$crashed=new RadioService($failedDb,$crashGateway,$failedClock,$failedConfig);$crashed->tick();
$same(0,count(receiptMessages($crashGateway)),'interrupted sending row does not auto-repost after restart');
$same([],receiptActions($crashGateway),'interrupted sending row cannot move people');

// Receipt time is queue ingress time; a slow worker cannot silently add time.
[$delayDb,$delayClock,$delayGateway,$delay,$delayConfig]=receiptFixture('2026-10-19T07:08:40+09:00');
$delayGateway->setVoiceState($owner,$delayConfig['voice_channel_id']);$requestTime=$delayClock->now()->getTimestamp()-40;
$same($requestTime+600,$delay->openAttendance($owner,'delayed-worker',$requestTime)['closes_at'],'processing delay never extends requested timer');
[$expiredDb,$expiredClock,$expiredGateway,$expired,$expiredConfig]=receiptFixture();$expiredGateway->setVoiceState($owner,$expiredConfig['voice_channel_id']);
$same('receipt_request_expired',$expired->openAttendance($owner,'expired-worker',$expiredClock->now()->getTimestamp()-121)['code'],'old queued receipt refused');
$same(0,count(receiptMessages($expiredGateway)),'expired receipt never posts');

// Actual JST participation dates stay separate from the midnight scheduled date.
[$nightDb,$nightClock,$nightGateway,$night,$nightConfig]=receiptFixture('2026-10-31T23:59:30+09:00');
$nightGateway->setVoiceState($owner,$nightConfig['voice_channel_id']);$nightOpen=$night->openAttendance($owner,'night-open');
$same('2026-11-01_0000',$nightOpen['session_id'],'midnight uses scheduled next date');
$same('attended',$night->attend($nightOpen['session_id'],$owner,'night-attend')['code'],'actual prior-date attendance accepted');
$same(1,$night->statusData($owner)['total_days'],'prior JST date counts once');
$nightClock->set('2026-11-01T00:00:01+09:00');$nightGateway->setVoiceState($owner,$nightConfig['voice_channel_id']);
$same('already_attended',$night->attend($nightOpen['session_id'],$owner,'night-duplicate')['code'],'cross-midnight duplicate adds no new day');
$same(1,$night->statusData($owner)['total_days'],'cross-midnight day remains one');

// All lingering participants disconnect regardless of attendance; no automatic move (Mock only).
[$moveDb,$moveClock,$moveGateway,$move,$moveConfig]=receiptFixture('2026-10-19T07:08:00+09:00',':memory:',true);
$unregistered='900000000000000102';$moveGateway->setVoiceState($owner,$moveConfig['voice_channel_id']);
$move->openAttendance($owner,'move-open');$move->attend($id,$owner,'move-attend');
$moveClock->advance(540);$move->tick();$moveClock->advance(60);
foreach([$owner,$unregistered]as$user){$moveGateway->setVoiceState($user,$moveConfig['voice_channel_id']);}
$moveGateway->setRoster($moveConfig['voice_channel_id'],[$owner,$unregistered]);$move->tick();
$actions=receiptActions($moveGateway);
$same(['disconnectMember','disconnectMember'],array_column($actions,'method'),'registered and unregistered lingering users both disconnect');
$same([$owner,$unregistered],array_column($actions,'user_id'),'actions apply to correct IDs');
$same('closed',receiptRow($moveDb)['status'],'mock closure completes');

// Eight real local processes compete for one shared receipt and one announcement attempt.
$temp=sys_get_temp_dir().'/radio-receipt-'.bin2hex(random_bytes(8));mkdir($temp,0700);$path=$temp.'/race.sqlite';
$prepared=new Database($path);unset($prepared);$processes=[];$outputs=[];
try {
    for($i=1;$i<=8;$i++) {
        $command=[PHP_BINARY];if(($ini=php_ini_loaded_file())!==false){$command[]='-c';$command[]=$ini;}
        array_push($command,__FILE__,'--receipt-child',$path,(string)(900000000000000100+$i));
        $pipes=[];$process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));
        if(!is_resource($process)){throw new RuntimeException('Cannot start local receipt worker');}fclose($pipes[0]);$processes[]=[$process,$pipes];
    }
    foreach($processes as[$process,$pipes]) {
        $output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        $same(0,proc_close($process),'local child exit: '.$error);$outputs[]=json_decode($output,true,32,JSON_THROW_ON_ERROR);
    }
    $race=new Database($path);$same(1,(int)$race->pdo()->query('SELECT COUNT(*) FROM sessions')->fetchColumn(),'race creates one scheduled session');
    $same(1,(int)$race->pdo()->query('SELECT COUNT(*) FROM receipt_announcements')->fetchColumn(),'race creates one announcement ledger row');
    $same(1,array_sum(array_column($outputs,'sends')),'race sends once across eight processes');
    $same((new DateTimeImmutable('2026-10-19T07:18:00+09:00'))->getTimestamp(),(int)receiptRow($race)['closes_at'],'race cannot extend timer');
    unset($race);
} finally {
    foreach([$path,$path.'-wal',$path.'-shm']as$file){if(is_file($file)){unlink($file);}}rmdir($temp);
}
echo "Post-exercise receipt: {$checks} assertions passed (signed fictional ten-person flow; eight local processes; no live operations).\n";
