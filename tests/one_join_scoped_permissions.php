<?php
declare(strict_types=1);
require_once __DIR__.'/artifact_directory.php';
require dirname(__DIR__) . '/bootstrap.php';

use RadioStamp\Core\FrozenClock;
use RadioStamp\Discord\DiscordRestClient;
use RadioStamp\Discord\HttpAdapter;
use RadioStamp\Discord\HttpRequest;
use RadioStamp\Discord\HttpResponse;
use RadioStamp\Discord\RestGateway;
use RadioStamp\Discord\RoleMutationScope;
use RadioStamp\Discord\RoomMutationScope;
use RadioStamp\Http\BotValidationCheck;
use RadioStamp\Http\InteractionQueue;
use RadioStamp\Http\PrivateConfig as Scope;
use RadioStamp\Security\TokenCipher;
use RadioStamp\Validation\OneJoin\GuardedHttp;
use RadioStamp\Validation\OneJoin\PermissionProfile;
use RadioStamp\Validation\OneJoin\Store;
use RadioStamp\Validation\OneJoin\Trial;
use RadioStamp\Validation\OneJoin\Window;

$ojspAssertions=0;
function ojspCheck(bool $condition,string $description):void {global $ojspAssertions;++$ojspAssertions;if(!$condition){throw new RuntimeException('Scoped one-join assertion failed: '.$description);}}
function ojspSame(mixed $expected,mixed $actual,string $description):void {ojspCheck($expected===$actual,$description);}

/** Entirely fictional responses; requests never leave this PHP process. */
final class OjspHttp implements HttpAdapter
{
    public const COMMON='900000000000088901';
    public const GRANT='900000000000088902';
    public const TITLE_IDS=['800000000000001010','800000000000001011','800000000000001012','800000000000001013','800000000000001014',
        '800000000000001015','800000000000001016','800000000000001017','800000000000001018','800000000000001019'];
    public array $calls=[];
    public array $memberRoles=[self::COMMON,self::GRANT];
    public array $roles=[];
    public array $channels=[];
    public string $ownerId=Scope::OWNER_ID;
    public string $botId=Scope::APPLICATION_ID;
    public bool $pending=false;
    public ?Closure $hook=null;

    public function __construct()
    {
        // Fixed published IDs are duplicated deliberately: changing production constants
        // must not silently change the approved test contract.
        $this->roles=[['id'=>Scope::GUILD_ID,'permissions'=>(string)(1024|2048),'position'=>0,'managed'=>false],
            ['id'=>self::COMMON,'permissions'=>'0','position'=>100,'managed'=>false],
            ['id'=>self::GRANT,'permissions'=>'268435456','position'=>99,'managed'=>false],
            ['id'=>'900000000000090001','permissions'=>'8','position'=>200,'managed'=>false]];
        foreach(self::TITLE_IDS as$i=>$id){$this->roles[]=['id'=>$id,'permissions'=>'0','position'=>$i+1,'managed'=>false];}
        foreach([Scope::GUIDE_ID,Scope::RECORDS_ID,Scope::VOICE_ID]as$id){$voice=$id===Scope::VOICE_ID;
            $this->channels[$id]=['id'=>$id,'guild_id'=>Scope::GUILD_ID,'type'=>$voice?2:0,'nsfw'=>true,'parent_id'=>'800000000000001004',
                'permission_overwrites'=>[
                    ['id'=>Scope::GUILD_ID,'type'=>0,'allow'=>'0','deny'=>(string)($voice?(1024|1048576):1024)],
                    ['id'=>Scope::APPLICATION_ID,'type'=>1,'allow'=>(string)($voice?(1024|2048|1048576|16777216):(1024|2048|32768)),'deny'=>'0'],
                ]];
        }
    }
    public function exchange(HttpRequest $request):HttpResponse
    {
        $path=(string)parse_url($request->url(),PHP_URL_PATH);$method=$request->method();
        $this->calls[]=['method'=>$method,'path'=>$path];
        if($this->hook!==null){($this->hook)($method,$path);}
        if($method!=='GET'){throw new RuntimeException('Fictional check must not mutate transport');}
        $data=match($path){
            '/api/v10/users/@me'=>['id'=>$this->botId,'bot'=>true],
            '/api/v10/oauth2/applications/@me'=>['id'=>Scope::APPLICATION_ID,'bot'=>['id'=>$this->botId]],
            '/api/v10/guilds/'.Scope::GUILD_ID=>['id'=>Scope::GUILD_ID,'owner_id'=>$this->ownerId],
            '/api/v10/guilds/'.Scope::GUILD_ID.'/members/'.Scope::APPLICATION_ID=>['user'=>['id'=>Scope::APPLICATION_ID],'roles'=>$this->memberRoles,'pending'=>$this->pending],
            '/api/v10/guilds/'.Scope::GUILD_ID.'/roles'=>$this->roles,
            default=>$this->channels[substr($path,strrpos($path,'/')+1)]??throw new RuntimeException('Unexpected fictional read'),
        };
        return new HttpResponse(200,[],json_encode($data,JSON_THROW_ON_ERROR));
    }
    public function role(string $id):int {foreach($this->roles as$i=>$role){if($role['id']===$id){return $i;}}throw new RuntimeException('Synthetic role missing');}
    public function client():DiscordRestClient {return new DiscordRestClient($this,Scope::APPLICATION_ID,'FICTIONAL_SCOPED_TRIAL_BOT_TOKEN',['enabled'=>true]);}
}

$ojspBase=sys_get_temp_dir().'/radio-onejoin-scope-tests-'.bin2hex(random_bytes(8));mkdir($ojspBase,0700);
$ojspFailure=null;$ojspFixtures=0;
try{
    (static function()use($ojspBase,&$ojspFixtures):void{
    $fixture=static function()use($ojspBase,&$ojspFixtures):array{
        $root=$ojspBase.'/case-'.++$ojspFixtures;$private=$root.'/private';$public=$root.'/public_html';
        foreach([$root,$private,$private.'/instances',$private.'/var',$public]as$dir){mkdir($dir,0700);}
        $source=$private.'/var/source.sqlite';file_put_contents($source,'FICTIONAL_ORIGINAL_NOT_A_SQLITE_FILE');
        $clock=new FrozenClock('2026-11-01T07:00:00+09:00');$now=$clock->now()->getTimestamp();
        $activation=Window::activation($now-300,$now-240,$now-60,$now);
        $store=new Store($private,$public,$activation,$clock,true);
        $pair=sodium_crypto_sign_seed_keypair(hash('sha256','FICTIONAL_SCOPED_PERMISSION_KEY',true));
        $config=['mode'=>'admin_validation','admin_validation_enabled'=>true,'application_id'=>Scope::APPLICATION_ID,'guild_id'=>Scope::GUILD_ID,'admin_user_ids'=>[Scope::OWNER_ID],
            'public_key_hex'=>bin2hex(sodium_crypto_sign_publickey($pair)),'bot_token'=>'FICTIONAL_SCOPED_TRIAL_BOT_TOKEN','token_encryption_key_hex'=>hash('sha256','FICTIONAL_SCOPED_QUEUE_KEY'),
            'private_db_path'=>$store->root.'/fixture.sqlite','text_channel_id'=>Scope::GUIDE_ID,'records_channel_id'=>Scope::RECORDS_ID,'voice_channel_id'=>Scope::VOICE_ID,
            'timezone'=>'Asia/Tokyo','member_actions_enabled'=>false,'live_automation_enabled'=>false,'role_ids'=>[]];
        $id=(string)(900000000000080000+$ojspFixtures);
        $data=['application_id'=>Scope::APPLICATION_ID,'type'=>2,'id'=>$id,'guild_id'=>Scope::GUILD_ID,'channel_id'=>Scope::GUIDE_ID,
            'token'=>'FICTIONAL_SCOPED_CALLBACK_TOKEN','member'=>['user'=>['id'=>Scope::OWNER_ID,'username'=>'FICTIONAL_TESTER']],
            'data'=>['id'=>Trial::COMMAND_ID,'type'=>1,'name'=>'radio-check','options'=>[]]];
        $raw=json_encode($data,JSON_THROW_ON_ERROR);$stamp=(string)$now;$server=['REQUEST_METHOD'=>'POST','HTTP_X_SIGNATURE_TIMESTAMP'=>$stamp,
            'HTTP_X_SIGNATURE_ED25519'=>bin2hex(sodium_crypto_sign_detached($stamp.$raw,sodium_crypto_sign_secretkey($pair)))];
        $response=$store->admit($config,$clock,$server,$raw,$data,'check');ojspSame($id,$response->interactionId,'signed fixed-owner check admitted into disposable queue');
        $store->ack($id,'under_1s');ojspCheck($store->start($id),'fake acknowledged operation running');
        $queue=new InteractionQueue($store->db->pdo(),new TokenCipher($config['token_encryption_key_hex']));
        ojspSame($id,$queue->claim($now,120,$id,'admin_validation')['interaction_id']??null,'worker claims only the acknowledged fixed test job');
        ojspSame('FICTIONAL_ORIGINAL_NOT_A_SQLITE_FILE',file_get_contents($source),'original sentinel untouched');
        ojspCheck(!file_exists($source.'-wal')&&!file_exists($source.'-shm'),'source sentinel never opened as SQLite');
        return compact('root','private','public','source','clock','activation','store','id','config');
    };
    $profile=static fn(array $f):?PermissionProfile=>PermissionProfile::forOperation($f['store'],$f['id'],$f['clock']);
    $run=static function(OjspHttp $http,?PermissionProfile $p):array{
        $result=(new BotValidationCheck($http->client(),$p))->run();
        foreach($http->calls as$call){ojspSame('GET',$call['method'],'permission inspection sends GET only');}
        return $result;
    };

    // Default API remains exactly the legacy strict check: elevated permissions never opt in implicitly.
    $legacy=new OjspHttp();ojspCheck(!$run($legacy,null)['ok'],'default strict guard still refuses current elevated roles');
    $legacy=new OjspHttp();$legacy->memberRoles=[];$legacy->roles=[['id'=>Scope::GUILD_ID,'permissions'=>(string)(1024|2048|32768|1048576),'position'=>0,'managed'=>false]];
    foreach($legacy->channels as&$channel){$channel['permission_overwrites']=[];}unset($channel);
    ojspCheck($run($legacy,null)['ok'],'default strict guard still accepts its prior minimal permission fixture');

    $f=$fixture();$p=$profile($f);ojspCheck($p instanceof PermissionProfile&&$p->current(),'scoped capability comes from same acknowledged running check');
    $valid=new OjspHttp();$validResult=$run($valid,$p);ojspCheck($validResult['ok'],'only exact current approved permission profile is accepted');
    ojspSame(8,count($valid->calls),'fixed identities, roles and exactly three channels read');
    ojspSame(['bot_identity','application_identity','guild_membership','guide_permissions','records_permissions','voice_permissions','restricted_permissions'],array_keys($validResult['checks']),'result exposes only existing fixed check names');
    $reordered=new OjspHttp();$reordered->memberRoles=array_reverse($reordered->memberRoles);$reordered->roles=array_reverse($reordered->roles);
    ojspCheck($run($reordered,$p)['ok'],'role/member response order does not alter the fixed permission facts');

    // Mutate the fixed authority facts one at a time. Each case uses a fresh client and no network.
    $mutations=[
        'bot administrator'=>static function(OjspHttp $h):void{$h->roles[$h->role(OjspHttp::GRANT)]['permissions']=(string)(268435456|8);},
        'grant adds harmless bit too'=>static function(OjspHttp $h):void{$h->roles[$h->role(OjspHttp::GRANT)]['permissions']=(string)(268435456|1024);},
        'common role elevated'=>static function(OjspHttp $h):void{$h->roles[$h->role(OjspHttp::COMMON)]['permissions']='16777216';},
        'everyone role grants moves'=>static function(OjspHttp $h):void{$h->roles[0]['permissions']=(string)(1024|2048|16777216);},
        'everyone role grants manage roles'=>static function(OjspHttp $h):void{$h->roles[0]['permissions']=(string)(1024|2048|268435456);},
        'unknown assigned role'=>static function(OjspHttp $h):void{$h->memberRoles[]='900000000000090001';},
        'missing approved member role'=>static function(OjspHttp $h):void{array_pop($h->memberRoles);},
        'duplicate assigned member role'=>static function(OjspHttp $h):void{$h->memberRoles[]=OjspHttp::GRANT;},
        'wrong grant role id'=>static function(OjspHttp $h):void{$h->roles[$h->role(OjspHttp::GRANT)]['id']='900000000000090002';},
        'managed grant role'=>static function(OjspHttp $h):void{$h->roles[$h->role(OjspHttp::GRANT)]['managed']=true;},
        'missing title'=>static function(OjspHttp $h):void{array_pop($h->roles);},
        'title permission nonzero'=>static function(OjspHttp $h):void{$h->roles[$h->role(OjspHttp::TITLE_IDS[0])]['permissions']='1';},
        'title at grant level'=>static function(OjspHttp $h):void{$h->roles[$h->role(OjspHttp::TITLE_IDS[0])]['position']=99;},
        'grant above common'=>static function(OjspHttp $h):void{$h->roles[$h->role(OjspHttp::GRANT)]['position']=101;},
        'ordinary role below bot highest'=>static function(OjspHttp $h):void{$h->roles[]=['id'=>'900000000000090003','permissions'=>'0','position'=>50,'managed'=>false];},
        'duplicate guild role'=>static function(OjspHttp $h):void{$h->roles[]=$h->roles[0];},
        'bot owns guild'=>static function(OjspHttp $h):void{$h->ownerId=Scope::APPLICATION_ID;},
        'bot membership pending'=>static function(OjspHttp $h):void{$h->pending=true;},
        'wrong bot identity'=>static function(OjspHttp $h):void{$h->botId='900000000000090004';},
        'wrong guide guild'=>static function(OjspHttp $h):void{$h->channels[Scope::GUIDE_ID]['guild_id']='900000000000090005';},
        'wrong voice channel type'=>static function(OjspHttp $h):void{$h->channels[Scope::VOICE_ID]['type']=0;},
        'moves in guide'=>static function(OjspHttp $h):void{$h->channels[Scope::GUIDE_ID]['permission_overwrites'][1]['allow']=(string)(1024|2048|32768|16777216);},
        'moves in records'=>static function(OjspHttp $h):void{$h->channels[Scope::RECORDS_ID]['permission_overwrites'][1]['allow']=(string)(1024|2048|32768|16777216);},
        'voice everyone moves'=>static function(OjspHttp $h):void{$h->channels[Scope::VOICE_ID]['permission_overwrites'][0]['allow']='16777216';},
        'voice role moves'=>static function(OjspHttp $h):void{$h->channels[Scope::VOICE_ID]['permission_overwrites'][]=['id'=>OjspHttp::COMMON,'type'=>0,'allow'=>'16777216','deny'=>'0'];},
        'voice duplicate bot overwrite'=>static function(OjspHttp $h):void{$h->channels[Scope::VOICE_ID]['permission_overwrites'][]=$h->channels[Scope::VOICE_ID]['permission_overwrites'][1];},
        'voice bot extra elevated bit'=>static function(OjspHttp $h):void{$h->channels[Scope::VOICE_ID]['permission_overwrites'][1]['allow']=(string)(1024|2048|1048576|16777216|16);},
        'missing guide attachment'=>static function(OjspHttp $h):void{$h->channels[Scope::GUIDE_ID]['permission_overwrites'][1]['allow']=(string)(1024|2048);},
        'missing voice connect'=>static function(OjspHttp $h):void{$h->channels[Scope::VOICE_ID]['permission_overwrites'][1]['allow']=(string)(1024|2048|16777216);},
    ];
    foreach($mutations as$name=>$mutate){$http=new OjspHttp();$mutate($http);ojspCheck(!$run($http,$p)['ok'],$name.' refused by scoped check');}

    // A scope token remains tied to its actual job, ACK and pending state, not just copied parameters.
    foreach([
        'wrong tester'=>"UPDATE interaction_jobs SET user_id='900000000000090088'",
        'wrong queue mode'=>"UPDATE interaction_jobs SET scope_mode='operations'",
        'wrong queued action'=>"UPDATE interaction_jobs SET action='attend'",
        'wrong queued target'=>"UPDATE interaction_jobs SET target='different_target'",
        'wrong operation stage'=>"UPDATE trial_operations SET step='inside'",
        'missing ACK'=>"UPDATE trial_operations SET ack=0",
        'operation no longer running'=>"UPDATE trial_operations SET status='done'",
        'queue job no longer claimed'=>"UPDATE interaction_jobs SET state='pending'",
        'queue lease malformed'=>"UPDATE interaction_jobs SET lease_id='not-a-lease'",
        'queue reclaimed attempt'=>"UPDATE interaction_jobs SET attempts=2",
        'job predates trial'=>"UPDATE interaction_jobs SET created_at=1",
    ]as$name=>$sql){$bad=$fixture();$active=$profile($bad);ojspCheck($active!==null,$name.' starts with valid token');$bad['store']->db->pdo()->exec($sql);
        ojspSame(null,$profile($bad),$name.' cannot create new scoped capability');ojspCheck(!$active->current(),$name.' invalidates existing capability');$http=new OjspHttp();ojspCheck(!$run($http,$active)['ok'],$name.' refused on use');ojspSame(0,count($http->calls),$name.' fails before HTTP');}
    foreach(['pending','phase','run','activation_hash']as$field){$bad=$fixture();$active=$profile($bad);$bad['store']->update(static function(array &$state)use($field):void{$state[$field]='FICTIONAL_DIFFERENT_BINDING';});
        ojspSame(null,$profile($bad),'changed '.$field.' refuses capability creation');ojspCheck(!$active->current(),'changed '.$field.' invalidates issued capability');$http=new OjspHttp();ojspCheck(!$run($http,$active)['ok'],'changed '.$field.' fails on use');ojspSame(0,count($http->calls),'changed '.$field.' performs no HTTP');}

    $future=$fixture();$token=$profile($future);$future['clock']->set('@'.($future['activation']['starts_at']-1));
    ojspSame(null,$profile($future),'before starts_at no capability');ojspCheck(!$token->current(),'existing capability cannot survive clock before window');
    $expired=$fixture();$token=$profile($expired);$expired['clock']->set('@'.$expired['activation']['expires_at']);
    ojspSame(null,$profile($expired),'exact expires_at no capability');$http=new OjspHttp();ojspCheck(!$run($http,$token)['ok'],'expiry rejected');ojspSame(0,count($http->calls),'expiry before first read sends no HTTP');
    $during=$fixture();$token=$profile($during);$http=new OjspHttp();$http->hook=static function()use($during):void{$during['clock']->set('@'.$during['activation']['expires_at']);};
    ojspCheck(!$run($http,$token)['ok'],'scope expiry while a GET is in flight cannot pass');ojspCheck(count($http->calls)<=1,'expiry observed before another permission read');
    $last=$fixture();$token=$profile($last);$http=new OjspHttp();$http->hook=static function(string $method,string $path)use($last):void{if($path==='/api/v10/channels/'.Scope::VOICE_ID){$last['clock']->set('@'.$last['activation']['expires_at']);}};
    ojspCheck(!$run($http,$token)['ok'],'expiry during final channel response also refuses success');ojspSame(8,count($http->calls),'only the bounded eight GET reads occurred before final expiry');
    ojspSame(null,PermissionProfile::forOperation($f['store'],'900000000000099999',$f['clock']),'another interaction has no capability');
    $otherVoice=(new OjspHttp())->channels[Scope::VOICE_ID];$otherVoice['id']='900000000000090100';
    ojspCheck(!$p->channelAllowed($otherVoice,1024|2048|1048576|16777216|268435456),'scoped profile itself refuses another VC even with same flags');

    // Accepting an observed permission does not give the trial a matching mutation transport.
    $spy=new class implements HttpAdapter {public int $calls=0;public function exchange(HttpRequest $r):HttpResponse{++$this->calls;return new HttpResponse(200,[],'{}');}};
    $guard=new GuardedHttp($spy,$f['store'],$f['id'],$f['clock']);$headers=['Authorization: Bot FICTIONAL_SCOPED_TRIAL_BOT_TOKEN'];
    $forbidden=[
        new HttpRequest('PATCH','https://discord.com/api/v10/guilds/'.Scope::GUILD_ID.'/members/'.Scope::OWNER_ID,$headers,'{"channel_id":null}'),
        new HttpRequest('PATCH','https://discord.com/api/v10/guilds/'.Scope::GUILD_ID.'/members/'.Scope::OWNER_ID,$headers,'{"channel_id":"900000000000090099"}'),
        new HttpRequest('PUT','https://discord.com/api/v10/guilds/'.Scope::GUILD_ID.'/members/'.Scope::OWNER_ID.'/roles/'.OjspHttp::TITLE_IDS[0],$headers,'',5000,1500,1048576,false,new RoleMutationScope([OjspHttp::TITLE_IDS[0]])),
        new HttpRequest('PUT','https://discord.com/api/v10/channels/'.Scope::VOICE_ID.'/permissions/'.Scope::GUILD_ID,$headers,'{"allow":"0","deny":"1048576"}',5000,1500,1048576,false,null,new RoomMutationScope()),
        new HttpRequest('POST','https://discord.com/api/v10/guilds/'.Scope::GUILD_ID.'/roles',$headers,'{}'),
        new HttpRequest('POST','https://discord.com/api/v10/channels/'.Scope::GUIDE_ID.'/messages',$headers,'{}'),
        new HttpRequest('POST','https://discord.com/api/v10/channels/'.Scope::RECORDS_ID.'/messages',$headers,'{}'),
    ];
    foreach($forbidden as$request){ojspSame(0,$guard->exchange($request)->status,'trial refuses member/role/room/other-channel mutation');ojspSame(0,$spy->calls,'forbidden mutation never reaches inner transport');}
    $gateway=new RestGateway(new DiscordRestClient($spy,Scope::APPLICATION_ID,'FICTIONAL_SCOPED_TRIAL_BOT_TOKEN',['enabled'=>true]),$f['clock']);
    ojspCheck(!$gateway->sendMessage(Scope::VOICE_ID,'x','x'),'validation gateway still refuses public sends');
    ojspCheck(!$gateway->setRoomOpen(Scope::VOICE_ID,true,'x'),'validation gateway still refuses room mutation');
    ojspCheck(!$gateway->moveMember(Scope::GUILD_ID,Scope::OWNER_ID,Scope::GUIDE_ID,'x'),'validation gateway still refuses moves');
    ojspCheck(!$gateway->disconnectMember(Scope::GUILD_ID,Scope::OWNER_ID,'x'),'validation gateway still refuses disconnect');
    ojspCheck(!$gateway->syncDecorationRole(Scope::GUILD_ID,Scope::OWNER_ID,OjspHttp::TITLE_IDS[0],'x'),'validation gateway still refuses role mutation');
    ojspSame(0,$spy->calls,'scoped capability adds no mutation transport');
    ob_start();var_dump($p);$debug=ob_get_clean();ojspCheck(!str_contains($debug,'FICTIONAL_SCOPED_'),'scope debug contains no synthetic credential/callback');
    })();
}catch(Throwable $error){$ojspFailure=$error->getMessage();}
finally{
    gc_collect_cycles();$owned=realpath($ojspBase);$tmp=realpath(sys_get_temp_dir());
    if($owned!==false&&$tmp!==false&&dirname($owned)===$tmp&&str_starts_with(basename($owned),'radio-onejoin-scope-tests-')){
        $remove=static function(string $directory)use(&$remove):void{foreach(new DirectoryIterator($directory)as$entry){if($entry->isDot()){continue;}if($entry->isLink()){unlink($entry->getPathname());}elseif($entry->isDir()){$remove($entry->getPathname());rmdir($entry->getPathname());}else{unlink($entry->getPathname());}}};$remove($owned);rmdir($owned);
    }
}
if($ojspFailure!==null){fwrite(STDERR,$ojspFailure."\n");exit(1);}
echo 'Scoped one-join permissions: '.$ojspAssertions.' assertions passed (fictional HTTP + disposable local SQLite only).'."\n";
