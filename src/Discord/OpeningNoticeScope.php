<?php
declare(strict_types=1);
namespace RadioStamp\Discord;

use RadioStamp\Core\Database;

/** Legacy claim-bound notice token; the shared outbound guard forbids broadcast mentions. */
final class OpeningNoticeScope
{
    private function __construct(private Database $db,private array $snapshot,private string $key,private string $operationOwner,private string $coreOwner,private \Closure $clock){}

    public static function fromClaim(Database $db,array $snapshot,string $key,string $operationOwner,\Closure $clock):?self
    {
        try{
            $q=$db->pdo()->prepare('SELECT lease_owner FROM core_effects WHERE effect_key=?');$q->execute([$key]);$owner=$q->fetchColumn();$q->closeCursor();
            if(!is_string($owner)||preg_match('/\A[a-f0-9]{32}\z/D',$owner)!==1){return null;}
            $scope=new self($db,$snapshot,$key,$operationOwner,$owner,$clock);
            return $scope->permits('POST',self::path(),$scope->body())?$scope:null;
        }catch(\Throwable){return null;}
    }

    private static function path():string{return '/api/v10/channels/'.DiscordRestClient::OPENING_NOTICE_CHANNEL_ID.'/messages';}
    private function body():string
    {
        $payload=$this->snapshot['payload'];$payload['nonce']=substr(hash('sha256',$this->key),0,25);$payload['enforce_nonce']=true;
        return json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    }

    public function permits(string $method,string $path,string $body):bool
    {
        try{
            $id=$this->snapshot['session_id']??null;
            if($method!=='POST'||$path!==self::path()||!is_string($id)||$this->key!==OpeningNoticeMessage::key($id)
                ||!in_array($this->snapshot['slot_id']??null,['morning','midday','night'],true)||!hash_equals($this->body(),$body)){return false;}
            $q=$this->db->pdo()->prepare('SELECT * FROM sessions WHERE id=?');$q->execute([$id]);$session=$q->fetch();$q->closeCursor();
            if($session===false||in_array($session['status'],['planned','closing','closed'],true)||OpeningNoticeMessage::snapshot($session)!==$this->snapshot){return false;}
            $now=($this->clock)();
            if(!is_int($now)||$now<(int)$session['scheduled_at']-600||$now>=(int)$session['scheduled_at']){return false;}
            $q=$this->db->pdo()->prepare('SELECT * FROM core_effects WHERE effect_key=?');$q->execute([$this->key]);$effect=$q->fetch();$q->closeCursor();
            if($effect===false||$effect['kind']!=='opening_notice'||$effect['status']!=='pending'||$effect['cancelled_at']!==null
                ||$effect['lease_owner']!==$this->coreOwner||(int)$effect['attempts']<1||(int)$effect['attempts']>5
                ||(int)$effect['expires_at']!==(int)$session['scheduled_at']||json_decode($effect['payload_json'],true,32,JSON_THROW_ON_ERROR)!==$this->snapshot){return false;}
            $q=$this->db->pdo()->prepare('SELECT * FROM operation_receipts WHERE operation_key=?');$q->execute([$this->key]);$receipt=$q->fetch();$q->closeCursor();
            return $receipt!==false&&$receipt['kind']==='opening_notice'&&$receipt['target']===DiscordRestClient::OPENING_NOTICE_CHANNEL_ID
                &&$receipt['status']==='sending'&&$receipt['claim_owner']===$this->operationOwner&&hash_equals($receipt['payload_sha256'],hash('sha256',$body));
        }catch(\Throwable){return false;}
    }
}
