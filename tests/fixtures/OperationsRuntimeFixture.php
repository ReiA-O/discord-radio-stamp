<?php
declare(strict_types=1);
use RadioStamp\Discord\{HttpAdapter,HttpRequest,HttpResponse,DiscordRestClient,GatewayTransport};

final class RuntimeFixtureHttp implements HttpAdapter
{
    public array $calls=[],$messages=[],$callbacks=[],$commands=[],$roles=[],$voices=[];
    public string $allow='1024',$deny='0';public int $connections=0;public ?RuntimeFixtureGateway $gateway=null;
    public bool $failConnect=false,$sequenceGap=false,$raceAfterGuard=false,$raceBeforeGuard=false,$wrongSourceApplied=false;
    public function exchange(HttpRequest $request):HttpResponse
    {
        $this->calls[]=$request;$path=parse_url($request->url(),PHP_URL_PATH);$method=$request->method();
        if($path==='/api/v10/users/@me'){return new HttpResponse(200,[],json_encode(['id'=>DiscordRestClient::APPLICATION_ID,'bot'=>true]));}
        if($path==='/api/v10/gateway/bot'){return new HttpResponse(200,[],json_encode(['url'=>'wss://gateway.discord.gg/','shards'=>1,'session_start_limit'=>['remaining'=>999,'max_concurrency'=>1]]));}
        if(preg_match('~/webhooks/[0-9]+/[^/]+/messages/@original$~',$path)&&$method==='PATCH'){
            $this->callbacks[]=['png'=>str_contains($request->body(),"\x89PNG\r\n\x1a\n"),'json'=>str_starts_with($request->body(),'{')?json_decode($request->body(),true):null];
            return new HttpResponse(200,[],json_encode(['id'=>'900000000000009900']));
        }
        if(preg_match('~/voice-states/([0-9]+)$~',$path,$m)){
            $channel=$this->voices[$m[1]]??null;
            if($this->raceBeforeGuard&&$this->gateway!==null){$this->voices[$m[1]]='900000000000000199';$this->gateway->change($m[1],'900000000000000199');$this->raceBeforeGuard=false;}
            return new HttpResponse(200,[],json_encode(['guild_id'=>DiscordRestClient::GUILD_ID,'user_id'=>$m[1],'channel_id'=>$channel]));
        }
        if(preg_match('~/members/([0-9]+)/roles/([0-9]+)$~',$path,$m)){
            $roles=$this->roles[$m[1]]??[];
            $this->roles[$m[1]]=$method==='PUT'?array_values(array_unique([...$roles,$m[2]])):array_values(array_diff($roles,[$m[2]]));return new HttpResponse(204);
        }
        if(preg_match('~/members/([0-9]+)$~',$path,$m)){
            if($method==='PATCH'){
                if($this->raceAfterGuard){$this->voices[$m[1]]='900000000000000199';$this->wrongSourceApplied=true;$this->raceAfterGuard=false;}
                $channel=json_decode($request->body(),true,32,JSON_THROW_ON_ERROR)['channel_id'];$this->voices[$m[1]]=$channel;$this->gateway?->change($m[1],$channel);
            }
            return new HttpResponse(200,[],json_encode(['user'=>['id'=>$m[1]],'roles'=>$this->roles[$m[1]]??[]]));
        }
        if($path==='/api/v10/channels/'.DiscordRestClient::VOICE_CHANNEL_ID){return new HttpResponse(200,[],json_encode(['id'=>DiscordRestClient::VOICE_CHANNEL_ID,'permission_overwrites'=>[['id'=>DiscordRestClient::GUILD_ID,'type'=>0,'allow'=>$this->allow,'deny'=>$this->deny]]]));}
        if($path==='/api/v10/channels/'.DiscordRestClient::VOICE_CHANNEL_ID.'/permissions/'.DiscordRestClient::GUILD_ID&&$method==='PUT'){
            $data=json_decode($request->body(),true,32,JSON_THROW_ON_ERROR);$this->allow=$data['allow'];$this->deny=$data['deny'];return new HttpResponse(204);
        }
        if(preg_match('~/channels/([0-9]+)/messages$~',$path,$m)&&$method==='POST'){
            $data=json_decode($request->body(),true,32,JSON_THROW_ON_ERROR);$id=(string)(900000000000008000+count($this->messages));
            $this->messages[]=$data+['id'=>$id,'channel_id'=>$m[1]];return new HttpResponse(200,[],json_encode(['id'=>$id,'channel_id'=>$m[1]]));
        }
        if(str_ends_with($path,'/commands')){
            if($method==='GET'){return new HttpResponse(200,[],json_encode($this->commands));}
            if($method==='POST'){$data=json_decode($request->body(),true,32,JSON_THROW_ON_ERROR);$data['id']=(string)(900000000000007000+count($this->commands));$this->commands[]=$data;return new HttpResponse(200,[],json_encode($data));}
        }
        throw new RuntimeException('Unhandled fictional route');
    }
    public function transport():RuntimeFixtureGateway{$this->gateway=new RuntimeFixtureGateway($this);return $this->gateway;}
    public function memberPatches():array{return array_values(array_filter($this->calls,static fn(HttpRequest $r):bool=>$r->method()==='PATCH'&&preg_match('~/guilds/[0-9]+/members/[0-9]+$~',$r->url())===1));}
}
final class RuntimeFixtureGateway implements GatewayTransport
{
    public array $frames=[],$sent=[];public bool $closed=false,$autoAck=true;public ?Closure $onWait=null;private int $sequence=2;
    public function __construct(private RuntimeFixtureHttp $http)
    {
        $states=[];foreach($http->voices as$user=>$channel){if($channel!==null){$states[]=['user_id'=>(string)$user,'channel_id'=>$channel];}}
        $this->frames=[['op'=>10,'d'=>['heartbeat_interval'=>45000]],
            ['op'=>0,'s'=>1,'t'=>'READY','d'=>['user'=>['id'=>DiscordRestClient::APPLICATION_ID,'bot'=>true],'session_id'=>'fictional-do-not-retain-session']],
            ['op'=>0,'s'=>$http->sequenceGap?4:2,'t'=>'GUILD_CREATE','d'=>['id'=>DiscordRestClient::GUILD_ID,'voice_states'=>$states]]];
    }
    public function connect():bool{$this->http->connections++;return !$this->http->failConnect;}
    public function send(#[SensitiveParameter] array $payload):bool{$this->sent[]=$payload;if(($payload['op']??null)===1&&$this->autoAck){$this->frames[]=['op'=>11];}return true;}
    public function receive(float $timeoutSeconds):?array{if($this->frames){return array_shift($this->frames);}if($this->onWait!==null){($this->onWait)($timeoutSeconds);}return null;}
    public function change(string $user,?string $channel):void{$this->frames[]=['op'=>0,'s'=>++$this->sequence,'t'=>'VOICE_STATE_UPDATE','d'=>['guild_id'=>DiscordRestClient::GUILD_ID,'user_id'=>$user,'channel_id'=>$channel]];}
    public function close():void{$this->closed=true;}
}
function runtimeFixtureConfig(string $root,string $policy='manual',bool $roles=false):array
{
    $config=require dirname(__DIR__,2).'/config/operations.example.php';$keys=sodium_crypto_sign_seed_keypair(str_repeat("\x17",32));
    $config=array_replace($config,['operations_enabled'=>true,'participant_commands_enabled'=>true,'posts_enabled'=>true,'room_permissions_enabled'=>true,
        'room_baseline'=>['allow'=>'1024','deny'=>'0'],'periodic_worker_enabled'=>true,'closure_policy'=>$policy,
        'member_actions_enabled'=>$policy==='best_effort','accept_voice_race_risk'=>$policy==='best_effort',
        'participant_scope'=>'allowlist','participant_user_ids'=>['800000000000001021','900000000000000102'],'bot_token'=>'fictional_runtime_bot_token_000000',
        'public_key_hex'=>bin2hex(sodium_crypto_sign_publickey($keys)),'token_encryption_key_hex'=>str_repeat('47',32),
        'private_db_path'=>$root.'/private/application.sqlite','private_root'=>$root.'/private','roles_enabled'=>$roles,'achievement_notifications_enabled'=>$roles]);
    if($roles){$config['role_ids']=RadioStamp\Operations\OperationsConfig::TITLE_ROLE_IDS;}
    return $config;
}
