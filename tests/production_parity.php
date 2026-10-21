<?php
declare(strict_types=1);
require_once __DIR__.'/artifact_directory.php';
require dirname(__DIR__).'/bootstrap.php';
require __DIR__.'/fixtures/OperationsRuntimeFixture.php';
use RadioStamp\Core\{Schedule,FrozenClock,Database};
use RadioStamp\Discord\{DiscordRestClient,HttpRequest,Gateway,ButtonBuilder};
use RadioStamp\Http\{OperationsEndpoint,Response,PostResponseDriver,ValidationControls};
use RadioStamp\Operations\{OperationsConfig,OperationsRuntime,OperationsCli,ParticipantScope,SafeDiagnostics};

$checks=0;
$same=static function(mixed $a,mixed $b,string $why)use(&$checks):void{++$checks;if($a!==$b){throw new RuntimeException($why.' expected='.json_encode($a).' actual='.json_encode($b));}};
final class ParityDriver implements PostResponseDriver {
    public function __construct(public bool $success=true){}
    public function available():bool{return true;}
    public function finish():bool{return $this->success;}
}
$root=str_replace('\\','/',sys_get_temp_dir().'/radio-parity-'.bin2hex(random_bytes(8)));mkdir($root,0700);
$fixtures=[];$scenarios=[];$seq=0;
$users=['900000000000000101','900000000000000102','900000000000000103'];
$visitor='900000000000000104';$elsewhere='900000000000000105';$vc=DiscordRestClient::VOICE_CHANNEL_ID;
$at=static fn(string $date):DateTimeImmutable=>new DateTimeImmutable($date,new DateTimeZone('Asia/Tokyo'));
$make=static function(string $label,string $scheduled,?array $spec=null)use($root,&$fixtures,$at):array{
    $dir=$root.'/'.$label;mkdir($dir,0700);mkdir($dir.'/private',0700);mkdir($dir.'/public',0700);
    $config=runtimeFixtureConfig($dir,'best_effort',true);
    $config=array_replace($config,['participant_scope'=>'guild','participant_user_ids'=>[],
        'live_automation_enabled'=>true,'diagnostics_enabled'=>true,'command_registration_enabled'=>true]);
    if($spec!==null){$config['schedule']=$spec;}
    $path=$dir.'/private/config.php';file_put_contents($path,"<?php\nreturn ".var_export($config,true).";\n");
    $s=$at($scheduled);$clock=new FrozenClock($s->modify('-30 minutes')->format(DATE_ATOM));$http=new RuntimeFixtureHttp();
    $runtime=new OperationsRuntime($http,fn()=>$http->transport(),$clock);$context=$runtime->build($config,static fn()=>true);
    $value=compact('dir','config','path','s','clock','http','runtime','context');$fixtures[]=$value;return $value;
};
$row=static function(array $f):array{$q=$f['context']->db->pdo()->prepare('SELECT * FROM sessions WHERE id=?');$q->execute([$f['s']->format('Y-m-d_Hi')]);return $q->fetch()?:[];};
$messages=static fn(array $f,string $channel):array=>array_values(array_filter($f['http']->messages,static fn(array $m):bool=>$m['channel_id']===$channel));
$receipt=static fn(array $f):array=>array_values(array_filter($f['http']->messages,static fn(array $m):bool=>($m['components']??[])!==[]));
$send=static function(array $f,string $user,string $name,array $data=[],array $overrides=[],bool $finish=true)use(&$seq,$same):array{
    $component=str_starts_with($name,'radio:');
    $payload=['id'=>(string)(900000000000010000+ ++$seq),'application_id'=>DiscordRestClient::APPLICATION_ID,
        'guild_id'=>DiscordRestClient::GUILD_ID,'channel_id'=>DiscordRestClient::VOICE_CHANNEL_ID,
        'token'=>'CANARY_SECRET_CALLBACK','type'=>$component?3:2,
        'member'=>['permissions'=>'0','nick'=>'CANARY_SECRET_NAME','user'=>['id'=>$user,'username'=>'CANARY_SECRET_NAME']],
        'data'=>$component?['component_type'=>2,'custom_id'=>$name]:array_replace(['type'=>1,'name'=>$name],$data)];
    $payload=array_replace($payload,$overrides);$body=json_encode($payload,JSON_THROW_ON_ERROR);$timestamp=(string)$f['clock']->now()->getTimestamp();
    $keys=sodium_crypto_sign_seed_keypair(str_repeat("\x17",32));$driver=new ParityDriver($finish);
    $server=['REQUEST_METHOD'=>'POST','HTTP_X_SIGNATURE_TIMESTAMP'=>$timestamp,
        'HTTP_X_SIGNATURE_ED25519'=>bin2hex(sodium_crypto_sign_detached($timestamp.$body,sodium_crypto_sign_secretkey($keys)))];
    $before=count($f['http']->calls);$delivery=(new OperationsEndpoint($f['dir'].'/public',$driver,$f['runtime']))->prepare($server,$body,$f['path']);
    $same($before,count($f['http']->calls),'signed ingress does no HTTP before ACK');
    $ok=$delivery->deliver($driver,static fn(Response $r)=>null);
    return [$delivery->response,$ok,$payload['id']];
};
try {
    // Shared source: all five defaults, explicitly identified shifted slots and invalid syntax.
    $schedule=new Schedule();$default=$schedule->between($at('2026-10-21 00:00'),$at('2026-10-21 23:59:59'));
    $same(['00:00','04:00','07:00','12:30','22:00'],array_map(static fn($s)=>$s->format('H:i'),$default),'five default slots');
    foreach($default as$s){
        $same($s->format('Y-m-d_Hi'),$schedule->initialSessionAt($s->getTimestamp()-1800),'inclusive opening boundary');
        $same(null,$schedule->initialSessionAt($s->getTimestamp()+1800),'exclusive first-receipt boundary');
    }
    foreach([['daily_times'=>['24:00']],['daily_times'=>['12:60']],['one_off_times'=>['2026-02-30 12:34']],[],['daily_times'=>'07:00'],['unknown'=>[]]]as$bad){
        $failed=false;try{new Schedule($bad);}catch(InvalidArgumentException){$failed=true;}$same(true,$failed,'invalid schedule rejected');
    }
    $daily=new Schedule(['daily_times'=>[['time'=>'12:34','slot_id'=>'morning']],'one_off_times'=>[['at'=>'2026-10-21 12:34','slot_id'=>'morning']]]);
    $same(1,count($daily->between($at('2026-10-21'),$at('2026-10-21 23:59:59'))),'daily and one-off overlap deduplicated');
    foreach(['2026-02-30_1234','2026-10-21_2460','2026-10-21_123400','arbitrary']as$bad){$same(false,Schedule::validSessionId($bad),'button ID strict calendar syntax');}
    $same('radio:attend:2026-10-21_1234',ButtonBuilder::sessionControls('2026-10-21_1234')[0]['components'][1]['custom_id'],'builder is not restricted to old four times');

    // One continuous production flow per case. Only schedule differs between default and shifted cases.
    foreach([['normal','2026-10-21 07:00',null],['shifted','2026-10-21 12:34',['daily_times'=>[],'one_off_times'=>[['at'=>'2026-10-21 12:34','slot_id'=>'morning']]]]]as[$label,$scheduled,$spec]){
        foreach($users as$firstIndex=>$first){
            $f=$make($label.'-'.$firstIndex,$scheduled,$spec);$id=$f['s']->format('Y-m-d_Hi');
            $same(true,OperationsConfig::ready($f['config']),'full-featured production config ready');
            foreach(['posts_enabled','roles_enabled','achievement_notifications_enabled','room_permissions_enabled','member_actions_enabled','periodic_worker_enabled']as$key){$same(true,$f['config'][$key],'no trial feature off: '.$key);}
            $same([], $f['config']['participant_user_ids'],'no fixed user test allowlist');
            $same(true,ParticipantScope::allows($f['config'],$visitor),'ordinary guild member qualifies');
            $same(false,$f['context']->service->statusData($first)['total_count']>0,'isolated instance begins empty');
            $f['http']->voices=array_fill_keys([...$users,$visitor],$vc);$f['http']->voices[$elsewhere]='900000000000009999';
            $f['clock']->advance(-1);$f['runtime']->cycle($f['context'],static fn()=>true);
            $same(0,count($messages($f,DiscordRestClient::TEXT_CHANNEL_ID)),'no notice before S-30');
            $f['clock']->advance(1);$f['runtime']->cycle($f['context'],static fn()=>true);
            $same('open',$row($f)['status'],'S-30 opens same scheduler');
            $same(0,count($messages($f,DiscordRestClient::TEXT_CHANNEL_ID)),'opening does not send the retired thirty-minute notice');
            $same(1,count($messages($f,$vc)),'one opening guide');
            $same(1<<20,(int)$f['http']->allow&(1<<20),'CONNECT opened');
            $same(0,(int)$f['http']->deny&(1<<20),'CONNECT no longer denied');
            $f['runtime']->cycle($f['context'],static fn()=>true);$same(0,count($messages($f,DiscordRestClient::TEXT_CHANNEL_ID)),'no immediate notice on another opening cycle');
            $f['clock']->advance(20*60-1);$f['runtime']->cycle($f['context'],static fn()=>true);$same(0,count($messages($f,DiscordRestClient::TEXT_CHANNEL_ID)),'not before the ten-minute boundary');
            $f['clock']->advance(1);$f['runtime']->cycle($f['context'],static fn()=>true);$same(1,count($messages($f,DiscordRestClient::TEXT_CHANNEL_ID)),'one ten-minute notice after verified opening');
            $f['runtime']->cycle($f['context'],static fn()=>true);$same(1,count($messages($f,DiscordRestClient::TEXT_CHANNEL_ID)),'ten-minute notice duplicate prevented');
            $f['clock']->advance(18*60);$origin=$f['clock']->now()->getTimestamp();
            [$response,$ok]=$send($f,$first,'radio-attend',[],[],false);
            $same(['type'=>5,'data'=>['flags'=>64]],$response->body,'ordinary non-admin gets private ACK');
            $same(false,$ok,'failed ACK finish stops worker');$same(0,count($receipt($f)),'no receipt side effect after failed ACK');
            [$response,$ok]=$send($f,$first,'radio-attend');$same(true,$ok,'any of three users can be first');
            $same($origin+600,(int)$row($f)['closes_at'],'R+600 cutoff');$same(1,count($receipt($f)),'single shared receipt');
            $same(['参加','カード表示'],array_column($receipt($f)[0]['components'][0]['components'],'label'),'same card and attend buttons');
            $same(0,(int)$f['context']->db->pdo()->query('SELECT count(*) FROM attendance')->fetchColumn(),'command does not register attendance');
            foreach($users as$user){
                $send($f,$user,'radio-attend');$send($f,$user,'radio:attend:'.$id);$send($f,$user,'radio:attend:'.$id);
                $same(1,$f['context']->service->statusData($user)['total_count'],'one count despite duplicate command/button');
                $same(1,$f['context']->service->statusData($user)['total_days'],'one JST participation day');
            }
            $same($origin+600,(int)$row($f)['closes_at'],'duplicates never extend deadline');$same(1,count($receipt($f)),'no duplicate receipt');
            $f['runtime']->cycle($f['context'],static fn()=>true);
            foreach($users as$user){$same([$f['config']['role_ids'][1]],$f['http']->roles[$user],'title grant and readback');}
            $notices=$messages($f,DiscordRestClient::RECORDS_CHANNEL_ID);$same(3,count($notices),'one achievement per actual attendee');
            foreach($notices as$notice){$same([],$notice['allowed_mentions']['parse'],'no role/everyone mention');$same(1,count($notice['allowed_mentions']['users']),'achievement mentions only actual person');}
            $f['runtime']->cycle($f['context'],static fn()=>true);$same(3,count($messages($f,DiscordRestClient::RECORDS_CHANNEL_ID)),'notice ledger dedup');
            foreach($users as$user){
                $before=count($f['http']->calls);$send($f,$user,'radio-status',[],['channel_id'=>DiscordRestClient::TEXT_CHANNEL_ID]);
                $same(0,count(array_filter(array_slice($f['http']->calls,$before),static fn(HttpRequest $r)=>str_contains($r->url(),'/voice-states/'))),'status outside VC has no voice read');
                foreach(['radio:card:2026-10','radio-card']as$action){
                    $send($f,$user,$action,[],['channel_id'=>DiscordRestClient::TEXT_CHANNEL_ID]);$same(true,$f['http']->callbacks[array_key_last($f['http']->callbacks)]['png'],'same monthly renderer returns private PNG');
                }
                $callbacksBefore=count($f['http']->callbacks);[$removedDayOne]=$send($f,$user,'radio:memorial:1',[],['channel_id'=>DiscordRestClient::TEXT_CHANNEL_ID]);
                $same(400,$removedDayOne->status,'removed first-day benefit rejected in normal and shifted flows');$same($callbacksBefore,count($f['http']->callbacks),'removed first-day benefit produces no PNG callback');
            }
            $send($f,$first,'radio-card',['options'=>[['type'=>3,'name'=>'month','value'=>'2026-09']]],['channel_id'=>DiscordRestClient::RECORDS_CHANNEL_ID]);
            $same(true,$f['http']->callbacks[array_key_last($f['http']->callbacks)]['png'],'explicit historical month not disabled');
            $same(3,(int)$f['context']->db->pdo()->query('SELECT count(*) FROM attendance')->fetchColumn(),'cards/status never increment');
            [$response]=$send($f,$first,'radio:attend:2026-10-21_1259');$same(400,$response->status,'unscheduled component rejected before queue');
            [$response]=$send($f,$first,'radio-attend',[],['guild_id'=>'900000000000099999']);$same(403,$response->status,'wrong guild rejected');
            [$response]=$send($f,$first,'radio-attend',[],['channel_id'=>'900000000000099999']);$same(403,$response->status,'wrong channel rejected');
            $same('session_not_found',$f['context']->service->attend('2026-10-21_1259',$first,'invented')['code'],'direct core cannot act on arbitrary requested timestamp');
            $f['clock']->advance(539);$f['runtime']->cycle($f['context'],static fn()=>true);$same(null,$row($f)['warning_sent_at'],'no early warning');
            $f['clock']->advance(1);$f['runtime']->cycle($f['context'],static fn()=>true);$same($origin+540,(int)$row($f)['warning_sent_at'],'R+540 warning');
            $f['clock']->advance(59);$f['runtime']->cycle($f['context'],static fn()=>true);$same(0,count($f['http']->memberPatches()),'no premature disconnect');
            $f['clock']->advance(1);$cycle=$f['runtime']->cycle($f['context'],static fn()=>true);
            $same('closed',$row($f)['status'],'R+600 closes');$same(4,count($f['http']->memberPatches()),'all target VC occupants including nonclicker disconnected');
            foreach([...$users,$visitor]as$user){$same(null,$f['http']->voices[$user],'occupant absent after disconnect readback');}
            $same('900000000000009999',$f['http']->voices[$elsewhere],'other VC occupant untouched');
            $same([null],array_values(array_unique(array_map(static fn(HttpRequest $r)=>json_decode($r->body(),true)['channel_id'],$f['http']->memberPatches()))),'disconnect only, no auto relocation');
            $same(1<<20,(int)$f['http']->deny&(1<<20),'CONNECT closed');
            $f['runtime']->cycle($f['context'],static fn()=>true);$same(4,count($f['http']->memberPatches()),'closure replay causes no extra mutation');
            $before=count($f['http']->messages);$send($f,$first,'radio:attend:'.$id);$same(3,(int)$f['context']->db->pdo()->query('SELECT count(*) FROM attendance')->fetchColumn(),'closed receipt cannot add attendance');
            $same($before,count($f['http']->messages),'closed click does not post');
            $log=file_get_contents($f['dir'].'/private/operations-diagnostic.safe.jsonl');$same(false,str_contains($log,'CANARY_SECRET'),'diagnostic secrets redacted');
            $same(false,str_contains($log,$first),'diagnostic raw user IDs absent');
            $same(true,str_contains($log,'"actor":"u'),'guild actors receive local anonymous aliases');
            $same(true,str_contains($log,'"code":"closed"'),'diagnostic inner closure evidence retained');
            $scenarios[]=['case'=>$label,'first_actor'=>$firstIndex+1,'attendance'=>3,'notices'=>3,'disconnects'=>4,'closed'=>true];
            $f['context']->roster->close();
        }
    }

    // Same business window including first-receipt boundaries, active reuse beyond S+30 and no-receipt close.
    foreach([null,['daily_times'=>[],'one_off_times'=>[['at'=>'2026-10-21 12:34','slot_id'=>'morning']]]]as$index=>$spec){
        $scheduled=$index===0?'2026-10-21 07:00':'2026-10-21 12:34';$f=$make('edges-'.$index,$scheduled,$spec);$f['http']->voices=[$users[0]=>$vc];
        $f['clock']->advance(-1);$same('session_not_open',$f['context']->service->openAttendance($users[0],'early')['code'],'S-30-1 rejects');
        $f['clock']->advance(1);$same('receipt_opened',$f['context']->service->openAttendance($users[0],'edge')['code'],'S-30 accepts');
        $g=$make('late-'.$index,$scheduled,$spec);$g['http']->voices=[$users[0]=>$vc];$g['clock']->set($g['s']->modify('+29 minutes 59 seconds')->format(DATE_ATOM));
        $same('receipt_opened',$g['context']->service->openAttendance($users[0],'late')['code'],'S+30-1 accepts');
        $g['clock']->advance(2);$same('receipt_already_open',$g['context']->service->openAttendance($users[0],'late-again')['code'],'active receipt reusable beyond initial window');
        $h=$make('empty-'.$index,$scheduled,$spec);$h['http']->voices=[$users[0]=>$vc];$h['runtime']->cycle($h['context'],static fn()=>true);
        $h['clock']->set($h['s']->modify('+29 minutes')->format(DATE_ATOM));$h['runtime']->cycle($h['context'],static fn()=>true);
        $h['clock']->advance(60);$same('session_not_open',$h['context']->service->openAttendance($users[0],'at-end')['code'],'S+30 first-receipt exclusive');
        $h['runtime']->cycle($h['context'],static fn()=>true);$same('closed',$row($h)['status'],'no-receipt S+30 closure');
        $same(0,(int)$h['context']->db->pdo()->query('SELECT count(*) FROM attendance')->fetchColumn(),'unstarted close never counts');
    }

    // Calendar/day policy across midnight and month boundary, same production routes and renderer.
    foreach([['2026-11-01 00:00',null],['2026-11-01 00:07',['daily_times'=>[],'one_off_times'=>[['at'=>'2026-11-01 00:07','slot_id'=>'midnight']]]]]as$index=>[$scheduled,$spec]){
        $f=$make('midnight-'.$index,$scheduled,$spec);$f['http']->voices=[$users[0]=>$vc];$f['clock']->set('2026-10-31T23:57:00+09:00');
        $send($f,$users[0],'radio-attend');$send($f,$users[0],'radio:attend:'.$f['s']->format('Y-m-d_Hi'));
        $record=$f['context']->db->pdo()->query('SELECT count_date,attended_at FROM attendance')->fetch();
        $same('2026-11-01',$record['count_date'],'scheduled midnight belongs to next count_date');
        $same(1,$f['context']->service->cardData($users[0],'fictional','2026-11')['month_count'],'scheduled month card count');
        $same(1,$f['context']->service->cardData($users[0],'fictional','2026-10')['month_days'],'participation day follows actual JST day');
        $f['clock']->advance(180);foreach(['radio:card:2026-11','radio:card:2026-10']as$action){$send($f,$users[0],$action);$same(true,$f['http']->callbacks[array_key_last($f['http']->callbacks)]['png'],'month-crossing cards follow common rules');}
        [$removedDayOne]=$send($f,$users[0],'radio:memorial:1');$same(400,$removedDayOne->status,'month-crossing first day still has no day benefit');
        $f['clock']->advance(360);$f['runtime']->cycle($f['context'],static fn()=>true);$f['clock']->advance(60);$f['runtime']->cycle($f['context'],static fn()=>true);$same('closed',$row($f)['status'],'midnight receipt closes normally');
    }

    // Coherent existing history is generated through the same service, not hand-patched tables.
    $f=$make('history','2026-10-21 07:00');$f['http']->voices=[$users[0]=>$vc];
    for($i=39;$i>=1;$i--){$s=$f['s']->modify('-'.$i.' days');$f['clock']->set($s->format(DATE_ATOM));$r=$f['context']->service->openAttendance($users[0],'history-open-'.$i);$same(true,$r['ok'],'historical receipt');$same(true,$f['context']->service->attend($r['session_id'],$users[0],'history-attend-'.$i)['ok'],'historical attendance');}
    $f['runtime']->cycle($f['context'],static fn()=>true);$same([$f['config']['role_ids'][1]],$f['http']->roles[$users[0]],'existing title baseline');
    $f['clock']->set($f['s']->format(DATE_ATOM));$send($f,$users[0],'radio-attend');$send($f,$users[0],'radio:attend:'.$f['s']->format('Y-m-d_Hi'));
    $f['runtime']->cycle($f['context'],static fn()=>true);$same(40,$f['context']->service->statusData($users[0])['total_count'],'history increment to 40');
    $same([$f['config']['role_ids'][40]],$f['http']->roles[$users[0]],'real upgrade logic replaces lower title');
    $same(4,count($messages($f,DiscordRestClient::RECORDS_CHANNEL_ID)),'title thresholds 1/40 and day thresholds 10/30 each produce one notice');
    $same(2,(int)$f['context']->db->pdo()->query("SELECT COUNT(*) FROM achievement_notices WHERE status='sent'")->fetchColumn(),'count-title ledger keeps two completed notices');
    $same(2,(int)$f['context']->db->pdo()->query("SELECT COUNT(*) FROM day_achievement_notices WHERE status='sent'")->fetchColumn(),'day ledger independently keeps two completed notices');
    $f['runtime']->cycle($f['context'],static fn()=>true);$same(4,count($messages($f,DiscordRestClient::RECORDS_CHANNEL_ID)),'title/day notice dedup stays independent');
    $send($f,$users[0],'radio:memorial:10',[],['channel_id'=>DiscordRestClient::TEXT_CHANNEL_ID]);$same(true,$f['http']->callbacks[array_key_last($f['http']->callbacks)]['png'],'earned ten-day memorial still uses the same private rendering path');
    $fresh=$make('separate-db','2026-10-21 07:00');$same(0,$fresh['context']->service->statusData($users[0])['total_count'],'same user separate instance stays empty');
    $same(40,$f['context']->service->statusData($users[0])['total_count'],'separate DB does not reset source');

    // Production command definition; no real registration. Legacy allowlist remains explicit.
    $same(true,$fresh['context']->client->registerParticipantCommands()->ok,'fake guild command registration');
    $same([null],array_values(array_unique(array_column($fresh['http']->commands,'default_member_permissions'),SORT_REGULAR)),'normal commands not administrator-only');
    $bad=$fresh['config'];$bad['schedule']=['daily_times'=>['25:00']];$same(false,OperationsConfig::ready($bad),'invalid schedule rejected before runtime');
    $bad=$fresh['config'];$bad['owner_trial_enabled']=true;$same(false,OperationsConfig::ready($bad),'retired trial flags cannot silently change behavior');
    $bad=$fresh['config'];$bad['participant_scope']=123;$same(false,OperationsConfig::ready($bad),'invalid scope fails closed');
    $limited=$fresh['config'];$limited['participant_scope']='allowlist';$limited['participant_user_ids']=[$users[0]];
    $same(true,ParticipantScope::allows($limited,$users[0]),'explicit limited scope remains');$same(false,ParticipantScope::allows($limited,$visitor),'limited scope rejects outsider');
    $clock=new FrozenClock('2026-10-21T12:10:00+09:00');$same('radio:attend:2026-10-21_1234',(new ValidationControls($clock,['schedule'=>['daily_times'=>[['time'=>'12:34','slot_id'=>'morning']]]]))->forNow()[0]['components'][1]['custom_id'],'validation controls share identified Schedule');
    $summary=['status'=>'LOCAL_PRODUCTION_PARITY_TESTED_NOT_DEPLOYED','offline_only'=>true,'assertions'=>$checks,'flows'=>$scenarios,
        'real_api_calls'=>0,'server_actions'=>0,'real_discord_mutations'=>0,'live_timeout_root_cause'=>'unconfirmed','live_disconnect_root_cause'=>'unconfirmed'];
    $artifacts=getenv('RADIO_STAMP_TEST_ARTIFACT_DIR');
    if(!is_dir($artifacts)&&!mkdir($artifacts,0700,true)&&!is_dir($artifacts)){throw new RuntimeException('test_artifact_directory_unavailable');}
    file_put_contents($artifacts.'/production-parity.safe.json',json_encode($summary,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n");
    echo 'Production parity: '.$checks.' assertions passed; '.count($scenarios)." full signed flows, normal and shifted schedules, fake REST/Gateway only.\n";
} finally {
    foreach($fixtures as$f){$f['context']->roster->close();}
    // Keep disposable local fixtures for diagnosis if a test fails; never traverse outside this test's root.
}
