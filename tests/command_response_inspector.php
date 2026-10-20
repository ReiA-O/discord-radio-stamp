<?php
declare(strict_types=1);
require_once __DIR__.'/artifact_directory.php';
require dirname(__DIR__).'/bootstrap.php';
use RadioStamp\Discord\CommandResponseInspector as Inspector;
use RadioStamp\Discord\DiscordRestClient as Client;
use RadioStamp\Discord\HttpAdapter;
use RadioStamp\Discord\HttpRequest;
use RadioStamp\Discord\HttpResponse;

$checks=0;
$assert=static function(bool $ok,string $case)use(&$checks):void{if(!$ok){throw new RuntimeException('Command inspection: '.$case);}$checks++;};
$defs=Client::validationCommandDefinitions();$card=$defs[1];$status=$defs[2];
$object=static fn(array $definition):array=>$definition+['id'=>'900000000000000001','application_id'=>Client::APPLICATION_ID,'guild_id'=>Client::GUILD_ID,'version'=>'900000000000000002'];
$inspect=static fn(array $actual,array $definition):array=>Inspector::inspect(201,json_encode($actual,JSON_THROW_ON_ERROR),$definition);
$actual=$object($card);$actual['secret_unknown']='SENSITIVE_FIXTURE_NEVER_OUTPUT';$actual['options'][0]['secret_unknown']='SENSITIVE_FIXTURE_NEVER_OUTPUT';
$good=$inspect($actual,$card);
$assert($good['verified']&&$good['json_parsed']&&$good['object']&&$good['id_valid']&&$good['definition_matches'],'correct scoped response verified');
$assert(!str_contains(json_encode($good,JSON_THROW_ON_ERROR),'SENSITIVE_FIXTURE')&&!str_contains(json_encode($good),Client::APPLICATION_ID),'inspection returns no raw values or ids');
$bad=Inspector::inspect(201,'{',$card);$assert(!$bad['json_parsed']&&$bad['json_error']==='syntax'&&!$bad['id_valid']&&$bad['fields']===[],'JSON failure is independent of id/definition');
foreach(['[]','null','"fixture"']as$raw){$r=Inspector::inspect(201,$raw,$card);$assert($r['json_parsed']&&!$r['object']&&!$r['verified'],'nonobject JSON distinguished');}
$value=$actual;unset($value['id']);$r=$inspect($value,$card);$assert(!$r['id_present']&&!$r['id_valid']&&$r['definition_matches'],'missing id distinguished from definition match');
foreach([123456789012345678,'bad-id',null]as$id){$value=$actual;$value['id']=$id;$r=$inspect($value,$card);$assert($r['id_present']&&!$r['id_valid']&&$r['definition_matches'],'invalid id distinguished');}
foreach(['application_id'=>'application_matches','guild_id'=>'guild_matches']as$key=>$flag){$value=$actual;$value[$key]='900000000000000099';$r=$inspect($value,$card);$assert($r['id_valid']&&$r['definition_matches']&&!$r[$flag]&&!$r['verified'],'scope mismatch independent');}
foreach(['name'=>'other','description'=>'other','type'=>2,'nsfw'=>false,'default_member_permissions'=>null]as$key=>$wrong){$value=$actual;$value[$key]=$wrong;$r=$inspect($value,$card);$assert(!$r['verified']&&$r['id_valid']&&$r['fields'][$key]==='different','individual top-level mismatch');}
foreach(['type'=>4,'required'=>true,'autocomplete'=>true,'min_length'=>6,'max_length'=>8]as$key=>$wrong){$value=$actual;$value['options'][0][$key]=$wrong;$r=$inspect($value,$card);$assert(!$r['verified']&&$r['fields']['options[0].'.$key]==='different','individual option mismatch');}
$value=$actual;$value['default_member_permissions']=0;$assert($inspect($value,$card)['fields']['default_member_permissions']==='invalid_type','permission integer zero is not string zero');
$value=$actual;$value['options'][0]['min_length']='7';$assert($inspect($value,$card)['fields']['options[0].min_length']==='invalid_type','length string is not numeric coercion');
$value=$actual;unset($value['options'][0]['required']);$value['options'][0]['autocomplete']=false;
$value['options'][0]['name_localizations']=null;$value['options'][0]['description_localizations']=null;
$value['options'][0]['name_localized']='SENSITIVE_FIXTURE_NEVER_OUTPUT';$value['options'][0]['description_localized']='SENSITIVE_FIXTURE_NEVER_OUTPUT';
$assert($inspect($value,$card)['verified'],'omitted false and null/response-only localization metadata accepted');
$value=$object($status);unset($value['type'],$value['options']);$assert($inspect($value,$status)['verified'],'omitted type and empty options use documented/structural defaults');
$false=$status;$false['nsfw']=false;$value=$object($false);unset($value['nsfw']);$assert($inspect($value,$false)['verified'],'missing nsfw equals false');
$value=$object($status);unset($value['nsfw']);$assert(!$inspect($value,$status)['verified'],'missing nsfw never equals requested true');
$value=$object($status);$value['options']=null;$assert($inspect($value,$status)['fields']['options']==='invalid_type','null options not treated as empty');
$value=$actual;$value['options'][0]['required']=null;$assert($inspect($value,$card)['fields']['options[0].required']==='invalid_type','null bool not treated as omitted false');
$value=$actual;$value['options'][0]['name_localizations']=['ja'=>'different'];$assert($inspect($value,$card)['fields']['options[0].name_localizations']==='different','real translations are preserved as differences');
$localized=$card;$localized['options'][0]['name_localizations']=['ja'=>'month'];$assert($inspect($object($card),$localized)['verified'],'same-as-base localization normalization follows official rule');
$value=$actual;$value['options'][]=$value['options'][0];$assert($inspect($value,$card)['fields']['options.count']==='different','extra option not ignored');
$choices=$card;$choices['options'][0]['choices']=[['name'=>'one','value'=>'one']];$value=$object($choices);$value['options'][0]['choices'][0]['value']='other';$r=$inspect($value,$choices);$assert($r['fields']['options[0].choices[0].value']==='different','choice field mismatch path is precise');
$value=$actual;$value['options'][0]['min_value']=0;$assert($inspect($value,$card)['fields']['options[0].min_value']==='unexpected','unexpected semantic constraints not ignored');
$tooMany=$card;$tooMany['options']=array_fill(0,25,$card['options'][0]);$r=$inspect($object($tooMany),$tooMany);$assert($r['truncated']&&!$r['verified']&&count($r['fields'])<=256,'diagnostic size bounded and truncated never verified');
$r=Inspector::inspect(201,str_repeat('SENSITIVE_FIXTURE_NEVER_OUTPUT',50000),$card);$assert($r['json_error']==='too_large'&&!$r['verified'],'oversize response classified without contents');
$r=Inspector::inspect(403,json_encode($object($card),JSON_THROW_ON_ERROR),$card);$assert(!$r['http_accepted']&&!$r['verified']&&$r['definition_matches'],'HTTP result is independent');

final class CommandInspectionFakeHttp implements HttpAdapter
{
    public array $requests=[];
    public function __construct(private array $responses){}
    public function exchange(HttpRequest $request):HttpResponse{$this->requests[]=$request;return array_shift($this->responses)??new HttpResponse(0,[],'','fixture_exhausted');}
}
$make=static fn(array $responses):CommandInspectionFakeHttp=>new CommandInspectionFakeHttp($responses);
$client=static fn(CommandInspectionFakeHttp $http,array $extra=[]):Client=>new Client($http,Client::APPLICATION_ID,'FICTIONAL_BOT_TOKEN_FOR_MOCK_ONLY',$extra+['enabled'=>true,'connection_ready'=>true,'command_registration_enabled'=>true]);
$normal=$object($card);unset($normal['options'][0]['required']);$normal['options'][0]['name_localizations']=null;
$http=$make([new HttpResponse(200,[],'[]'),new HttpResponse(201,[],json_encode($normal,JSON_THROW_ON_ERROR))]);
$r=$client($http)->upsertValidationCommands(['radio-card']);$assert($r->ok&&$r->code==='commands_ready'&&count($http->requests)===2,'registrar accepts normalized response');
foreach(['{',json_encode(array_diff_key($normal,['id'=>true]),JSON_THROW_ON_ERROR)]as$raw){
 $http=$make([new HttpResponse(200,[],'[]'),new HttpResponse(201,[],$raw)]);$r=$client($http)->upsertValidationCommands(['radio-card']);$check=$r->data['command_response_check']??[];
 $assert(!$r->ok&&$r->code==='command_response_invalid'&&isset($check['json_parsed'],$check['id_valid'])&&count($http->requests)===2,'registrar returns separate safe failure stages');
 $assert(!str_contains(json_encode($r,JSON_THROW_ON_ERROR),'FICTIONAL_'),'registrar failure does not expose credential');
}
$different=$normal;$different['options'][0]['min_length']=6;$different['secret']='SENSITIVE_FIXTURE_NEVER_OUTPUT';
$http=$make([new HttpResponse(200,[],'[]'),new HttpResponse(201,[],json_encode($different,JSON_THROW_ON_ERROR))]);$r=$client($http)->upsertValidationCommands(['radio-card']);
$assert(($r->data['command_response_check']['fields']['options[0].min_length']??null)==='different'&&!str_contains(json_encode($r),'SENSITIVE_FIXTURE'),'registrar identifies mismatch without response body');
$http=$make([new HttpResponse(200,[],json_encode([$normal],JSON_THROW_ON_ERROR))]);$r=$client($http)->upsertValidationCommands(['radio-card']);
$assert($r->ok&&count($http->requests)===1&&$http->requests[0]->method()==='GET','existing equivalent definition never re-created');
$different=$normal;$different['description']='changed';$http=$make([new HttpResponse(200,[],json_encode([$different],JSON_THROW_ON_ERROR))]);$r=$client($http)->upsertValidationCommands(['radio-card']);
$assert(!$r->ok&&$r->code==='command_name_conflict'&&count($http->requests)===1,'real existing difference blocks all writes');
$http=$make([]);$r=$client($http,['command_registration_enabled'=>false])->upsertValidationCommands(['radio-card']);$assert(!$r->ok&&count($http->requests)===0,'registration remains opt-in');
$readUrl='https://discord.com/api/v10/applications/'.Client::APPLICATION_ID.'/guilds/'.Client::GUILD_ID.'/commands?with_localizations=true';
$http=$make([new HttpResponse(200,[],json_encode([$normal],JSON_THROW_ON_ERROR))]);$r=$client($http,['command_registration_enabled'=>false])->inspectGuildCommands();$inventory=$r->data['command_inventory']??[];
$assert($r->ok&&$r->code==='command_inventory'&&count($http->requests)===1&&$http->requests[0]->method()==='GET'&&$http->requests[0]->url()===$readUrl,'independent full-localization inventory makes only fixed GET');
$assert($inventory['commands']['radio-card']['inspection']['verified']&&$inventory['commands']['radio-status']['count']===0&&$inventory['commands']['radio-attend']['count']===0,'inventory distinguishes found and missing without registration');
$assert(!str_contains(json_encode($r,JSON_THROW_ON_ERROR),'FICTIONAL_')&&!str_contains(json_encode($r),'SENSITIVE_FIXTURE'),'inventory returns no credentials or response-only fields');
foreach(['{}','{','null']as$raw){$http=$make([new HttpResponse(200,[],$raw)]);$r=$client($http)->inspectGuildCommands();$assert(!$r->ok&&$r->code==='command_inventory_invalid'&&count($http->requests)===1,'malformed or object inventory never means missing');}
$http=$make([new HttpResponse(200,[],'[]')]);$r=$client($http)->inspectGuildCommands();$assert($r->ok&&$r->data['command_inventory']['count']===0,'genuine empty list is distinct from invalid object');
$http=$make([new HttpResponse(200,[],json_encode([$normal,$normal],JSON_THROW_ON_ERROR))]);$r=$client($http)->inspectGuildCommands();$assert(!$r->ok&&$r->data['command_inventory']['commands']['radio-card']['count']===2,'duplicate names stop inventory reconciliation');
$wrongScope=$normal;$wrongScope['guild_id']='900000000000000099';$http=$make([new HttpResponse(200,[],json_encode([$wrongScope],JSON_THROW_ON_ERROR))]);$r=$client($http)->inspectGuildCommands();$assert(!$r->ok&&!$r->data['command_inventory']['scope_valid'],'wrong inventory scope stops before writing');
$http=$make([]);$r=$client($http,['enabled'=>false])->inspectGuildCommands();$assert(!$r->ok&&count($http->requests)===0,'read transport remains disabled unless explicitly enabled');
foreach([['POST',$readUrl],['GET',$readUrl.'&extra=1'],['GET',str_replace('with_localizations=true','with_localizations=false',$readUrl)],['GET',str_replace(Client::GUILD_ID,'900000000000000099',$readUrl)]]as[$verb,$url]){
 $blocked=false;try{new HttpRequest($verb,$url,[]);}catch(InvalidArgumentException){$blocked=true;}$assert($blocked,'only one exact GET query is allowed');
}
echo json_encode(['offline_only'=>true,'assertions'=>$checks,'live_http_calls'=>0,'credential_or_body_values_in_reports'=>false,'defaults_and_semantic_differences_tested'=>true,'registrar_fake_response_integration_tested'=>true],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),"\n";
