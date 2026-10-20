<?php
declare(strict_types=1);
require_once __DIR__.'/artifact_directory.php';
require dirname(__DIR__).'/bootstrap.php';
use RadioStamp\Core\{Database,FrozenClock,RadioService,TitlePolicy};
use RadioStamp\Discord\{MockGateway,AchievementMessage,Gateway,RestGateway,AchievementGateway};

if (($argv[1]??null)==='--notice-child') {
    $childDb=new Database($argv[2]);$childClock=new FrozenClock('2026-01-10T22:00:00+09:00');$childGateway=new MockGateway($childClock);
    $childService=new RadioService($childDb,$childGateway,$childClock,['guild_id'=>'fictional-guild','voice_channel_id'=>'fictional-voice','text_channel_id'=>'fictional-guide','records_channel_id'=>'fictional-achievements','achievement_notifications_enabled'=>true]);
    $childService->tick();
    echo count(array_filter($childGateway->actionLog,static fn(array $a):bool=>$a['method']==='sendAchievement'));exit(0);
}

$checks=0;$check=static function(bool $ok,string $why)use(&$checks):void{++$checks;if(!$ok){throw new RuntimeException($why);}};
$db=new Database(':memory:');$clock=new FrozenClock('2026-01-10T22:00:00+09:00');$mock=new MockGateway($clock);
$user='900000000000000101';$other='900000000000000102';
$config=['guild_id'=>'fictional-guild','voice_channel_id'=>'fictional-voice','text_channel_id'=>'fictional-guide','records_channel_id'=>'fictional-achievements',
    'member_actions_enabled'=>true,'achievement_notifications_enabled'=>true,'role_ids'=>[40=>'fictional-role-40',80=>'fictional-role-80']];
$service=new RadioService($db,$mock,$clock,$config);
$seed=static function(int $count)use($db,$user):void{
    $db->transaction(static function(PDO $pdo)use($count,$user):void{
        $pdo->prepare('INSERT OR IGNORE INTO participants(user_id,total_count) VALUES (?,0)')->execute([$user]);
        $session=$pdo->prepare("INSERT OR IGNORE INTO sessions(id,scheduled_at,count_date,opens_at,unstarted_closes_at,status,started_at,closes_at) VALUES (?,?,?,?,?,'closed',?,?)");
        $record=$pdo->prepare('INSERT OR IGNORE INTO attendance(session_id,user_id,count_date,attended_at,ordinal,stamp) VALUES (?,?,?,?,?,?)');
        for($i=1;$i<=$count;$i++){$at=(new DateTimeImmutable('2026-01-01T00:00:00+09:00'))->modify('+'.intdiv($i-1,4).' days')->setTime([0,4,7,22][($i-1)%4],0);$id=$at->format('Y-m-d_Hi');$seconds=$at->getTimestamp();$date=$at->format('Y-m-d');
            $session->execute([$id,$seconds,$date,$seconds-1800,$seconds+1800,$seconds,$seconds+900]);$record->execute([$id,$user,$date,$seconds,$i,['IC','コンデンサ','LED'][($i-1)%3]]);}
        [$threshold,$title]=TitlePolicy::forCount($count);$pdo->prepare('UPDATE participants SET total_count=?,latest_threshold=?,latest_title=? WHERE user_id=?')->execute([$count,$threshold,$title,$user]);
    });
};
$messages=static fn(MockGateway $g):array=>array_values(array_filter($g->actionLog,static fn(array $a):bool=>$a['method']==='sendAchievement'));
$seed(39);$scheduled=new DateTimeImmutable('2026-01-10T22:00:00+09:00');$service->planSessions($scheduled,$scheduled);$mock->setVoiceState($user,'fictional-voice');
$check($service->start('2026-01-10_2200',$user,'start-40')['ok'],'milestone session starts');
$r=$service->attend('2026-01-10_2200',$user,'attend-40');
$check($r['latest_title']==='🔌 接続完了'&&$r['total_count']===40&&$r['total_days']===10,'40-count title independent from ten-day benefit');
$check($messages($mock)===[],'attendance result alone does not announce unassigned title');
$mock->setResult('syncDecorationRole',[false,true]);$service->tick();
$check($messages($mock)===[]&&(int)$db->pdo()->query('SELECT COUNT(*) FROM achievement_notices')->fetchColumn()===0,'failed role assignment cannot enqueue or send notice');
$service->tick();$sent=$messages($mock);$check(count($sent)===1&&$sent[0]['ok'],'successful assignment enables one announcement');
$check($sent[0]['channel_id']==='fictional-achievements','announcement uses existing records destination');
$check($sent[0]['content']==='🎉 <@'.$user.'> さんが「🔌 接続完了」ロールを獲得しました！！！'."\n".'累計40回参加！新たな段階へレベルアップ⚡','only stable user ID and approved role title produce the approved two-line celebration');
$check($sent[0]['allowed_mentions']===['parse'=>[],'users'=>[$user],'roles'=>[],'replied_user'=>false]&&$sent[0]['tts']===false,'exactly one user may be notified, no roles/everyone/reply/TTS');
$service->cardData($user,'@everyone <@'.$other.'> 別の名前','2026-01');
for($i=0;$i<3;$i++){$service->tick();}
$check(count($messages($mock))===1,'rename and repeated ticks cannot notify twice');
$otherGateway=new MockGateway($clock);$restarted=new RadioService($db,$otherGateway,$clock,$config);$restarted->tick();
$check($messages($otherGateway)===[],'restart/second worker cannot repeat a completed notice');
$check($db->pdo()->query('SELECT status FROM achievement_notices')->fetchColumn()==='sent','successful notice persisted');

// A confirmed-grant/pending-notice fixture is claimed once by eight real processes.
$dir=sys_get_temp_dir().'/radio-stamp-notice-race-'.bin2hex(random_bytes(8));mkdir($dir,0700);$path=$dir.'/application.sqlite';
try {
    $fileDb=new Database($path);
    foreach(['participants','sessions','attendance']as$table){foreach($db->pdo()->query('SELECT * FROM '.$table)->fetchAll()as$row){$columns=array_keys($row);$sql='INSERT INTO '.$table.' ('.implode(',',$columns).') VALUES ('.implode(',',array_fill(0,count($row),'?')).')';$fileDb->pdo()->prepare($sql)->execute(array_values($row));}}
    $fileDb->pdo()->prepare("INSERT INTO achievement_notices(user_id,threshold,total_count,status,created_at) VALUES (?,40,40,'pending',?)")->execute([$user,$clock->now()->getTimestamp()]);
    $processes=[];
    for($i=0;$i<8;$i++){$command=[PHP_BINARY];if(($ini=php_ini_loaded_file())!==false){array_push($command,'-c',$ini);}array_push($command,__FILE__,'--notice-child',$path);$pipes=[];$process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);$check(is_resource($process),'launch notice race worker');fclose($pipes[0]);$processes[]=[$process,$pipes];}
    $totalSends=0;
    foreach($processes as[$process,$pipes]){$output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$check(proc_close($process)===0&&$error===''&&preg_match('/^[01]$/D',$output)===1,'race worker returns one bounded mock result');$totalSends+=(int)$output;}
    $check($totalSends===1,'eight processes make exactly one notification attempt');
    $check($fileDb->pdo()->query('SELECT status FROM achievement_notices')->fetchColumn()==='sent','race outcome persisted once');
} finally {unset($fileDb,$childService,$childDb);gc_collect_cycles();foreach(glob($dir.'/*')?:[]as$file){if(is_file($file)){unlink($file);}}rmdir($dir);}

// Send outcome uncertainty is held for manual reconciliation, never automatically re-pinged.
$seed(79);$clock->set('2026-01-20T22:00:00+09:00');$when=new DateTimeImmutable('2026-01-20T22:00:00+09:00');$service->planSessions($when,$when);$mock->setVoiceState($user,'fictional-voice');
$check($service->start('2026-01-20_2200',$user,'start-80')['ok'],'next title session starts');
$check($service->attend('2026-01-20_2200',$user,'attend-80')['latest_title']==='⚙️ 暖機運転','80-count title');
$mock->setResult('sendAchievement',false);$service->tick();$attempts=count($messages($mock));$service->tick();$restarted->tick();
$check($attempts===2&&count($messages($mock))===2&&$messages($otherGateway)===[],'uncertain send is attempted once across ticks and restart');
$check($db->pdo()->query('SELECT status FROM achievement_notices WHERE threshold=80')->fetchColumn()==='uncertain','uncertain delivery is visible, no false success');
$check((int)$db->pdo()->query('SELECT COUNT(*) FROM achievement_notices')->fetchColumn()===2,'deduplication key is user ID plus threshold');

$defaultMock=new MockGateway($clock);$off=$config;$off['achievement_notifications_enabled']=false;
$disabled=new RadioService($db,$defaultMock,$clock,$off);$disabled->tick();$check($messages($defaultMock)===[],'notification flag OFF prevents sends');
$check(!is_subclass_of(RestGateway::class,AchievementGateway::class),'real restricted REST adapter has no achievement posting capability');
$validation=require dirname(__DIR__).'/config/admin-validation.example.php';$validation['mode']='admin_validation';
$check(\RadioStamp\Http\PrivateConfig::validationScope($validation),'fixed owner validation scope remains available with notifications OFF');
$validation['achievement_notifications_enabled']=true;
$check(!\RadioStamp\Http\PrivateConfig::validationScope($validation),'owner validation cannot enable public achievement notifications');
foreach([['@everyone',40,40],['900000000000000101><@&1',40,40],[$user,41,41],[$user,80,79]]as$bad){try{AchievementMessage::build(...$bad);$check(false,'invalid announcement accepted');}catch(InvalidArgumentException){$check(true,'invalid ID/threshold/count rejected');}}
foreach($mock->actionLog as$event){if($event['method']==='sendMessage'){$check($event['allowed_mentions']===Gateway::ALLOWED_MENTIONS,'all ordinary messages keep zero mentions');}}
echo "Achievements: {$checks} assertions passed (mock role grants and mock notices only; no live changes).\n";
