<?php
declare(strict_types=1);
require_once __DIR__.'/artifact_directory.php';
require dirname(__DIR__).'/bootstrap.php';

use RadioStamp\Core\Database;
use RadioStamp\Discord\{DiscordRestClient,ParticipantCommand,ServerOperationsClient,HttpAdapter,HttpRequest,HttpResponse};

$checks=0;
$same=static function(mixed $expected,mixed $actual,string $why)use(&$checks):void{
    ++$checks;
    if($expected!==$actual){throw new RuntimeException('Participant command access: '.$why.' expected='.json_encode($expected).' actual='.json_encode($actual));}
};

/** Only fictional, fixed-guild inventory and per-ID public metadata patches are accepted. */
final class ParticipantCommandAccessHttp implements HttpAdapter
{
    public array $calls=[];
    public bool $failPatch=false,$staleReadback=false,$allowCreate=false;
    private int $reads=0;
    public function __construct(public array $commands){}
    public function exchange(HttpRequest $request):HttpResponse
    {
        $this->calls[]=$request;
        $path=parse_url($request->url(),PHP_URL_PATH);
        $base='/api/v10/applications/'.DiscordRestClient::APPLICATION_ID.'/guilds/'.DiscordRestClient::GUILD_ID.'/commands';
        if($request->method()==='GET'&&$path===$base){
            ++$this->reads;$rows=$this->commands;
            if($this->staleReadback&&$this->reads>1){$rows[0]['nsfw']=true;}
            return new HttpResponse(200,[],json_encode($rows,JSON_THROW_ON_ERROR));
        }
        if($this->allowCreate&&$request->method()==='POST'&&$path===$base){
            $body=json_decode($request->body(),true,32,JSON_THROW_ON_ERROR);
            if(!in_array($body['name']??null,['radio-attend','radio-card','radio-status'],true)||($body['nsfw']??null)!==false){throw new RuntimeException('Only the three all-ages participant commands may be created.');}
            $body['id']=(string)(900000000000007100+count($this->commands));$this->commands[]=$body;
            return new HttpResponse(201,[],json_encode($body,JSON_THROW_ON_ERROR));
        }
        if($request->method()==='PATCH'&&is_string($path)&&str_starts_with($path,$base.'/')){
            $id=substr($path,strlen($base)+1);
            $body=json_decode($request->body(),true,32,JSON_THROW_ON_ERROR);
            $allowed=['nsfw'=>false,'default_member_permissions'=>null];
            if($body===[]||array_diff_key($body,$allowed)!==[]){throw new RuntimeException('Only public command access metadata may be patched.');}
            foreach($body as$key=>$value){if($value!==$allowed[$key]){throw new RuntimeException('Unexpected public command metadata.');}}
            foreach($this->commands as &$row){
                if(($row['id']??null)!==$id){continue;}
                if(!in_array($row['name']??null,['radio-attend','radio-card','radio-status'],true)){throw new RuntimeException('Unrelated command mutation.');}
                if($this->failPatch){unset($row);return new HttpResponse(403,[],'{}');}
                $row=array_replace($row,$body);$response=$row;unset($row);
                return new HttpResponse(200,[],json_encode($response,JSON_THROW_ON_ERROR));
            }
            unset($row);
            throw new RuntimeException('Unknown command ID.');
        }
        throw new RuntimeException('Unexpected global, bulk, creation, deletion or unrelated route.');
    }
    public function writes():array{return array_values(array_filter($this->calls,static fn(HttpRequest $r):bool=>$r->method()!=='GET'));}
}

$validation=DiscordRestClient::validationCommandDefinitions();
$byName=array_column($validation,null,'name');
$same(true,$byName['radio-check']['nsfw'],'administrator validation command keeps its age restriction');
$same('0',$byName['radio-check']['default_member_permissions'],'administrator validation permissions stay restricted');
foreach(['radio-card','radio-status']as$name){$same(false,$byName[$name]['nsfw'],$name.' is available in an all-ages channel');}
$same(false,ParticipantCommand::definition()['nsfw'],'radio-attend is available in an all-ages channel');

$rows=[];
foreach([$byName['radio-card'],$byName['radio-status'],ParticipantCommand::definition()]as$i=>$definition){
    $definition['id']=(string)(900000000000007001+$i);$definition['default_member_permissions']='0';$definition['nsfw']=true;$rows[]=$definition;
}
$admin=$byName['radio-check']+['id'=>'900000000000007004'];
$unrelated=['type'=>1,'name'=>'unrelated-tool','description'=>'Unrelated tool','id'=>'900000000000007005','nsfw'=>true,'default_member_permissions'=>'8','options'=>[]];
$rows[]=$admin;$rows[]=$unrelated;
$make=static function(array $commands,array $overrides=[])use(&$same):array{
    $http=new ParticipantCommandAccessHttp($commands);
    $client=new ServerOperationsClient(new Database(':memory:'),$http,'fictional_command_access_token_000000',
        $overrides+['enabled'=>true,'commands_enabled'=>true,'participant_scope'=>'guild']);
    return [$client,$http];
};

[$disabled,$disabledHttp]=$make($rows,['commands_enabled'=>false]);
$same(false,$disabled->repairParticipantCommandMetadata()->ok,'metadata repair is explicitly gated');
$same(0,count($disabledHttp->calls),'disabled metadata repair performs no HTTP');
[$scoped,$scopedHttp]=$make($rows,['participant_scope'=>'allowlist']);
$same(false,$scoped->repairParticipantCommandMetadata()->ok,'metadata repair requires explicit public guild scope');
$same(0,count($scopedHttp->calls),'non-public scope performs no HTTP');

[$client,$http]=$make($rows);
$same(true,$client->repairParticipantCommandMetadata()->ok,'three existing participant access restrictions repair');
$same(3,count($http->writes()),'exactly the three public commands are patched');
$same([$admin,$unrelated],array_slice($http->commands,3),'administrator and unrelated commands are unchanged');
foreach(array_slice($http->commands,0,3)as$i=>$row){
    $expected=$rows[$i];$expected['nsfw']=false;$expected['default_member_permissions']=null;$same($expected,$row,'command body changes only its public access metadata');
}
foreach($http->writes()as$request){
    $same('PATCH',$request->method(),'repair never bulk replaces, creates or deletes commands');
    $same(['nsfw'=>false,'default_member_permissions'=>null],json_decode($request->body(),true,32,JSON_THROW_ON_ERROR),'repair writes only public access fields');
}
$before=count($http->writes());$same(true,$client->repairParticipantCommandMetadata()->ok,'repeated repair reconciles');
$same($before,count($http->writes()),'repeated repair makes no additional mutation');

$partialRows=$rows;$partialRows[0]['default_member_permissions']=null;
$partialRows[1]['nsfw']=false;$partialRows[2]['nsfw']=false;$partialRows[2]['default_member_permissions']=null;
[$partial,$partialHttp]=$make($partialRows);
$same(true,$partial->repairParticipantCommandMetadata()->ok,'mixed correct access fields reconcile');
$same(2,count($partialHttp->writes()),'already-correct command is not patched');
$same(['nsfw'=>false],json_decode($partialHttp->writes()[0]->body(),true,32,JSON_THROW_ON_ERROR),'age-only repair preserves correct permissions');
$same(['default_member_permissions'=>null],json_decode($partialHttp->writes()[1]->body(),true,32,JSON_THROW_ON_ERROR),'permission-only repair preserves correct age metadata');

$omittedRows=$rows;
for($i=0;$i<3;$i++){$omittedRows[$i]['default_member_permissions']=null;unset($omittedRows[$i]['nsfw']);}
[$omitted,$omittedHttp]=$make($omittedRows);
$same(true,$omitted->repairParticipantCommandMetadata()->ok,'omitted false age metadata follows the Discord default');
$same(0,count($omittedHttp->writes()),'omitted false age metadata needs no mutation');
$same(true,$omitted->registerParticipantCommands()->ok,'normal registration also accepts omitted false age metadata');
$same(0,count($omittedHttp->writes()),'normal registration does not recreate commands with omitted false age metadata');

// Validation of the complete set precedes any write, including a late conflict.
foreach(['description','permissions','options','missing','duplicate','invalid_id','invalid_nsfw']as$case){
    $bad=$rows;
    switch($case){
        case 'description':$bad[2]['description']='Different existing description';break;
        case 'permissions':$bad[2]['default_member_permissions']='8';break;
        case 'options':$bad[2]['options']=[['type'=>3,'name'=>'unexpected','description'=>'unexpected']];break;
        case 'missing':array_splice($bad,2,1);break;
        case 'duplicate':$bad[]=$bad[2];break;
        case 'invalid_id':$bad[2]['id']='invalid';break;
        case 'invalid_nsfw':$bad[2]['nsfw']='true';break;
    }
    [$reject,$rejectHttp]=$make($bad);
    $same(false,$reject->repairParticipantCommandMetadata()->ok,$case.' stops repair');
    $same(0,count($rejectHttp->writes()),$case.' aborts before every mutation');
}

[$failed,$failedHttp]=$make($rows);$failedHttp->failPatch=true;
$same(false,$failed->repairParticipantCommandMetadata()->ok,'failed metadata PATCH never reports readiness');
$same(1,count($failedHttp->writes()),'failed PATCH stops further command writes');
[$stale,$staleHttp]=$make($rows);$staleHttp->staleReadback=true;
$same(false,$stale->repairParticipantCommandMetadata()->ok,'stale final inventory never reports readiness');

// Normal registration still refuses to overwrite old metadata without the explicit repair action.
[$normal,$normalHttp]=$make($rows);
$same(false,$normal->registerParticipantCommands()->ok,'ordinary registration still rejects age metadata conflict');
$same(0,count($normalHttp->writes()),'ordinary registration cannot silently patch existing metadata');

[$missing,$missingHttp]=$make([$admin,$unrelated]);$missingHttp->allowCreate=true;
$same(true,$missing->registerParticipantCommands()->ok,'normal registration creates the missing three public commands');
$same(3,count($missingHttp->writes()),'exactly three missing participant commands are created');
$same([$admin,$unrelated],array_slice($missingHttp->commands,0,2),'missing-only creation preserves unrelated and administrator commands');
$same(['radio-card','radio-status','radio-attend'],array_column(array_slice($missingHttp->commands,2),'name'),'creation scope contains exactly the approved public commands');
foreach(array_slice($missingHttp->commands,2)as$row){
    $same(false,$row['nsfw'],'new public command has no age restriction');
    $same(null,$row['default_member_permissions'],'guild public permissions are explicit');
}
$same(true,$missing->registerParticipantCommands()->ok,'missing-only registration reconciles after creation');
$same(3,count($missingHttp->writes()),'successful registration never duplicates the three commands');

echo "Participant command access: {$checks} assertions passed (fictional HTTP only; no live calls).\n";
