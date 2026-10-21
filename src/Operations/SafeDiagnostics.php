<?php
declare(strict_types=1);
namespace RadioStamp\Operations;

/** Explicit opt-in, private JSONL. Arbitrary strings and exception messages never cross this boundary. */
final class SafeDiagnostics
{
    private const STAGES=['http_prepare','http_controller','http_action','http_queue','ack_emit','ack_finish','after_response',
        'worker_claim','worker_result','followup','cycle','session','member_pending','rest','rest_exception','roster','member_guard','ack_handoff','weekly_ranking'];
    private const ACTIONS=['receipt','attend','card','status','start','memorial','check'];
    private const ROUTES=['identity','gateway','voice_read','member_write','room_read','room_write','message','followup','other'];
    private const CODES=[
        'weekly_ranking_disabled','weekly_ranking_not_due','weekly_ranking_skipped','weekly_ranking_complete',
        'weekly_ranking_held','weekly_ranking_retry_wait','weekly_ranking_retry_pending','weekly_ranking_retry_exhausted',
        'weekly_ranking_part_sent','weekly_ranking_deferred','weekly_ranking_unavailable',
        'weekly_ranking_expired','weekly_ranking_configuration_unavailable',
        'other_code','exception','parsed','config_ready','operations_unavailable','response_prepared','emitted','finished','unavailable',
        'administrator_validation_unavailable','method_not_allowed','request_too_large','invalid_signature','invalid_json',
        'wrong_application','administrator_scope_required','wrong_channel','invalid_interaction','unsupported_action',
        'post_response_unavailable','private_worker_configuration_required','invalid_display_name','interaction_conflict',
        'private_queue_unavailable','queued','claimed','no_job','done','retry','worker_retry','validation_followup_retry',
        'mock_followup_retry','worker_failed','worker_claim_lost','operations_cycle','tick','operations_identity_verified','validation_bot_identity_unverified',
        'closed','warning_grace','warning_pending','closure_pending','room_close_pending','closure_rescheduled',
        'voice_roster_unknown','voice_roster_stale','voice_unknown','voice_observed','voice_state_unknown','voice_state_stale',
        'not_in_voice','not_in_target_voice','not_in_source_voice','disconnect_pending','move_pending','member_still_in_voice','destination_unverified','member_actions_disabled',
        'member_action_transport_unsafe','manual_member_actions_required','session_active','session_not_open','session_closed',
        'receipt_opened','receipt_already_open','receipt_announcement_unconfirmed',
        'ack_handoff_saved','ack_handoff_pending','ack_handoff_recovered','ack_handoff_rejected','interaction_expired',
        'operation_claim_lost','operation_claim_unavailable','delivery_not_started','delivery_rejected','member_not_started','member_rejected',
        'request_not_started','receipt_expired_room_close_pending','receipt_expired_closed','receipt_expired_superseded',
        'receipt_request_expired','session_schedule_conflict','schedule_too_close',
        'slot_binding_required','session_slot_binding_conflict','schedule_slot_invalid','schedule_slot_conflict',
        'operations_scope_forbidden','participant_flow_disabled','attended','already_attended','status_ready',
        'card_ready','memorial_ready','memorial_locked','card_render_failed','card_data','source_match_failed','scope_rejected','guard_disabled',
        'roster_known','roster_unknown','roster_scope_rejected','bot_identity_checked','bot_identity_cached','gateway_verified',
        'gateway_guard_stopped','gateway_guard_exception','gateway_connect_failed','gateway_hello_invalid','gateway_send_failed',
        'gateway_heartbeat_lost','gateway_identify_limited','gateway_exception','gateway_close_failed',
        'message_confirmed','delivery_uncertain','already_sent','sending','sent','uncertain','room_verified','room_unknown',
        'room_permissions_disabled','room_baseline_unavailable','room_baseline_changed','room_change_unconfirmed',
        'automatic_moves_disabled','member_risk_option_disabled','member_attempt_already_recorded','voice_check_expired',
        'best_effort_applied','member_result_uncertain','operation_budget_exhausted','source_generation_changed',
        'operation_deadline','transport_disabled','ok','forbidden','not_found','discord_rejected','rate_limited','unauthorized','transport_uncertain','transport_timeout','transport_failed','transport_unavailable',
        'transport_async_dns_required','response_too_large','request_failed','http_failed','response_invalid','lease_lost',
        'missed_session_expired','superseded_session_closed','reminder_expired','not_due','opening_pending'
    ];
    private ?string $trace=null;
    private ?string $actor=null;
    private ?string $action=null;
    private int $started;
    private int $dropped=0;
    private array $dynamicAliases=[];
    private function __construct(private readonly bool $enabled,private readonly ?\Closure $writer,private readonly array $participants=[],?int $started=null,private readonly bool $guildScope=false)
    {
        $this->started=$started??hrtime(true);
    }
    public static function fromConfig(array $config,?\Closure $writer=null,?int $started=null):self
    {
        if(($config['diagnostics_enabled']??false)!==true){return new self(false,null);}
        $participants=array_values(array_filter($config['participant_user_ids']??[],static fn($id)=>is_string($id)&&preg_match('/\A[1-9][0-9]{16,19}\z/D',$id)===1));
        $guildScope=ParticipantScope::mode($config)==='guild';
        if($writer!==null){return new self(true,$writer,$participants,$started,$guildScope);}
        $root=$config['private_root']??null;
        if(!is_string($root)||!is_dir($root)||is_link($root)||($real=realpath($root))===false){return new self(false,null);}
        $path=$real.DIRECTORY_SEPARATOR.'operations-diagnostic.safe.jsonl';
        if(is_link($path)||(file_exists($path)&&realpath($path)!==$path)){return new self(false,null);}
        $writer=static function(string $line)use($path):bool{
            if(is_link($path)){return false;}
            $mask=umask(0077);
            try{
                $handle=@fopen($path,'ab');
                if($handle===false){return false;}
                try{if(!@flock($handle,LOCK_EX|LOCK_NB)){return false;}@chmod($path,0600);$written=@fwrite($handle,$line);@flock($handle,LOCK_UN);return $written===strlen($line);}
                finally{@fclose($handle);}
            }finally{umask($mask);}
        };
        return new self(true,$writer,$participants,$started,$guildScope);
    }
    public static function code(mixed $code):string{return is_string($code)&&in_array($code,self::CODES,true)?$code:'other_code';}
    public static function route(string $route):string
    {
        return match(true){
            $route==='identity'=>'identity',$route==='gateway:info'=>'gateway',$route==='voice:read'=>'voice_read',
            $route==='member:voice'=>'member_write',$route==='room:read'=>'room_read',$route==='room:write'=>'room_write',
            str_starts_with($route,'messages:')=>'message',default=>'other'
        };
    }
    public function alias(?string $id):string
    {
        $index=array_search($id,$this->participants,true);
        // Guild users get process-local aliases. No raw ID or cross-process identity map is written.
        if($index===false&&$this->guildScope&&is_string($id)&&preg_match('/\A[1-9][0-9]{16,19}\z/D',$id)===1){return $this->dynamicAliases[$id]??= 'u'.(count($this->dynamicAliases)+1);}
        return $index===false?'outside':'p'.($index+1);
    }
    public function context(?string $id=null,?string $user=null,?string $action=null):void
    {
        $this->trace=is_string($id)&&preg_match('/\A[1-9][0-9]{16,19}\z/D',$id)===1?substr(hash('sha256',$id),0,12):null;
        $this->actor=$user===null?null:$this->alias($user);
        $this->action=in_array($action,self::ACTIONS,true)?$action:null;
    }
    public function record(string $stage,array $fields=[]):void
    {
        if(!$this->enabled||$this->writer===null||!in_array($stage,self::STAGES,true)){return;}
        try{
            $event=['at_utc'=>gmdate('Y-m-d\TH:i:s\Z'),'stage'=>$stage,'elapsed_ms'=>(int)((hrtime(true)-$this->started)/1000000)];
            if($this->dropped>0){$event['dropped_events']=$this->dropped;}
            foreach(['trace','actor','action']as$key){if($this->$key!==null){$event[$key]=$this->$key;}}
            foreach(['ok','finished','sent','known']as$key){if(is_bool($fields[$key]??null)){$event[$key]=$fields[$key];}}
            foreach(['status','duration_ms','count','failures','pending']as$key){if(is_int($fields[$key]??null)){$event[$key]=max(0,min(10000000,$fields[$key]));}}
            if(array_key_exists('code',$fields)){$event['code']=self::code($fields['code']);}
            if(isset($fields['transport'])){$event['transport']=self::code($fields['transport']);}
            if(in_array($fields['outcome']??null,['not_started','rejected','succeeded','uncertain'],true)){$event['outcome']=$fields['outcome'];}
            if(in_array($fields['route']??null,self::ROUTES,true)){$event['route']=$fields['route'];}
            if(is_string($fields['actor']??null)&&preg_match('/\A(?:p(?:[1-9]|1[0-9]|20)|u[1-9][0-9]{0,5}|outside)\z/D',$fields['actor'])===1){$event['actor']=$fields['actor'];}
            if(in_array($fields['exception']??null,['PDOException','JsonException','InvalidArgumentException','TypeError','Error','RuntimeException','OtherThrowable'],true)){$event['exception']=$fields['exception'];}
            $written=($this->writer)(json_encode($event,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
            $this->dropped=$written===false?$this->dropped+1:0;
        }catch(\Throwable){$this->dropped++;/* Diagnostics never break business processing. */}
    }
    public function exception(string $stage,\Throwable $error):void
    {
        $type=match(true){$error instanceof \PDOException=>'PDOException',$error instanceof \JsonException=>'JsonException',
            $error instanceof \InvalidArgumentException=>'InvalidArgumentException',$error instanceof \TypeError=>'TypeError',
            $error instanceof \Error=>'Error',$error instanceof \RuntimeException=>'RuntimeException',default=>'OtherThrowable'};
        $this->record($stage,['ok'=>false,'code'=>'exception','exception'=>$type]);
    }
}
