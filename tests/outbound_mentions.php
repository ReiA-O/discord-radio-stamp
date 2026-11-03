<?php
declare(strict_types=1);
require_once __DIR__.'/artifact_directory.php';
require dirname(__DIR__).'/bootstrap.php';

use RadioStamp\Core\{Database,StampPolicy};
use RadioStamp\Discord\{AchievementMessage,DiscordRestClient,Gateway,HttpAdapter,HttpRequest,HttpResponse,OpeningNoticeMessage,ReceiptMessage,ServerOperationsClient,TransportOutcome};
use RadioStamp\Http\{DiscordRestFollowupSink,Response};

$omChecks=0;
function omSame(mixed $expected,mixed $actual,string $why):void
{
    global $omChecks;++$omChecks;
    if($expected!==$actual){throw new RuntimeException('Outbound mentions: '.$why.' expected='.json_encode($expected).' actual='.json_encode($actual));}
}
function omCheck(bool $actual,string $why):void{omSame(true,$actual,$why);}
function omJson(mixed $payload):string{return json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);}

/** Recorder only: no socket, cURL, external service, or network fallback. */
final class OutboundMentionFixtureHttp implements HttpAdapter
{
    public array $requests=[];
    public function exchange(HttpRequest $request):HttpResponse
    {
        $this->requests[]=$request;$data=['id'=>'900000000000009999'];
        if(preg_match('~/channels/([0-9]+)/messages$~',$request->url(),$match)===1){$data['channel_id']=$match[1];}
        return new HttpResponse(200,[],omJson($data));
    }
}
function omRequest(string $body,string $method='POST',?string $path=null,?array $headers=null):HttpRequest
{
    return new HttpRequest($method,'https://discord.com/api/v10'.($path??'/channels/'.DiscordRestClient::VOICE_CHANNEL_ID.'/messages'),$headers??['Content-Type: application/json'],$body);
}
function omReject(callable $make,string $why,?OutboundMentionFixtureHttp $http=null):void
{
    $before=count($http?->requests??[]);$caught=null;
    try{$value=$make();if($http!==null&&$value instanceof HttpRequest){$http->exchange($value);}}
    catch(InvalidArgumentException $error){$caught=$error->getMessage();}
    omSame('discord_mentions_rejected',$caught,$why.' is rejected by the shared boundary');
    if($http!==null){omSame($before,count($http->requests),$why.' reaches no adapter exchange');}
}
function omMultipart(string $json,string $png,?string $boundary=null):array
{
    $boundary??='RadioStamp'.str_repeat('a',32);
    $body='--'.$boundary."\r\nContent-Disposition: form-data; name=\"payload_json\"\r\nContent-Type: application/json\r\n\r\n".$json
        ."\r\n--".$boundary."\r\nContent-Disposition: form-data; name=\"files[0]\"; filename=\"card.png\"\r\nContent-Type: image/png\r\n\r\n".$png."\r\n--".$boundary."--\r\n";
    return ['body'=>$body,'headers'=>['Content-Type: multipart/form-data; boundary='.$boundary],'boundary'=>$boundary];
}

$http=new OutboundMentionFixtureHttp();
$routes=[
    'guide'=>['POST','/channels/'.DiscordRestClient::TEXT_CHANNEL_ID.'/messages'],
    'voice'=>['POST','/channels/'.DiscordRestClient::VOICE_CHANNEL_ID.'/messages'],
    'records'=>['POST','/channels/'.DiscordRestClient::RECORDS_CHANNEL_ID.'/messages'],
    'notice'=>['POST','/channels/'.DiscordRestClient::OPENING_NOTICE_CHANNEL_ID.'/messages'],
    'callback'=>['PATCH','/webhooks/'.DiscordRestClient::APPLICATION_ID.'/FICTIONAL_OUTBOUND_CALLBACK_TOKEN/messages/@original'],
    'command'=>['POST','/applications/'.DiscordRestClient::APPLICATION_ID.'/guilds/'.DiscordRestClient::GUILD_ID.'/commands'],
];
$fields=[
    'content'=>static fn(string $value):array=>['content'=>$value],
    'embed_description'=>static fn(string $value):array=>['embeds'=>[['description'=>$value]]],
    'embed_title'=>static fn(string $value):array=>['embeds'=>[['title'=>$value]]],
    'embed_fields'=>static fn(string $value):array=>['embeds'=>[['fields'=>[['name'=>'safe','value'=>$value]]]]],
    'embed_author'=>static fn(string $value):array=>['embeds'=>[['author'=>['name'=>$value]]]],
    'embed_footer'=>static fn(string $value):array=>['embeds'=>[['footer'=>['text'=>$value]]]],
    'component_label'=>static fn(string $value):array=>['components'=>[['type'=>1,'components'=>[['type'=>2,'label'=>$value]]]]],
    'component_custom_id'=>static fn(string $value):array=>['components'=>[['type'=>1,'components'=>[['type'=>2,'custom_id'=>$value]]]]],
    'component_placeholder'=>static fn(string $value):array=>['components'=>[['type'=>1,'components'=>[['type'=>3,'placeholder'=>$value]]]]],
    'component_option'=>static fn(string $value):array=>['components'=>[['type'=>1,'components'=>[['type'=>3,'options'=>[['label'=>'safe','description'=>$value]]]]]]],
    'username'=>static fn(string $value):array=>['username'=>$value],
    'attachment_description'=>static fn(string $value):array=>['attachments'=>[['id'=>0,'description'=>$value]]],
    'attachment_filename'=>static fn(string $value):array=>['attachments'=>[['id'=>0,'filename'=>$value]]],
    'unknown_nested'=>static fn(string $value):array=>['future'=>[['nested'=>['text'=>$value]]]],
    'unknown_key'=>static fn(string $value):array=>[$value=>'safe'],
];

// Every lexical JSON string is inspected, including keys and Unicode escapes.
foreach(['@everyone','@here']as$mention){
    foreach($fields as$field=>$payload){
        $json=omJson($payload('before '.$mention.' after'));
        $encodings=['literal'=>$json,'escaped_at'=>str_replace('@','\\u0040',$json),'escaped_word'=>str_replace($mention,$mention==='@everyone'?'@every\\u006fne':'@h\\u0065re',$json)];
        foreach($encodings as$encoding=>$body){
            // The content field crosses every write route; the field matrix uses the ordinary VC route.
            $targets=$field==='content'?$routes:['voice'=>$routes['voice']];
            foreach($targets as$route=>[$method,$path]){omReject(fn():HttpRequest=>omRequest($body,$method,$path),$route.' '.$field.' '.$mention.' '.$encoding,$http);}
        }
    }
}
foreach([
    '{"content":"@everyone","content":"safe"}',
    '{"content":"\\u0040everyone","content":"safe"}',
    '{"content":"@here","content":"safe"}',
    '{"content":"\\u0040here","content":"safe"}',
    '{"nested":{"value":"@everyone"},"nested":{"value":"safe"}}',
    '{"content":"\\u0040\\u0065\\u0076\\u0065\\u0072\\u0079\\u006f\\u006e\\u0065"}',
]as$body){omReject(fn():HttpRequest=>omRequest($body),'overwritten or fully escaped prohibited lexical string',$http);}

// Permission declarations are validated recursively even when there is no mention in content.
$user='800000000000001021';$role='900000000000000123';$many=[];for($i=0;$i<101;$i++){$many[]=(string)(900000000000000000+$i);}
$invalidMentions=[
    'everyone_parse'=>['parse'=>['everyone']],
    'duplicate_parse'=>['parse'=>['users','users']],
    'unknown_parse'=>['parse'=>['all']],
    'scalar_parse'=>['parse'=>'users'],
    'null_parse'=>['parse'=>null],
    'object_parse'=>['parse'=>(object)['0'=>'users']],
    'numeric_parse'=>['parse'=>[1]],
    'invalid_user'=>['users'=>['invalid']],
    'numeric_user'=>['users'=>[800000000000001021]],
    'duplicate_users'=>['users'=>[$user,$user]],
    'too_many_users'=>['users'=>$many],
    'invalid_role'=>['roles'=>['0']],
    'duplicate_roles'=>['roles'=>[$role,$role]],
    'too_many_roles'=>['roles'=>$many],
    'scalar_replied_user'=>['replied_user'=>'false'],
    'unknown_key'=>['parse'=>[],'everything'=>true],
];
foreach($invalidMentions as$case=>$mentions){
    foreach(['root'=>['content'=>'safe','allowed_mentions'=>$mentions],'nested'=>['content'=>'safe','future'=>[['allowed_mentions'=>$mentions]]]]as$location=>$payload){
        omReject(fn():HttpRequest=>omRequest(omJson($payload)),$location.' invalid allowed_mentions '.$case,$http);
    }
}

// Existing explicit user and role mentions remain valid; they do not grant a broadcast capability.
foreach([
    ['content'=>'<@'.$user.'> <@&'.$role.'>','allowed_mentions'=>['parse'=>[],'users'=>[$user],'roles'=>[$role],'replied_user'=>false]],
    ['content'=>'safe','allowed_mentions'=>['parse'=>['users','roles'],'users'=>[],'roles'=>[],'replied_user'=>true]],
    ['content'=>'safe','allowed_mentions'=>['parse'=>[],'users'=>array_slice($many,0,100),'roles'=>array_slice($many,0,100),'replied_user'=>false]],
    AchievementMessage::build($user,40,40),
    ReceiptMessage::build('2026-10-22_0700',1792620600),
]as$payload){$body=omJson($payload);$request=omRequest($body);$http->exchange($request);omSame($body,$request->body(),'safe explicit mentions/receipt payload remains byte-identical');}

// All five fixed notice bodies are safe at the shared boundary; broadcast mutations are never safe.
foreach(['midnight'=>'00:00','dawn'=>'04:00','morning'=>'07:00','midday'=>'12:30','night'=>'22:00']as$slot=>$time){
    $at=(new DateTimeImmutable('2026-10-22 '.$time,new DateTimeZone('Asia/Tokyo')))->getTimestamp();$id='2026-10-22_'.str_replace(':','',$time);
    $snapshot=OpeningNoticeMessage::snapshot(['id'=>$id,'scheduled_at'=>$at,'slot_id'=>$slot,'stamp'=>StampPolicy::SLOT_STAMPS[$slot]]);
    $payload=$snapshot['payload'];$payload['nonce']=substr(hash('sha256',OpeningNoticeMessage::key($id)),0,25);$payload['enforce_nonce']=true;
    omSame(Gateway::ALLOWED_MENTIONS,$payload['allowed_mentions'],$slot.' fixed notice disables every mention class');
    $body=omJson($payload);$request=omRequest($body,'POST','/channels/'.DiscordRestClient::OPENING_NOTICE_CHANNEL_ID.'/messages');$http->exchange($request);omSame($body,$request->body(),$slot.' safe notice remains byte-identical at shared boundary');
    foreach(['here','escaped_here','broadcast_parse']as$change){
        $bad=$payload;if($change==='broadcast_parse'){$bad['allowed_mentions']['parse']=['everyone'];}else{$bad['content'].=' @here';}
        $body=omJson($bad);if($change==='escaped_here'){$body=str_replace('@','\\u0040',$body);}
        omReject(fn():HttpRequest=>omRequest($body,'POST','/channels/'.DiscordRestClient::OPENING_NOTICE_CHANNEL_ID.'/messages'),$slot.' notice mutation '.$change,$http);
    }
}

// Only the existing card multipart shape is accepted, and image bytes are never interpreted as text.
$png="\x89PNG\r\n\x1a\nFICTIONAL_BINARY\x00 @everyone @here \\u0040everyone";
$card=['content'=>'safe card','components'=>[],'allowed_mentions'=>Gateway::ALLOWED_MENTIONS,'attachments'=>[['id'=>0,'filename'=>'card.png','description'=>'ラジオ体操スタンプカード']]];
$multipart=omMultipart(omJson($card),$png);$request=omRequest($multipart['body'],'PATCH',$routes['callback'][1],$multipart['headers']);$http->exchange($request);
omSame($multipart['body'],$request->body(),'safe card multipart preserves binary bytes and body exactly');
foreach(['@everyone','@here']as$mention){
    foreach(['content','embed_description','component_label','username','attachment_description']as$field){
        $payload=$card;$injected=$fields[$field]('before '.$mention.' after');$payload=array_replace($payload,$injected);
        foreach(['literal'=>omJson($payload),'escaped'=>str_replace('@','\\u0040',omJson($payload))]as$encoding=>$json){
            $bad=omMultipart($json,$png);omReject(fn():HttpRequest=>omRequest($bad['body'],'PATCH',$routes['callback'][1],$bad['headers']),'multipart '.$field.' '.$mention.' '.$encoding,$http);
        }
    }
}
$boundary=$multipart['boundary'];$end="\r\n--".$boundary."--\r\n";
$extra="\r\n--".$boundary."\r\nContent-Disposition: form-data; name=\"unknown\"\r\n\r\nsafe";
$secondPayload="\r\n--".$boundary."\r\nContent-Disposition: form-data; name=\"payload_json\"\r\nContent-Type: application/json\r\n\r\n{}";
$malformed=[
    'unknown_part'=>str_replace($end,$extra.$end,$multipart['body']),
    'duplicate_payload'=>str_replace($end,$secondPayload.$end,$multipart['body']),
    'wrong_filename'=>str_replace('filename="card.png"','filename="other.png"',$multipart['body']),
    'wrong_file_name'=>str_replace('name="files[0]"','name="files[1]"',$multipart['body']),
    'wrong_file_type'=>str_replace('Content-Type: image/png','Content-Type: text/plain',$multipart['body']),
    'missing_end'=>substr($multipart['body'],0,-strlen($end)),
    'invalid_payload_json'=>omMultipart('{not JSON}',$png)['body'],
];
foreach($malformed as$case=>$body){omReject(fn():HttpRequest=>omRequest($body,'PATCH',$routes['callback'][1],$multipart['headers']),'malformed multipart '.$case,$http);}

// Public original-response edits share the same guard for JSON and PNG multipart.
$followHttp=new OutboundMentionFixtureHttp();$client=new DiscordRestClient($followHttp,DiscordRestClient::APPLICATION_ID,'FICTIONAL_OUTBOUND_BOT_TOKEN_000000',['enabled'=>true]);$callback='FICTIONAL_OUTBOUND_CALLBACK_TOKEN';$interaction='900000000000000301';
foreach(['@everyone','@here']as$mention){foreach([null,$png]as$image){$before=count($followHttp->requests);$result=$client->editOriginal($interaction,$callback,['content'=>'before '.$mention.' after','components'=>[]],$image);omSame(false,$result->ok,'public original edit refuses '.$mention.($image===null?' JSON':' multipart'));omSame($before,count($followHttp->requests),'rejected original edit reaches no fake transport');}}
$sink=new DiscordRestFollowupSink($client);
foreach(['attended','bot_check_passed','validation_ready','receipt_opened']as$code){omSame(true,$sink->send($interaction,$callback,['ok'=>true,'code'=>$code],null),'fixed participant/admin follow-up remains valid: '.$code);}
omSame(true,$sink->send($interaction,$callback,['ok'=>true,'code'=>'card_ready'],$png),'existing PNG card follow-up remains valid');
$last=$followHttp->requests[array_key_last($followHttp->requests)];omCheck(str_contains($last->body(),$png),'public multipart preserves all PNG bytes');
foreach($followHttp->requests as$request){omSame('PATCH',$request->method(),'follow-up continues editing original response');omSame('/api/v10'.$routes['callback'][1],parse_url($request->url(),PHP_URL_PATH),'follow-up keeps original callback route');}

$db=new Database(':memory:');$operationHttp=new OutboundMentionFixtureHttp();$operations=new ServerOperationsClient($db,$operationHttp,'FICTIONAL_OUTBOUND_OPERATIONS_TOKEN_000000',['enabled'=>true,'posts_enabled'=>true]);
foreach(['@everyone','@here']as$mention){$result=$operations->postText(DiscordRestClient::VOICE_CHANNEL_ID,$mention,'outbound-blocked:'.substr($mention,1));omSame(false,$result->ok,'ordinary operation blocks '.$mention);omSame(TransportOutcome::NotStarted,$result->outcome,'ordinary blocked mention is known not-started');omSame(0,count($operationHttp->requests),'ordinary blocked mention reaches no adapter');}
omSame(true,$operations->postReceipt('2026-10-22_0700',1792620600,'outbound-safe-receipt')->ok,'existing operations receipt still sends');
omSame(1,count($operationHttp->requests),'safe receipt is the only operation sent');

// Direct interaction callbacks cannot bypass the shared text rules via response types4/5/7.
foreach([4,5,7]as$type){
    foreach(['@everyone','@here']as$mention){foreach(['content','embed_author','component_label','unknown_key']as$field){$data=$fields[$field]($mention);$data['flags']=64;omReject(fn():Response=>new Response(200,['type'=>$type,'data'=>$data]),'callback type'.$type.' '.$field.' '.$mention);}}
    $body=['type'=>$type,'data'=>['content'=>'<@'.$user.'> <@&'.$role.'>','flags'=>64,'allowed_mentions'=>['parse'=>[],'users'=>[$user],'roles'=>[$role],'replied_user'=>false]]];$response=new Response(200,$body);omSame($body,$response->body,'safe callback type'.$type.' explicit mentions remain unchanged');
    omReject(fn():Response=>new Response(200,['type'=>$type,'data'=>['content'=>'safe','allowed_mentions'=>['parse'=>['everyone']]]]),'callback type'.$type.' cannot grant broadcast parse');
}
omReject(fn():Response=>new Response(400,['error'=>'@everyone']),'non-callback JSON response cannot leak prohibited literal');
omSame(['type'=>1],(new Response(200,['type'=>1]))->body,'PING response remains unchanged');
foreach(['@everyone','@here']as$mention){
    $embed=(object)['description'=>'safe at construction'];$response=new Response(200,['type'=>4,'data'=>['embeds'=>[$embed]]]);$embed->description=$mention;
    ob_start();
    try{omReject(static fn()=>$response->emit(),'emit rechecks nested object changed to '.$mention);$bytes=ob_get_contents();}
    finally{ob_end_clean();}
    omSame('',$bytes,'late nested mutation emits zero bytes for '.$mention);
}

echo 'Outbound mentions: '.$omChecks.' assertions passed (fictional HTTP only; shared requests, callbacks, and multipart; no network).'."\n";
