<?php
declare(strict_types=1);
namespace RadioStamp\Discord;

use PDO;
use RadioStamp\Core\{Database,TitlePolicy};
use RadioStamp\Operations\SafeDiagnostics;

/** Fixed-scope REST adapter for the separate operations runtime; every mutation defaults OFF. */
final class ServerOperationsClient
{
    public const DESTINATION_ID='800000000000001002';
    private array $options;
    private RestStateStore $limits;
    private bool $authenticationFailed=false;
    private bool $identityConfirmed=false;
    private \Closure $now;
    private \Closure $monotonic;
    private \Closure $requestGuard;
    private ?SafeDiagnostics $diagnostics=null;

    public function __construct(private Database $db,private HttpAdapter $http,#[\SensitiveParameter] private string $botToken,array $options=[])
    {
        $this->options=$options+['enabled'=>false,'posts_enabled'=>false,'roles_enabled'=>false,'room_permissions_enabled'=>false,'commands_enabled'=>false,'member_actions_enabled'=>false,'accept_voice_race_risk'=>false,'role_ids'=>[],'room_baseline'=>null];
        $this->diagnostics=($options['diagnostics']??null) instanceof SafeDiagnostics?$options['diagnostics']:null;
        foreach(['enabled','posts_enabled','roles_enabled','room_permissions_enabled','commands_enabled','member_actions_enabled','accept_voice_race_risk']as$key){if(!is_bool($this->options[$key])){throw new \InvalidArgumentException('operations_flags_invalid');}}
        if(preg_match('/\A[A-Za-z0-9._-]{20,256}\z/D',$botToken)!==1){throw new \InvalidArgumentException('operations_configuration_invalid');}
        if(!is_array($this->options['role_ids'])||count($this->options['role_ids'])>10){throw new \InvalidArgumentException('operations_roles_invalid');}
        foreach($this->options['role_ids']as$threshold=>$role){if(!isset(TitlePolicy::TITLES[$threshold])||!self::id($role)){throw new \InvalidArgumentException('operations_roles_invalid');}}
        if(count(array_unique($this->options['role_ids']))!==count($this->options['role_ids'])){throw new \InvalidArgumentException('operations_roles_invalid');}
        $this->now=isset($options['now'])?\Closure::fromCallable($options['now']):static fn():float=>microtime(true);
        $this->monotonic=isset($options['monotonic'])?\Closure::fromCallable($options['monotonic']):static fn():float=>hrtime(true)/1e9;
        $this->requestGuard=isset($options['request_guard'])?\Closure::fromCallable($options['request_guard']):static fn():bool=>true;
        $this->limits=new RestStateStore($db->pdo());
        $db->pdo()->exec("CREATE TABLE IF NOT EXISTS operation_receipts(operation_key TEXT PRIMARY KEY,kind TEXT NOT NULL,target TEXT NOT NULL,payload_sha256 TEXT NOT NULL,status TEXT NOT NULL CHECK(status IN ('sending','sent','uncertain')),message_id TEXT,created_at INTEGER NOT NULL,finished_at INTEGER,claim_owner TEXT)");
        if(!in_array('claim_owner',$db->pdo()->query('PRAGMA table_info(operation_receipts)')->fetchAll(PDO::FETCH_COLUMN,1),true)){
            try{$db->pdo()->exec('ALTER TABLE operation_receipts ADD COLUMN claim_owner TEXT');}
            catch(\PDOException $error){if(!in_array('claim_owner',$db->pdo()->query('PRAGMA table_info(operation_receipts)')->fetchAll(PDO::FETCH_COLUMN,1),true)){throw $error;}}
        }
        $db->pdo()->exec('CREATE TABLE IF NOT EXISTS operation_role_sync(user_id TEXT PRIMARY KEY,highest_threshold INTEGER NOT NULL DEFAULT 0,lease_owner TEXT,lease_until REAL NOT NULL DEFAULT 0)');
    }
    public function __debugInfo():array{return ['enabled'=>$this->options['enabled'],'credentials'=>'[redacted]','scope'=>'explicit_operations_config'];}
    private static function id(mixed $id):bool{return is_string($id)&&preg_match('/\A[1-9][0-9]{16,19}\z/D',$id)===1;}

    public function identity():RestResult
    {
        if($this->authenticationFailed){return new RestResult(false,'unauthorized',401);}
        if($this->identityConfirmed){return new RestResult(true,'bot_identity_cached',200);}
        $result=$this->request('GET','/users/@me','identity');
        $this->identityConfirmed=$result->ok&&($result->data['id']??null)===DiscordRestClient::APPLICATION_ID&&($result->data['bot']??null)===true;
        return new RestResult($this->identityConfirmed,'bot_identity_checked',$result->status);
    }
    public function gatewayInfo():RestResult
    {
        $result=$this->request('GET','/gateway/bot','gateway:info');
        if(!$result->ok){return $result;}
        $data=$result->data;$limit=$data['session_start_limit']??null;
        if(!in_array($data['url']??null,['wss://gateway.discord.gg','wss://gateway.discord.gg/'],true)||($data['shards']??null)!==1||!is_array($limit)
            ||!is_int($limit['remaining']??null)||$limit['remaining']<10||!is_int($limit['max_concurrency']??null)||$limit['max_concurrency']<1){return new RestResult(false,'gateway_budget_unavailable');}
        return new RestResult(true,'gateway_ready',200,['url'=>$data['url'],'remaining'=>$limit['remaining']]);
    }
    public function setRoomConnect(bool $open):RestResult
    {
        if(!$this->options['room_permissions_enabled']){return new RestResult(false,'room_permissions_disabled');}
        $baseline=$this->options['room_baseline'];
        if(!is_array($baseline)||!self::bits($baseline['allow']??null)||!self::bits($baseline['deny']??null)){return new RestResult(false,'room_baseline_unavailable');}
        $path='/channels/'.DiscordRestClient::VOICE_CHANNEL_ID;$bit=1<<20;
        $read=function()use($path):?array{
            $response=$this->request('GET',$path,'room:read');
            if(!$response->ok||($response->data['id']??null)!==DiscordRestClient::VOICE_CHANNEL_ID||!is_array($response->data['permission_overwrites']??null)){return null;}
            foreach($response->data['permission_overwrites']as$row){if(($row['id']??null)===DiscordRestClient::GUILD_ID){return ($row['type']??null)===0&&self::bits($row['allow']??null)&&self::bits($row['deny']??null)?$row:null;}}
            return ['allow'=>'0','deny'=>'0'];
        };
        $row=$read();if($row===null){return new RestResult(false,'room_unknown');}
        if(((int)$row['allow']&~$bit)!==((int)$baseline['allow']&~$bit)||((int)$row['deny']&~$bit)!==((int)$baseline['deny']&~$bit)){return new RestResult(false,'room_baseline_changed');}
        $allow=$open?((int)$row['allow']|$bit):((int)$row['allow']&~$bit);
        $deny=$open?((int)$row['deny']&~$bit):((int)$row['deny']|$bit);
        if((int)$row['allow']!==$allow||(int)$row['deny']!==$deny){
            $body=json_encode(['type'=>0,'allow'=>(string)$allow,'deny'=>(string)$deny],JSON_THROW_ON_ERROR);
            $changed=$this->request('PUT',$path.'/permissions/'.DiscordRestClient::GUILD_ID,'room:write',$body,null,new RoomMutationScope());
            if(!$changed->ok||$changed->status!==204){return new RestResult(false,'room_change_unconfirmed');}
        }
        $after=$read();$ok=$after!==null&&(int)$after['allow']===$allow&&(int)$after['deny']===$deny;
        return new RestResult($ok,$ok?'room_verified':'room_change_unconfirmed');
    }
    private static function bits(mixed $value):bool
    {
        return is_string($value)&&preg_match('/\A(?:0|[1-9][0-9]{0,18})\z/D',$value)===1
            &&(strlen($value)<strlen((string)PHP_INT_MAX)||(strlen($value)===strlen((string)PHP_INT_MAX)&&strcmp($value,(string)PHP_INT_MAX)<=0));
    }
    public function registerParticipantCommands():RestResult
    {
        if(!$this->options['commands_enabled']){return new RestResult(false,'command_registration_disabled');}
        $definitions=array_values(array_filter(DiscordRestClient::validationCommandDefinitions(),static fn(array $d):bool=>in_array($d['name'],['radio-card','radio-status'],true)));
        $definitions[]=ParticipantCommand::definition()+['default_member_permissions'=>'0'];
        if (($this->options['participant_scope']??'allowlist')==='guild') {
            foreach ($definitions as &$definition) { $definition['default_member_permissions']=null; } unset($definition);
        }
        $path='/applications/'.DiscordRestClient::APPLICATION_ID.'/guilds/'.DiscordRestClient::GUILD_ID.'/commands';
        $read=$this->request('GET',$path,'commands:read');if(!$read->ok||!array_is_list($read->data)){return new RestResult(false,'commands_unavailable');}
        $missing=[];
        foreach($definitions as$definition){
            $matches=array_values(array_filter($read->data,static fn($actual):bool=>is_array($actual)&&($actual['name']??null)===$definition['name']));
            if(count($matches)>1){return new RestResult(false,'command_conflict');}
            if($matches){foreach($definition as$key=>$value){
                $actual=$matches[0][$key]??($key==='options'?[]:null);
                if(self::commandValue($actual)!==self::commandValue($value)){return new RestResult(false,'command_conflict');}
            }}
            else{$missing[]=$definition;}
        }
        foreach($missing as$definition){
            $json=json_encode($definition,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);$key='command:'.$definition['name'];
            $claim=$this->claim($key,'command',DiscordRestClient::GUILD_ID,hash('sha256',$json));
            if($claim instanceof RestResult){return $claim;}
            if($claim['status']!=='claimed'){return new RestResult(false,'command_result_requires_reconciliation');}
            $created=$this->request('POST',$path,'commands:write',$json);
            $ok=$created->ok&&self::id($created->data['id']??null)&&($created->data['name']??null)===$definition['name'];
            $outcome=$ok?TransportOutcome::Succeeded:($created->outcome->retryable()?$created->outcome:TransportOutcome::Uncertain);
            $this->finish($key,$claim['owner'],$outcome,$ok?$created->data['id']:null);if(!$ok){return new RestResult(false,'command_result_uncertain',outcome:$outcome);}
        }
        return new RestResult(true,'participant_commands_ready',200,['definitions'=>3,'created'=>count($missing)]);
    }
    /** Object key order and omitted false option defaults have no command meaning. List order does. */
    private static function commandValue(mixed $value):mixed
    {
        if(!is_array($value)){return $value;}
        if(array_is_list($value)){return array_map(self::commandValue(...),$value);}
        foreach(['required','autocomplete']as$key){if(($value[$key]??null)===false){unset($value[$key]);}}
        foreach($value as$key=>$child){$value[$key]=self::commandValue($child);}ksort($value);return $value;
    }

    public function readVoice(string $user):RestResult
    {
        if(!self::id($user)){return new RestResult(false,'scope_rejected');}
        $result=$this->request('GET','/guilds/'.DiscordRestClient::GUILD_ID.'/voice-states/'.$user,'voice:read');
        if(!$result->ok){return $result;}
        if(($result->data['user_id']??null)!==$user || (isset($result->data['guild_id'])&&$result->data['guild_id']!==DiscordRestClient::GUILD_ID)
            || !array_key_exists('channel_id',$result->data) || ($result->data['channel_id']!==null&&!self::id($result->data['channel_id']))){return new RestResult(false,'voice_unknown');}
        return new RestResult(true,'voice_observed',200,['user_id'=>$user,'channel_id'=>$result->data['channel_id'],'observed_at'=>($this->now)()]);
    }
    public function postReceipt(string $session,int $closesAt,string $key,?\Closure $beforeTransport=null):RestResult
    {
        return $this->post(DiscordRestClient::VOICE_CHANNEL_ID,ReceiptMessage::build($session,$closesAt),'receipt:'.$session,'receipt',$beforeTransport);
    }
    public function postText(string $channel,string $content,string $key):RestResult
    {
        if(!in_array($channel,[DiscordRestClient::VOICE_CHANNEL_ID,DiscordRestClient::TEXT_CHANNEL_ID],true)||strlen($content)>2000||$content===''){return new RestResult(false,'scope_rejected',outcome:TransportOutcome::NotStarted);}
        return $this->post($channel,['content'=>$content,'components'=>[],'allowed_mentions'=>Gateway::ALLOWED_MENTIONS,'tts'=>false],$key,'ordinary');
    }
    public function postAchievement(string $user,int $threshold,int $total,string $key):RestResult
    {
        try{$q=$this->db->pdo()->prepare('SELECT highest_threshold FROM operation_role_sync WHERE user_id=?');$q->execute([$user]);$confirmedThreshold=(int)$q->fetchColumn();$q->closeCursor();}
        catch(\Throwable $error){return $this->claimUnavailable($error);}
        if(!isset($this->options['role_ids'][$threshold])||$confirmedThreshold!==$threshold){return new RestResult(false,'title_grant_unconfirmed',outcome:TransportOutcome::NotStarted);}
        return $this->post(DiscordRestClient::RECORDS_CHANNEL_ID,AchievementMessage::build($user,$threshold,$total),'achievement:'.$user.':'.$threshold,'achievement');
    }
    public function postDayAchievement(string $user,int $milestone,int $totalDays,?string $ownerUserId,string $key):RestResult
    {
        try{
            $payload=AchievementMessage::buildDay($user,$milestone,$totalDays,$ownerUserId);
            if($milestone===365&&($this->options['owner_user_id']??null)!==$ownerUserId){return new RestResult(false,'owner_unavailable',outcome:TransportOutcome::NotStarted);}
            $query=$this->db->pdo()->prepare("SELECT total_days,owner_user_id FROM day_achievement_notices WHERE user_id=? AND milestone=? AND status='sending'");$query->execute([$user,$milestone]);$notice=$query->fetch();$query->closeCursor();
            if($notice===false||(int)$notice['total_days']!==$totalDays||$notice['owner_user_id']!==$ownerUserId){return new RestResult(false,'day_grant_unconfirmed',outcome:TransportOutcome::NotStarted);}
        }catch(\InvalidArgumentException){return new RestResult(false,'scope_rejected',outcome:TransportOutcome::NotStarted);}
        catch(\Throwable $error){return $this->claimUnavailable($error);}
        return $this->post(DiscordRestClient::RECORDS_CHANNEL_ID,$payload,'day-achievement:'.$user.':'.$milestone,'day_achievement');
    }
    private function post(string $channel,array $payload,string $key,string $kind,?\Closure $beforeTransport=null):RestResult
    {
        if(!$this->options['enabled']||!$this->options['posts_enabled']){return new RestResult(false,'operations_posts_disabled',outcome:TransportOutcome::NotStarted);}
        $payload['nonce']=substr(hash('sha256',$key),0,25);$payload['enforce_nonce']=true;
        $json=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $claim=$this->claim($key,$kind,$channel,hash('sha256',$json));
        if($claim instanceof RestResult){return $claim;}
        if($claim['status']!=='claimed'){return new RestResult($claim['status']==='sent',$claim['status']==='sent'?'already_sent':$claim['status'],0,['message_id'=>$claim['message_id']??null],outcome:$claim['status']==='sent'?TransportOutcome::Succeeded:TransportOutcome::Uncertain);}
        $result=$this->request('POST','/channels/'.$channel.'/messages','messages:'.$channel,$json,null,null,$beforeTransport);
        $message=$result->ok&&self::id($result->data['id']??null)&&($result->data['channel_id']??null)===$channel?$result->data['id']:null;
        $outcome=$message!==null?TransportOutcome::Succeeded:($result->outcome->retryable()?$result->outcome:TransportOutcome::Uncertain);
        if(!$this->finish($key,$claim['owner'],$outcome,$message)){return new RestResult(false,'operation_claim_lost');}
        $code=match($outcome){TransportOutcome::Succeeded=>'message_confirmed',TransportOutcome::NotStarted=>'delivery_not_started',TransportOutcome::Rejected=>'delivery_rejected',default=>'delivery_uncertain'};
        return new RestResult($message!==null,$code,$result->status,['message_id'=>$message],$result->retryAfter,$result->syntheticStatus,$outcome);
    }

    /** Idempotent per-role endpoints preserve unrelated member roles. Never PATCH the whole role list. */
    public function grantTitle(string $user,int $threshold):RestResult
    {
        if(!$this->options['enabled']||!$this->options['roles_enabled']){return new RestResult(false,'operations_roles_disabled');}
        $role=$this->options['role_ids'][$threshold]??null;
        if(!self::id($user)||!is_string($role)||$user===DiscordRestClient::APPLICATION_ID){return new RestResult(false,'scope_rejected');}
        try{$owner=bin2hex(random_bytes(16));$claim=$this->db->transaction(function(PDO $pdo)use($user,$threshold,$owner):string{
            $pdo->prepare('INSERT OR IGNORE INTO operation_role_sync(user_id) VALUES(?)')->execute([$user]);
            $q=$pdo->prepare('SELECT * FROM operation_role_sync WHERE user_id=?');$q->execute([$user]);$row=$q->fetch();
            if((int)$row['highest_threshold']>$threshold){return 'role_superseded';}
            if((float)$row['lease_until']>($this->now)()){return 'role_sync_busy';}
            $pdo->prepare('UPDATE operation_role_sync SET lease_owner=?,lease_until=? WHERE user_id=?')->execute([$owner,($this->now)()+120,$user]);return 'claimed';
        });}catch(\Throwable $error){return $this->claimUnavailable($error);}
        if($claim!=='claimed'){return new RestResult(false,$claim);}
        $fenced=function(string $method,string $path,string $route,string $body='',?RoleMutationScope $scope=null)use($user,$owner):RestResult{
            $q=$this->db->pdo()->prepare('SELECT 1 FROM operation_role_sync WHERE user_id=? AND lease_owner=? AND lease_until>?');$q->execute([$user,$owner,($this->now)()]);
            return $q->fetchColumn()!==false?$this->request($method,$path,$route,$body,$scope):new RestResult(false,'role_lease_lost');
        };
        try{
        $scope=new RoleMutationScope(array_values($this->options['role_ids']));
        $path='/guilds/'.DiscordRestClient::GUILD_ID.'/members/'.$user;
        $before=$fenced('GET',$path,'member:read');
        if(!$this->validMember($before,$user)){return new RestResult(false,'member_unverified');}
        $added=$fenced('PUT',$path.'/roles/'.$role,'role:write','',$scope);
        if(!$added->ok||$added->status!==204){return new RestResult(false,'title_grant_unconfirmed');}
        foreach($this->options['role_ids']as$other){
            if($other!==$role&&in_array($other,$before->data['roles'],true)){
                $removed=$fenced('DELETE',$path.'/roles/'.$other,'role:write','',$scope);
                if(!$removed->ok||$removed->status!==204){return new RestResult(false,'title_cleanup_unconfirmed');}
            }
        }
        $after=$fenced('GET',$path,'member:read');
        if(!$this->validMember($after,$user)||!in_array($role,$after->data['roles'],true)
            ||array_values(array_intersect($after->data['roles'],array_values($this->options['role_ids'])))!==[$role]){return new RestResult(false,'title_verification_failed');}
        $saved=$this->db->transaction(function(PDO $pdo)use($user,$owner,$threshold):bool{
            $q=$pdo->prepare('UPDATE operation_role_sync SET highest_threshold=MAX(highest_threshold,?) WHERE user_id=? AND lease_owner=? AND lease_until>?');$q->execute([$threshold,$user,$owner,($this->now)()]);return $q->rowCount()===1;
        });
        return new RestResult($saved,$saved?'title_verified':'role_lease_lost',200,['user_id'=>$user,'threshold'=>$threshold]);
        }finally{$this->db->transaction(fn(PDO $pdo)=>$pdo->prepare('UPDATE operation_role_sync SET lease_owner=NULL,lease_until=0 WHERE user_id=? AND lease_owner=?')->execute([$user,$owner]));}
    }
    private function validMember(RestResult $result,string $user):bool
    {
        return $result->ok&&($result->data['user']['id']??null)===$user&&is_array($result->data['roles']??null)
            &&array_is_list($result->data['roles'])&&count($result->data['roles'])<=250&&count(array_filter($result->data['roles'],self::id(...)))===count($result->data['roles']);
    }
    public function awardAndAnnounce(string $user,int $threshold,int $total,string $key):RestResult
    {
        $role=$this->grantTitle($user,$threshold);
        return $role->ok?$this->postAchievement($user,$threshold,$total,$key):$role;
    }

    /** NOT atomic. Prepared option only, with two separate OFF-by-default acknowledgements. */
    public function tryRelocate(string $user,?string $destination,string $key,?MemberActionFence $fence=null):RestResult
    {
        if($destination!==null){return new RestResult(false,'automatic_moves_disabled',outcome:TransportOutcome::NotStarted);}
        if(!$this->options['enabled']||!$this->options['member_actions_enabled']||!$this->options['accept_voice_race_risk']){return new RestResult(false,'member_risk_option_disabled',outcome:TransportOutcome::NotStarted);}
        if(!self::id($user)||$user===DiscordRestClient::APPLICATION_ID||($destination!==null&&$destination!==self::DESTINATION_ID)){return new RestResult(false,'scope_rejected',outcome:TransportOutcome::NotStarted);}
        $first=$this->readVoice($user);$checked=($this->monotonic)();$second=$first->ok?$this->readVoice($user):$first;
        if(!$first->ok||!$second->ok){return new RestResult(false,'voice_unknown',outcome:TransportOutcome::NotStarted);}
        if($first->data['channel_id']!==DiscordRestClient::VOICE_CHANNEL_ID||$second->data['channel_id']!==DiscordRestClient::VOICE_CHANNEL_ID){return new RestResult(false,'not_in_source_voice',outcome:TransportOutcome::NotStarted);}
        $json=json_encode(['channel_id'=>$destination],JSON_THROW_ON_ERROR);
        $claim=$this->claim($key,'voice',$user,hash('sha256',$json));
        if($claim instanceof RestResult){return $claim;}
        if($claim['status']!=='claimed'){return new RestResult(false,'member_attempt_already_recorded');}
        if(($this->monotonic)()-$checked>0.25){
            $released=$this->finish($key,$claim['owner'],TransportOutcome::NotStarted);
            return new RestResult(false,$released?'voice_check_expired':'operation_claim_lost',outcome:$released?TransportOutcome::NotStarted:TransportOutcome::Uncertain);
        }
        $sourceGuard=$this->options['source_guard']??static fn(string $unused,?MemberActionFence $unusedFence):bool=>true;
        $changed=$this->request('PATCH','/guilds/'.DiscordRestClient::GUILD_ID.'/members/'.$user,'member:voice',$json,null,null,
            fn():bool=>($this->monotonic)()-$checked<=0.25&&$sourceGuard($user,$fence)===true&&($this->monotonic)()-$checked<=0.25);
        $after=$changed->ok?$this->readVoice($user):$changed;
        $confirmed=$changed->ok&&$after->ok&&$after->data['channel_id']===$destination;
        $outcome=$confirmed?TransportOutcome::Succeeded:($changed->outcome->retryable()?$changed->outcome:TransportOutcome::Uncertain);
        if(!$this->finish($key,$claim['owner'],$outcome)){return new RestResult(false,'operation_claim_lost');}
        $code=match($outcome){TransportOutcome::Succeeded=>'best_effort_applied',TransportOutcome::NotStarted=>'member_not_started',TransportOutcome::Rejected=>'member_rejected',default=>'member_result_uncertain'};
        return new RestResult($confirmed,$code,$changed->status,
            ['atomic'=>false,'residual_risk'=>true,'automatic_compensation'=>false],$changed->retryAfter,$changed->syntheticStatus,$outcome);
    }

    private function claim(string $key,string $kind,string $target,string $hash):array|RestResult
    {
        if(preg_match('/\A[A-Za-z0-9:._-]{1,200}\z/D',$key)!==1){return ['status'=>'operation_key_invalid'];}
        $observedExisting=null;
        try{$owner=bin2hex(random_bytes(16));return $this->db->transaction(function(PDO $pdo)use($key,$kind,$target,$hash,$owner,&$observedExisting):array{
            $q=$pdo->prepare('SELECT * FROM operation_receipts WHERE operation_key=?');$q->execute([$key]);$row=$q->fetch();
            if($row!==false){$observedExisting=$row;return $row['kind']===$kind&&$row['target']===$target&&hash_equals($row['payload_sha256'],$hash)?$row:['status'=>'operation_conflict'];}
            $pdo->prepare("INSERT INTO operation_receipts(operation_key,kind,target,payload_sha256,status,created_at,claim_owner) VALUES(?,?,?,?,'sending',?,?)")->execute([$key,$kind,$target,$hash,(int)($this->now)(),$owner]);
            return ['status'=>'claimed','owner'=>$owner];
        });}catch(\Throwable $error){return $this->claimUnavailable($error,$observedExisting===null?TransportOutcome::NotStarted:TransportOutcome::Uncertain);}
    }
    /** This invocation has not entered transport. Never erase a row whose ownership could not be read. */
    private function claimUnavailable(\Throwable $error,TransportOutcome $outcome=TransportOutcome::NotStarted):RestResult
    {
        $this->diagnostics?->exception('rest_exception',$error);
        $this->diagnostics?->record('rest',['ok'=>false,'code'=>'operation_claim_unavailable','outcome'=>$outcome->value]);
        return new RestResult(false,'operation_claim_unavailable',outcome:$outcome);
    }
    private function finish(string $key,string $owner,TransportOutcome $outcome,?string $message=null):bool
    {
        return $this->db->transaction(function(PDO $pdo)use($key,$owner,$outcome,$message):bool{
            if($outcome->retryable()){
                $q=$pdo->prepare("DELETE FROM operation_receipts WHERE operation_key=? AND claim_owner=? AND status='sending'");$q->execute([$key,$owner]);
            }else{
                $q=$pdo->prepare("UPDATE operation_receipts SET status=?,message_id=?,finished_at=?,claim_owner=NULL WHERE operation_key=? AND claim_owner=? AND status='sending'");
                $q->execute([$outcome===TransportOutcome::Succeeded?'sent':'uncertain',$message,(int)($this->now)(),$key,$owner]);
            }
            return $q->rowCount()===1;
        });
    }
    private function request(string $method,string $path,string $route,string $body='',?RoleMutationScope $scope=null,?RoomMutationScope $roomScope=null,?\Closure $lastGuard=null):RestResult
    {
        $started=hrtime(true);
        try{
            $result=$this->requestInternal($method,$path,$route,$body,$scope,$roomScope,$lastGuard);
            $fields=['ok'=>$result->ok,'code'=>$result->code,'status'=>$result->status,'outcome'=>$result->outcome->value,'route'=>SafeDiagnostics::route($route),
                'duration_ms'=>(int)((hrtime(true)-$started)/1000000)];
            if(preg_match('~/(?:voice-states|members)/([1-9][0-9]{16,19})\z~D',$path,$match)){$fields['actor']=$this->diagnostics?->alias($match[1])??'outside';}
            $this->diagnostics?->record('rest',$fields);
            return $result;
        }catch(\Throwable $error){$this->diagnostics?->exception('rest_exception',$error);throw $error;}
    }
    private function requestInternal(string $method,string $path,string $route,string $body='',?RoleMutationScope $scope=null,?RoomMutationScope $roomScope=null,?\Closure $lastGuard=null):RestResult
    {
        if(!$this->options['enabled']){return new RestResult(false,'operations_disabled',outcome:TransportOutcome::NotStarted);}
        $exchangeStarted=false;
        try{
            if(($this->requestGuard)()!==true){return new RestResult(false,'operation_budget_exhausted',outcome:TransportOutcome::NotStarted);}
            if($this->authenticationFailed){return new RestResult(false,'unauthorized',401,[],0,true,TransportOutcome::NotStarted);}
            if(($wait=$this->limits->cooldown($route,($this->now)()))>0){return new RestResult(false,'rate_limited',429,[],$wait,true,TransportOutcome::NotStarted);}
            $request=new HttpRequest($method,'https://discord.com/api/v10'.$path,
                ['Authorization: Bot '.$this->botToken,'Content-Type: application/json','User-Agent: DiscordBot (https://hosting-user.example.invalid, 0.2)'],
                $body,2000,500,65536,false,$scope,$roomScope);
            // Database/cooldown work may use up a budget. The source guard is the last check before transport.
            if(($this->requestGuard)()!==true){return new RestResult(false,'operation_budget_exhausted',outcome:TransportOutcome::NotStarted);}
            if($lastGuard!==null&&$lastGuard()!==true){return new RestResult(false,'source_generation_changed',0,[],0,true,TransportOutcome::NotStarted);}
            $exchangeStarted=true;$response=$this->http->exchange($request);
            if($response->error!==null){$this->diagnostics?->record('rest',['ok'=>false,'route'=>SafeDiagnostics::route($route),'transport'=>$response->error,'status'=>$response->status]);}
            if($response->error!==null||$response->status===0||strlen($response->body)>65536){return new RestResult(false,'transport_uncertain',outcome:TransportOutcome::Uncertain);}
            if($response->status===401){$this->authenticationFailed=true;return new RestResult(false,'unauthorized',401,outcome:TransportOutcome::Rejected);}
            $jsonValid=true;
            try{$data=$response->body===''?[]:json_decode($response->body,true,32,JSON_THROW_ON_ERROR);}
            catch(\Throwable $error){$this->diagnostics?->exception('rest_exception',$error);if($response->status!==429){return new RestResult(false,'response_invalid',$response->status);}$data=[];$jsonValid=false;}
            if(!is_array($data)){return new RestResult(false,'response_invalid');}
            $headers=[];foreach($response->headers as$key=>$value){if(is_string($key)&&(is_string($value)||is_int($value))){$headers[strtolower($key)]=(string)$value;}}
            $retry=$data['retry_after']??$headers['retry-after']??1;
            $valid=$jsonValid&&is_numeric($retry)&&is_finite((float)$retry)&&(float)$retry>=0;
            $retry=$valid?max(0.25,min(86400,(float)$retry)):86400;
            $this->limits->observe($route,$route,$headers,$response->status,$retry,!$valid||($data['global']??false)===true||($headers['x-ratelimit-global']??'')==='true'||($headers['x-ratelimit-scope']??'')==='global',($this->now)());
            if($response->status===401){$this->authenticationFailed=true;}
            return $response->status>=200&&$response->status<300?new RestResult(true,'ok',$response->status,$data,outcome:TransportOutcome::Succeeded)
                :new RestResult(false,match($response->status){401=>'unauthorized',403=>'forbidden',404=>'not_found',429=>'rate_limited',default=>'discord_rejected'},$response->status,[],$response->status===429?$retry:0,
                    outcome:in_array($response->status,[400,401,403,404,405,429],true)?TransportOutcome::Rejected:TransportOutcome::Uncertain);
        }catch(\Throwable $error){$this->diagnostics?->exception('rest_exception',$error);return new RestResult(false,$exchangeStarted?'transport_uncertain':'request_not_started',outcome:$exchangeStarted?TransportOutcome::Uncertain:TransportOutcome::NotStarted);}
    }
}
