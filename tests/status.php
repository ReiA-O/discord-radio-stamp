<?php
declare(strict_types=1);
require_once __DIR__.'/artifact_directory.php';
require dirname(__DIR__) . '/bootstrap.php';

use RadioStamp\Core\{Database,FrozenClock,RadioService};
use RadioStamp\Discord\{MockGateway,DiscordRestClient,HttpAdapter,HttpRequest,HttpResponse,Gateway};
use RadioStamp\Http\{InteractionQueue,InteractionController,InteractionWorker,MockFollowupSink,DiscordRestFollowupSink};
use RadioStamp\Security\TokenCipher;

$checks=0;$check=static function(bool $ok,string $why)use(&$checks):void{++$checks;if(!$ok){throw new RuntimeException($why);}};
$clock=new FrozenClock('2026-10-19T07:00:00+09:00');$db=new Database(':memory:');$gateway=new MockGateway($clock);
$keys=sodium_crypto_sign_keypair();$secret=sodium_crypto_sign_secretkey($keys);
$config=require dirname(__DIR__).'/config/admin-validation.example.php';
$config['admin_validation_enabled']=true;$config['public_key_hex']=bin2hex(sodium_crypto_sign_publickey($keys));
$config['token_encryption_key_hex']=str_repeat('42',32);$config['private_db_path']=':memory:';
$owner='900000000000000101';$other='900000000000000102';$config['admin_user_ids']=[$owner,$other];
$service=new RadioService($db,$gateway,$clock,$config);
$service->planSessions(new DateTimeImmutable('2026-10-19T07:00:00+09:00'),new DateTimeImmutable('2026-10-19T07:00:00+09:00'));
$gateway->setVoiceState($owner,$config['voice_channel_id']);
$check($service->start('2026-10-19_0700',$owner,'fictional-start')['ok'],'seed start');
$check($service->attend('2026-10-19_0700',$owner,'fictional-attend')['ok'],'seed attendance');
$gateway->voiceStates=[]; // Now outside every VC, with no known presence.
$dump=static function()use($db):array{$out=[];foreach(['sessions','participants','attendance','memorial_awards','core_interactions','core_effects','achievement_notices']as$table){$out[$table]=$db->pdo()->query('SELECT * FROM '.$table)->fetchAll();}return$out;};
$before=$dump();$actionsBefore=count($gateway->actionLog);
$check($service->statusData($owner)===['ok'=>true,'code'=>'status_ready','total_count'=>1,'total_days'=>1],'outside-VC own status');
$check($service->statusData($other)===['ok'=>true,'code'=>'status_ready','total_count'=>0,'total_days'=>0],'other ID has separate zero counters');
$check($before===$dump()&&$actionsBefore===count($gateway->actionLog),'direct read changes no domain data or gateway actions');
$queue=new InteractionQueue($db->pdo(),new TokenCipher($config['token_encryption_key_hex']));
$controller=new InteractionController($config,$queue,$clock);$sink=new MockFollowupSink();
$worker=new InteractionWorker($queue,$service,static function(){throw new RuntimeException('status must not render');},$sink,$clock);
$counter=0;
$send=static function(array $changes=[])use(&$counter,$owner,$config,$clock,$secret,$controller):RadioStamp\Http\Response{
    $payload=['id'=>(string)(900000000000001000+ ++$counter),'application_id'=>$config['application_id'],'guild_id'=>$config['guild_id'],
        'channel_id'=>$config['text_channel_id'],'token'=>'fictional_status_callback_000000','type'=>2,
        'member'=>['user'=>['id'=>$owner,'username'=>'@everyone <@900000000000000102>'],'nick'=>'同じ表示名'],
        'data'=>['type'=>1,'name'=>'radio-status']];
    $payload=array_replace_recursive($payload,$changes);$body=json_encode($payload,JSON_THROW_ON_ERROR);
    $at=(string)$clock->now()->getTimestamp();$signature=bin2hex(sodium_crypto_sign_detached($at.$body,$secret));
    return $controller->handle('POST',['x-signature-timestamp'=>$at,'x-signature-ed25519'=>$signature],$body);
};
$ack=$send();$check($ack->body===['type'=>5,'data'=>['flags'=>64]],'signed status defers privately');
$check($worker->runOnce()['code']==='status_ready','worker reads outside VC without renderer');
$records=array_values($sink->records);$check($records[0]['ephemeral']&&$records[0]['png_bytes']===0,'read produces private text only');
$check($records[0]['result']===['ok'=>true,'code'=>'status_ready','counts'=>['total_count'=>1,'total_days'=>1]],'only own numeric counters cross response boundary');
$check($before===$dump(),'queued status adds no attendance, day, title, notice or session data');
$ack=$send(['member'=>['user'=>['id'=>$other]]]);$check($ack->status===200&&$worker->runOnce()['code']==='status_ready','second allowed caller reads self');
$records=array_values($sink->records);$check($records[1]['result']['counts']===['total_count'=>0,'total_days'=>0],'same-name caller cannot retrieve owner record');
$check($send(['data'=>['options'=>[['type'=>6,'name'=>'user','value'=>$other]]]])->status===400,'no other-person search option');
$check($send(['member'=>['user'=>['id'=>'900000000000000199']]])->status===403,'unapproved caller remains rejected');
$check($send(['guild_id'=>'900000000000000199'])->status===403,'wrong guild remains rejected');
$check($send(['channel_id'=>'900000000000000199'])->status===403,'unapproved channel remains rejected');

$http=new class implements HttpAdapter{public array $calls=[];public function exchange(HttpRequest $request):HttpResponse{$this->calls[]=$request;return new HttpResponse(200,[],json_encode(['id'=>'900000000000009999']));}};
$client=new DiscordRestClient($http,DiscordRestClient::APPLICATION_ID,'fictional_bot_token_0000000000',['enabled'=>true]);
$restSink=new DiscordRestFollowupSink($client);
$check($restSink->send('900000000000001001','fictional_callback_token_000000',['ok'=>true,'code'=>'status_ready','counts'=>['total_count'=>4,'total_days'=>1]],null),'numeric status formatted through fake REST');
$payload=json_decode($http->calls[0]->body(),true,32,JSON_THROW_ON_ERROR);
$check($payload['content']==="累計参加：4回\n累計参加日数：1日（JST、同日は1日）",'count/day units explicit');
$check($payload['allowed_mentions']===Gateway::ALLOWED_MENTIONS,'ordinary status cannot mention anybody');
$check(!$restSink->send('900000000000001002','fictional_callback_token_000000',['code'=>'status_ready','counts'=>['total_count'=>1,'total_days'=>2]],null),'invalid counters rejected without edit');
$check(count($http->calls)===1,'invalid result has no fake transport call');
$definitions=DiscordRestClient::validationCommandDefinitions();$status=array_values(array_filter($definitions,static fn(array $d):bool=>$d['name']==='radio-status'));
$check(count($status)===1&&$status[0]['options']===[]&&$status[0]['default_member_permissions']==='0','local command definition has no target selector and stays restricted');
$check($client->upsertValidationCommands()->code==='command_registration_disabled'&&count($http->calls)===1,'registration remains disabled');
echo "Status: {$checks} assertions passed (signed fictional interactions, fake HTTP, no live calls).\n";
