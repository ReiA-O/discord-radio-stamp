<?php
declare(strict_types=1);
require_once __DIR__.'/artifact_directory.php';
require dirname(__DIR__).'/bootstrap.php';
use RadioStamp\Core\Database;
use RadioStamp\Discord\{HttpAdapter,HttpRequest,HttpResponse,DiscordRestClient,ServerOperationsClient,Gateway,AtomicMemberGateway,RoleMutationScope};

$checks=0;$same=static function(mixed $a,mixed $b,string $why)use(&$checks):void{++$checks;if($a!==$b){throw new RuntimeException('Operations adapter: '.$why);}};
final class OperationsFakeHttp implements HttpAdapter {
    public array $calls=[];public array $roles=['900000000000000141','900000000000000149'];
    public ?string $voice=DiscordRestClient::VOICE_CHANNEL_ID;public array $voiceSequence=[];
    public bool $failGrant=false;public bool $failPost=false;public bool $raceAtPatch=false;public bool $movedFromOther=false;
    public ?HttpResponse $next=null;
    public function exchange(HttpRequest $request):HttpResponse {
        $this->calls[]=$request;$path=parse_url($request->url(),PHP_URL_PATH);$method=$request->method();
        if($this->next!==null){$response=$this->next;$this->next=null;return $response;}
        if(str_contains($path,'/voice-states/')){
            $id=basename($path);$channel=$this->voiceSequence?array_shift($this->voiceSequence):$this->voice;
            return new HttpResponse(200,[],json_encode(['guild_id'=>DiscordRestClient::GUILD_ID,'user_id'=>$id,'channel_id'=>$channel]));
        }
        if(preg_match('~/members/([0-9]+)/roles/([0-9]+)$~',$path,$m)){
            if($this->failGrant){return new HttpResponse(403);}
            if($method==='PUT'){$this->roles=array_values(array_unique([...$this->roles,$m[2]]));}
            elseif($method==='DELETE'){$this->roles=array_values(array_diff($this->roles,[$m[2]]));}
            return new HttpResponse(204);
        }
        if(preg_match('~/members/([0-9]+)$~',$path,$m)){
            if($method==='PATCH'){
                if($this->raceAtPatch){$this->voice='900000000000000199';$this->movedFromOther=true;}
                $this->voice=json_decode($request->body(),true,32,JSON_THROW_ON_ERROR)['channel_id'];
            }
            return new HttpResponse(200,[],json_encode(['user'=>['id'=>$m[1]],'roles'=>$this->roles]));
        }
        if(preg_match('~/channels/([0-9]+)/messages$~',$path,$m)&&$method==='POST'){
            if($this->failPost){throw new RuntimeException('fictional transport loss');}
            return new HttpResponse(200,[],json_encode(['id'=>'900000000000009999','channel_id'=>$m[1]]));
        }
        throw new RuntimeException('Unexpected fake route');
    }
}
function operationsFixture(array $options=[]):array {
    $db=new Database(':memory:');$http=new OperationsFakeHttp();
    $client=new ServerOperationsClient($db,$http,'fictional_operations_bot_token_00000',$options);
    return [$db,$http,$client];
}
$user='900000000000000101';$deadline=1792361880;$session='2026-10-19_0700';
[$db,$http,$client]=operationsFixture();
$same(false,$client->readVoice($user)->ok,'all requests OFF by default');
$same(false,$client->postReceipt($session,$deadline,'first')->ok,'posting OFF');
$same(false,$client->grantTitle($user,40)->ok,'roles OFF');
$same(false,$client->tryRelocate($user,null,'move-first')->ok,'member actions OFF');
$same(0,count($http->calls),'default makes no HTTP request');
$same(false,$client instanceof AtomicMemberGateway,'client never claims atomic member capability');
$same(false,str_contains(print_r($client,true),'fictional_operations_bot_token'),'debug output redacts token');

[$db,$http,$client]=operationsFixture(['enabled'=>true,'posts_enabled'=>true]);
$same(true,$client->readVoice($user)->ok,'general user voice read');
$same('voice_observed',$client->readVoice($user)->code,'voice only returns scoped DTO');
$before=count($http->calls);$post=$client->postReceipt($session,$deadline,'first');
$same(true,$post->ok,'receipt message confirmed');$same('900000000000009999',$post->data['message_id'],'message ID persisted');
$payload=json_decode($http->calls[$before]->body(),true,32,JSON_THROW_ON_ERROR);
$same(Gateway::ALLOWED_MENTIONS,$payload['allowed_mentions'],'receipt mentions none');$same(false,$payload['tts'],'TTS false');
$same(true,$payload['enforce_nonce'],'server nonce is additional protection');$same(25,strlen($payload['nonce']),'nonce bounded');
$same('already_sent',$client->postReceipt($session,$deadline,'changed-caller-key')->code,'session identity overrides arbitrary caller key');
$same($before+1,count($http->calls),'duplicate receipt does not call HTTP');
$same(false,$client->postReceipt($session,$deadline+60,'other-key')->ok,'different deadline cannot repost same session');
$same(false,$client->postText('900000000000000198','bad','bad-target')->ok,'other channel refused');
$same(false,$client->postAchievement($user,40,40,'without-role')->ok,'achievement cannot bypass verified role grant');
$same($before+1,count($http->calls),'rejections have no HTTP side effects');

[$db,$http,$client]=operationsFixture(['enabled'=>true,'posts_enabled'=>true]);$http->failPost=true;
$same(false,$client->postReceipt($session,$deadline,'lost')->ok,'lost response held');
$http->failPost=false;$same(false,$client->postReceipt($session,$deadline,'retry')->ok,'uncertain response never auto-retried');
$same(1,count($http->calls),'one attempt after uncertainty');
$same('uncertain',$db->pdo()->query('SELECT status FROM operation_receipts')->fetchColumn(),'uncertain state persisted');
$db->pdo()->exec("UPDATE operation_receipts SET status='sending'");
$restarted=new ServerOperationsClient($db,$http,'fictional_operations_bot_token_00000',['enabled'=>true,'posts_enabled'=>true]);
$same(false,$restarted->postReceipt($session,$deadline,'restart')->ok,'interrupted send not retried after restart');$same(1,count($http->calls),'restart adds no POST');

$options=['enabled'=>true,'posts_enabled'=>true,'roles_enabled'=>true,'role_ids'=>[1=>'900000000000000141',40=>'900000000000000142']];
[$db,$http,$client]=operationsFixture($options);
$same(true,$client->awardAndAnnounce($user,40,40,'award-first')->ok,'announcement only after verified role update');
$same(['900000000000000149','900000000000000142'],$http->roles,'unrelated role retained, obsolete title removed');
$methods=array_map(static fn(HttpRequest $request):string=>$request->method(),$http->calls);
$same(['GET','PUT','DELETE','GET','POST'],$methods,'per-role mutation then readback before announcement');
$payload=json_decode($http->calls[4]->body(),true,32,JSON_THROW_ON_ERROR);
$same(['parse'=>[],'users'=>[$user],'roles'=>[],'replied_user'=>false],$payload['allowed_mentions'],'only actual awarded user can ping');
$before=count($http->calls);$same(true,$client->awardAndAnnounce($user,40,40,'other-award-key')->ok,'repeat grant is idempotent');
$same(1,count(array_filter($http->calls,static fn(HttpRequest $r):bool=>$r->method()==='POST')),'user/threshold event cannot post twice');
$before=count($http->calls);$same('role_superseded',$client->grantTitle($user,1)->code,'stale lower title cannot overwrite higher title');
$same($before,count($http->calls),'superseded title makes no request');
$db->pdo()->exec("UPDATE operation_role_sync SET lease_owner='other-worker',lease_until=9999999999");
$same('role_sync_busy',$client->grantTitle($user,40)->code,'role operations fenced per person');
[$db,$http,$client]=operationsFixture($options);$http->failGrant=true;
$same(false,$client->awardAndAnnounce($user,40,40,'failed-award')->ok,'failed grant stops announcement');
$same(0,count(array_filter($http->calls,static fn(HttpRequest $r):bool=>$r->method()==='POST')),'failed grant never posts');
$same(['900000000000000141','900000000000000149'],$http->roles,'failed add does not delete existing roles');

// Default DTO restrictions stay unchanged; only an explicit configured role capability adds PUT/DELETE.
$reject=static function(callable $call):bool{try{$call();return false;}catch(Throwable){return true;}};
$url='https://discord.com/api/v10/guilds/'.DiscordRestClient::GUILD_ID.'/members/'.$user.'/roles/900000000000000142';
$same(true,$reject(fn()=>new HttpRequest('PUT',$url,[])),'ordinary request still refuses role PUT');
$scope=new RoleMutationScope(['900000000000000142']);
$same('PUT',(new HttpRequest('PUT',$url,[],'',2000,500,65536,false,$scope))->method(),'scoped role PUT accepted');
$same(true,$reject(fn()=>new HttpRequest('DELETE',str_replace('142','143',$url),[],'',2000,500,65536,false,$scope)),'unconfigured role DELETE rejected');

[$db,$http,$client]=operationsFixture(['enabled'=>true,'member_actions_enabled'=>true]);
$same(false,$client->tryRelocate($user,null,'not-acknowledged')->ok,'member flag alone cannot accept residual risk');
$same(0,count($http->calls),'no reads or mutation without separate acknowledgement');
$moveOptions=['enabled'=>true,'member_actions_enabled'=>true,'accept_voice_race_risk'=>true];
[$db,$http,$client]=operationsFixture($moveOptions);
$same('automatic_moves_disabled',$client->tryRelocate($user,ServerOperationsClient::DESTINATION_ID,'disabled-forced-move')->code,'forced destination rejected even with all flags enabled');
$same(0,count($http->calls),'disabled forced move sends no HTTP');
[$db,$http,$client]=operationsFixture($moveOptions);$http->voice='900000000000000198';
$same('not_in_source_voice',$client->tryRelocate($user,null,'other-vc')->code,'another VC is skipped');
$same(0,count(array_filter($http->calls,static fn(HttpRequest $r):bool=>$r->method()==='PATCH')),'observed other VC never patched');
[$db,$http,$client]=operationsFixture($moveOptions);$http->voiceSequence=[DiscordRestClient::VOICE_CHANNEL_ID,'900000000000000198'];
$same(false,$client->tryRelocate($user,null,'changed-before-final-read')->ok,'change between reads detected');
$same(0,count(array_filter($http->calls,static fn(HttpRequest $r):bool=>$r->method()==='PATCH')),'no patch after final read changes');
[$db,$http,$client]=operationsFixture($moveOptions);$http->raceAtPatch=true;
$risk=$client->tryRelocate($user,null,'race-after-final-read');
$same(true,$risk->ok,'best effort may disconnect');$same(true,$http->movedFromOther,'test explicitly demonstrates unresolved origin race');
$same(false,$risk->data['atomic'],'result never claims atomic safety');$same(true,$risk->data['residual_risk'],'risk remains explicit even after success');
$same(false,$risk->data['automatic_compensation'],'no guessed corrective move');
$http->voice=DiscordRestClient::VOICE_CHANNEL_ID;$http->raceAtPatch=false;$before=count($http->calls);
$same(false,$client->tryRelocate($user,null,'race-after-final-read')->ok,'same action cannot patch twice');
$same($before+2,count($http->calls),'repeat only observes and adds no mutation');
[$db,$http,$client]=operationsFixture($moveOptions+['request_guard'=>static fn():bool=>false]);
$same(false,$client->tryRelocate($user,null,'budget-lost')->ok,'expired worker budget prevents mutation');
$same(0,count($http->calls),'guard prevents every HTTP request');

[$db,$http,$client]=operationsFixture(['enabled'=>true]);$http->next=new HttpResponse(429,[],json_encode(['retry_after'=>10,'global'=>true]));
$same('rate_limited',$client->readVoice($user)->code,'429 safely interpreted');
$same('rate_limited',$client->readVoice('900000000000000102')->code,'global cooldown applies to other people');
$same(1,count($http->calls),'cooldown does not send requests repeatedly');
[$db,$http,$client]=operationsFixture(['enabled'=>true]);$http->next=new HttpResponse(401);
$same('unauthorized',$client->readVoice($user)->code,'authentication failure');$same('unauthorized',$client->readVoice($user)->code,'authentication circuit stays stopped');
$same(1,count($http->calls),'authentication failure not repeatedly sent');
[$db,$http,$client]=operationsFixture(['enabled'=>true]);$http->next=new HttpResponse(429,['retry-after'=>'invalid'],'not-json');
$same('rate_limited',$client->readVoice($user)->code,'malformed 429 fails into conservative cooldown');
$same('rate_limited',$client->readVoice($user)->code,'malformed rate response cannot loop requests');
$same(1,count($http->calls),'invalid 429 still makes only one request');
[$db,$http,$client]=operationsFixture(['enabled'=>true]);$http->next=new HttpResponse(401,[],'not-json');
$same('unauthorized',$client->readVoice($user)->code,'non-JSON 401 still stops authentication');
$same('unauthorized',$client->readVoice($user)->code,'non-JSON auth failure retains circuit');
$same(1,count($http->calls),'non-JSON auth error not repeated');
echo "Operations adapter: {$checks} assertions passed (fake HTTP only; guarded disconnect race explicitly demonstrated; forced moves disabled).\n";
