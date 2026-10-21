<?php
declare(strict_types=1);
namespace RadioStamp\Core;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RadioStamp\Discord\Gateway;
use RadioStamp\Discord\MockGateway;
use RadioStamp\Discord\VoiceState;
use RadioStamp\Discord\VoiceRoster;
use RadioStamp\Discord\ButtonBuilder;
use RadioStamp\Discord\AtomicMemberGateway;
use RadioStamp\Discord\MemberActionFence;
use RadioStamp\Discord\AdminValidationGateway;
use RadioStamp\Discord\RestGateway;
use RadioStamp\Discord\AchievementGateway;
use RadioStamp\Discord\DayAchievementGateway;
use RadioStamp\Discord\ReceiptMessage;
use RadioStamp\Discord\{ReceiptGateway,RoleGateway,BestEffortMemberGateway,ServerOperationsGateway};
use RadioStamp\Discord\{DeliveryAwareGateway,TransportOutcome};
use RadioStamp\Discord\{OpeningNoticeGateway,OpeningNoticeMessage};
use RadioStamp\Operations\OperationsConfig;

final class RadioService
{
    private array $config;
    private DateTimeZone $timezone;
    private Schedule $schedule;
    private bool $adminValidationMode = false;
    private bool $operationsMode = false;

    public function __construct(private Database $db, private Gateway $discord, private Clock $clock, array $config)
    {
        $this->config = $config + [
            'timezone' => 'Asia/Tokyo', 'mode' => 'mock', 'member_actions_enabled' => false,
            'destination_channel_id' => null, 'role_ids' => [], 'max_roster_age_seconds' => 5,
            'live_automation_enabled' => false,
            'achievement_notifications_enabled' => false,
            'participant_commands_enabled' => false,
        ];
        // A live REST snapshot is not an atomic complete roster, and GET->PATCH can race.
        // Administrator validation permits only self-presence reads and local records.
        // It does not enable any scheduled or public effect, or member mutation.
        if ($this->config['mode'] === 'mock' && $discord instanceof MockGateway) {
            // Existing deterministic simulation boundary.
        } elseif ($this->config['mode'] === 'admin_validation' && $discord instanceof RestGateway && $discord instanceof AdminValidationGateway && !$discord instanceof AtomicMemberGateway) {
            $admins = $config['admin_user_ids'] ?? null;
            if (($config['admin_validation_enabled'] ?? null) !== true || ($config['member_actions_enabled'] ?? null) !== false
                || ($config['role_ids'] ?? null) !== [] || ($config['live_automation_enabled'] ?? null) !== false
                || ($config['achievement_notifications_enabled'] ?? false) !== false
                || ($config['participant_commands_enabled'] ?? false) !== false
                || !is_array($admins) || !array_is_list($admins) || count($admins) !== 1
                || !is_string($admins[0]) || preg_match('/^[1-9][0-9]{16,19}$/D', $admins[0]) !== 1) {
                throw new \LogicException('Administrator validation requires one explicit user and disabled automation and member actions.');
            }
            $this->adminValidationMode = true;
        } elseif($this->config['mode']==='operations'&&$discord instanceof ServerOperationsGateway&&OperationsConfig::ready($config)) {
            $this->operationsMode=true;
        } else {
            throw new \LogicException('This gateway and execution mode are not permitted.');
        }
        if ($this->config['timezone'] !== 'Asia/Tokyo') { throw new \InvalidArgumentException('The calendar timezone must be Asia/Tokyo.'); }
        if (!is_bool($this->config['participant_commands_enabled'])) { throw new \InvalidArgumentException('Participant flow flag must be explicit.'); }
        foreach (['guild_id', 'voice_channel_id', 'text_channel_id', 'records_channel_id'] as $key) {
            if (!isset($this->config[$key]) || !is_string($this->config[$key]) || $this->config[$key] === '') {
                throw new \InvalidArgumentException('Required channel configuration is missing.');
            }
        }
        if ($this->adminValidationMode && ($this->config['voice_channel_id'] !== RestGateway::VOICE_CHANNEL_ID
            || !$discord->permitsValidationUser($this->config['guild_id'], $this->config['admin_user_ids'][0]))) {
            throw new \LogicException('The administrator validation scope does not match its fixed transport.');
        }
        if (!is_int($this->config['max_roster_age_seconds']) || $this->config['max_roster_age_seconds'] < 0 || $this->config['max_roster_age_seconds'] > 5) {
            throw new \InvalidArgumentException('Voice observation freshness must be at most five seconds.');
        }
        $this->timezone = new DateTimeZone('Asia/Tokyo');
        $this->schedule = Schedule::fromConfig($config);
        $this->config['warning_channel_id'] ??= $this->config['voice_channel_id'];
        $this->config['entry_notice_channel_id'] ??= $this->config['voice_channel_id'];
    }

    public function __debugInfo():array{return ['mode'=>$this->config['mode'],'credentials'=>'[redacted]'];}

    /** Inclusive instant range. Midnight is keyed to its scheduled JST date. */
    public function planSessions(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $times=$this->schedule->between($from,$to);
        return $this->db->transaction(function (PDO $pdo) use ($times): array {
            $sessions = [];
            foreach ($times as $scheduled) {
                $sessions[] = $this->plannedSession($pdo,$scheduled);
            }
            return $sessions;
        });
    }

    /** Existing rows are returned unchanged; only new occurrences receive an immutable snapshot. */
    private function plannedSession(PDO $pdo,DateTimeImmutable $scheduled): array
    {
        $id=$scheduled->format('Y-m-d_Hi');$existing=$this->session($pdo,$id);
        if($existing!==null){return $existing;}
        $binding=$this->adminValidationMode?null:$this->schedule->bindingForSession($id);
        if(!$this->adminValidationMode&&$binding===null){throw new \RuntimeException('slot_binding_required');}
        if($binding!==null){
            $query=$pdo->prepare('SELECT id FROM sessions WHERE count_date=? AND slot_id=? LIMIT 1');$query->execute([$scheduled->format('Y-m-d'),$binding['slot_id']]);$other=$query->fetchColumn();$query->closeCursor();
            if($other!==false){throw new \RuntimeException('session_slot_binding_conflict');}
        }
        $at=$scheduled->getTimestamp();
        $pdo->prepare('INSERT OR IGNORE INTO sessions(id,scheduled_at,count_date,opens_at,unstarted_closes_at,status,slot_id,stamp) VALUES(?,?,?,?,?,?,?,?)')
            ->execute([$id,$at,$scheduled->format('Y-m-d'),$at-1800,$at+1800,'planned',$binding['slot_id']??null,$binding['stamp']??null]);
        return $this->session($pdo,$id)??throw new \RuntimeException('session_slot_binding_conflict');
    }

    /** A snapshot is never repaired from current configuration, including an unstarted legacy row. */
    private function sessionBindingError(array $session): ?string
    {
        if($this->adminValidationMode){return null;}
        $expected=$this->schedule->bindingForSession((string)$session['id']);
        if($expected===null||($session['slot_id']??null)===null||($session['stamp']??null)===null){return 'slot_binding_required';}
        return $session['slot_id']===$expected['slot_id']&&$session['stamp']===$expected['stamp']?null:'session_slot_binding_conflict';
    }

    public function tick(): array
    {
        if ($this->adminValidationMode) {
            return $this->result(false, 'live_automation_disabled', ['sessions' => [], 'processed' => 0, 'mode' => 'admin_validation']);
        }
        $now = $this->clock->now()->setTimezone($this->timezone);
        $planningError=null;
        try{$this->planSessions($now->modify('-1 day')->setTime(0, 0),$now->modify('+2 days')->setTime(0, 0));}
        catch(\RuntimeException $error){if(!in_array($error->getMessage(),['slot_binding_required','session_slot_binding_conflict'],true)){throw $error;}$planningError=$error->getMessage();}
        $this->expireReminders();
        $query = $this->db->pdo()->prepare("SELECT id FROM sessions WHERE status != 'closed' AND opens_at <= ? ORDER BY scheduled_at");
        $query->execute([$now->getTimestamp()]);
        $ids = $query->fetchAll(PDO::FETCH_COLUMN);
        $results = [];
        foreach ($ids as $id) {
            // Removed occurrences may still need local expiry bookkeeping. Binding guards prevent new I/O.
            $results[] = $this->processSession((string)$id);
        }
        $bindingBlocked=$planningError!==null||array_filter($results,static fn(array $row):bool=>in_array($row['code']??null,['slot_binding_required','session_slot_binding_conflict'],true))!==[];
        if(!$bindingBlocked){$this->syncRoles();$this->flushAchievementNotices();$this->flushDayAchievementNotices();}
        return $this->result($planningError===null,$planningError??'tick',['sessions'=>$results,'processed'=>count($results),'mode'=>'mock']);
    }

    public function start(string $sessionId, string $userId, string $interactionId): array
    {
        if (!$this->validationUserAllowed($userId)) { return $this->result(false, 'admin_validation_forbidden'); }
        if($this->schedule->isBeforeStart($sessionId)){return $this->result(false,'session_before_launch');}
        if(($replay=$this->interactionReplay('start',$sessionId,$userId,$interactionId))!==null){return $replay;}
        if (!$this->adminValidationMode && !$this->schedule->contains($sessionId)) { return $this->result(false,'session_not_found'); }
        if ($this->config['participant_commands_enabled']) { return $this->result(false, 'use_receipt_command'); }
        if ($this->adminValidationMode && ($preparation = $this->prepareValidationSession($sessionId)) !== null) { return $preparation; }
        $state = $this->discord->voiceState($this->config['guild_id'], $userId);
        $result = $this->interaction('start', $sessionId, $userId, $interactionId, function (PDO $pdo) use ($sessionId, $userId, $state): array {
            $session = $this->session($pdo, $sessionId);
            if ($session === null) { return $this->result(false, 'session_not_found'); }
            $now = $this->now();
            if ($session['status'] === 'closed' || $session['status'] === 'closing' || $now >= (int)($session['closes_at'] ?? $session['unstarted_closes_at'])) {
                return $this->result(false, 'session_closed');
            }
            if ($now < (int)$session['opens_at']) { return $this->result(false, 'session_not_open'); }
            if(($bindingError=$this->sessionBindingError($session))!==null){return $this->result(false,$bindingError,['session_id'=>$sessionId]);}
            if ($session['started_at'] !== null) { return $this->result(false, 'already_started', ['session_id' => $sessionId]); }
            $voiceError = $this->presenceError($state);
            if ($voiceError !== null) { return $this->result(false, $voiceError); }
            if (!$this->adminValidationMode && $this->sessionScheduleConflict($pdo,$session,$now,$now+900)) {
                return $this->result(false,'session_schedule_conflict',['session_id'=>$sessionId]);
            }
            if (!$this->adminValidationMode) { $this->ensureOpen($pdo, $session); }
            $pdo->prepare("UPDATE sessions SET status='started',started_at=?,started_by=?,closes_at=?,warning_sent_at=NULL WHERE id=? AND started_at IS NULL")
                ->execute([$now, $userId, $now + 900, $sessionId]);
            return $this->result(true, 'started', [
                'session_id' => $sessionId, 'started_at' => $now, 'warning_at' => $now + 840,
                'closes_at' => $now + 900, 'count_date' => $session['count_date'],
            ]);
        });
        if ($result['ok'] && !$this->adminValidationMode) { $this->flushOpening($sessionId); }
        return $result;
    }

    /** A post-exercise participant command; only the explicitly enabled Mock flow can use it. */
    public function openAttendance(string $userId, string $interactionId, ?int $requestedAt = null): array
    {
        if ($this->adminValidationMode || !$this->config['participant_commands_enabled']) { return $this->result(false, 'participant_flow_disabled'); }
        if(!$this->validationUserAllowed($userId)){return $this->result(false,'operations_scope_forbidden');}
        if ($userId === '' || strlen($userId)>200 || $interactionId === '' || strlen($interactionId)>200) { return $this->result(false, 'invalid_request'); }
        $query = $this->db->pdo()->prepare('SELECT operation,user_id,result_json FROM core_interactions WHERE id=?');
        $query->execute([$interactionId]);
        $replay = $query->fetch();
        if ($replay !== false) {
            if ($replay['operation'] !== 'receipt' || $replay['user_id'] !== $userId) { return $this->result(false, 'interaction_conflict'); }
            $result = json_decode($replay['result_json'],true,32,JSON_THROW_ON_ERROR);
            if($this->schedule->isBeforeStart((string)($result['session_id']??''))){return $this->result(false,'session_before_launch');}
            return ($result['ok'] ?? false) ? $this->receiptResponse($result) : $result;
        }
        $requestedAt ??= $this->now();
        if ($requestedAt > $this->now() || $requestedAt < $this->now()-120) { return $this->result(false, 'receipt_request_expired'); }
        $voiceError = $this->presenceError($this->discord->voiceState($this->config['guild_id'],$userId));
        if ($voiceError !== null) { return $this->result(false,$voiceError); }
        $id = $this->receiptSessionIdAt($requestedAt);
        if ($id === null) { return $this->result(false,'session_not_open'); }
        $scheduled = DateTimeImmutable::createFromFormat('!Y-m-d_Hi',$id,$this->timezone);
        $result = $this->interaction('receipt',$id,$userId,$interactionId,function(PDO $pdo) use($id,$scheduled,$userId,$requestedAt):array {
            try{$session=$this->plannedSession($pdo,$scheduled);}
            catch(\RuntimeException $error){if(!in_array($error->getMessage(),['slot_binding_required','session_slot_binding_conflict'],true)){throw $error;}return $this->result(false,$error->getMessage(),['session_id'=>$id]);}
            if (in_array($session['status'],['closed','closing'],true)) { return $this->result(false,'session_closed'); }
            if(($bindingError=$this->sessionBindingError($session))!==null){return $this->result(false,$bindingError,['session_id'=>$id]);}
            if ($session['started_at'] !== null) {
                if ($requestedAt >= (int)$session['closes_at'] || $this->now() >= (int)$session['closes_at']) { return $this->result(false,'session_closed'); }
                $notice=$pdo->prepare('SELECT 1 FROM receipt_announcements WHERE session_id=?');$notice->execute([$id]);
                if ($notice->fetchColumn() === false) { return $this->result(false,'receipt_announcement_unconfirmed'); }
                return $this->result(true,'receipt_already_open',['session_id'=>$id,'started_at'=>(int)$session['started_at'],'closes_at'=>(int)$session['closes_at']]);
            }
            if ($requestedAt < (int)$session['opens_at'] || $requestedAt >= (int)$session['unstarted_closes_at']) { return $this->result(false,'session_not_open'); }
            $closes=$requestedAt+600;
            if ($this->sessionScheduleConflict($pdo,$session,$this->now(),$closes)) {
                return $this->result(false,'session_schedule_conflict',['session_id'=>$id]);
            }
            $pdo->prepare("UPDATE sessions SET status='started',started_at=?,started_by=?,closes_at=?,warning_sent_at=NULL WHERE id=? AND started_at IS NULL")
                ->execute([$requestedAt,$userId,$closes,$id]);
            $pdo->prepare("INSERT OR IGNORE INTO receipt_announcements(session_id,status,created_at) VALUES(?,'pending',?)")->execute([$id,$requestedAt]);
            return $this->result(true,'receipt_opened',['session_id'=>$id,'started_at'=>$requestedAt,'closes_at'=>$closes,'warning_at'=>$requestedAt+540]);
        });
        return ($result['ok'] ?? false) ? $this->receiptResponse($result) : $result;
    }

    private function receiptSessionIdAt(int $at): ?string
    {
        // An active receipt can extend past the initial scheduled+30-minute window.
        $query=$this->db->pdo()->prepare("SELECT s.id FROM sessions s JOIN receipt_announcements r ON r.session_id=s.id WHERE s.status='started' AND s.started_at<=? AND s.closes_at>? ORDER BY s.scheduled_at DESC LIMIT 1");
        $query->execute([$at,$at]);$active=$query->fetchColumn();
        if (is_string($active) && $this->schedule->contains($active)) { return $active; }
        return $this->schedule->initialSessionAt($at);
    }

    private function receiptResponse(array $result): array
    {
        return $this->flushReceiptAnnouncement($result['session_id']) ? $result
            : $this->result(false,'receipt_announcement_unconfirmed',['session_id'=>$result['session_id'],'closes_at'=>$result['closes_at']]);
    }

    /** Claim before transport; only an owned, definitely unapplied outcome may return to pending. */
    private function flushReceiptAnnouncement(string $id): bool
    {
        $owner=bin2hex(random_bytes(16));
        $state=$this->db->transaction(function(PDO $pdo)use($id,$owner):array {
            $q=$pdo->prepare('SELECT r.status,r.expired_at,s.closes_at FROM receipt_announcements r JOIN sessions s ON s.id=r.session_id WHERE r.session_id=?');$q->execute([$id]);$row=$q->fetch();
            if($row!==false&&$row['expired_at']!==null){return ['status'=>'expired'];}
            if ($row===false || $row['status']!=='pending') { return $row===false ? ['status'=>'missing'] : $row; }
            if ($this->now()>=(int)$row['closes_at']) {
                $pdo->prepare("UPDATE receipt_announcements SET expired_at=? WHERE session_id=? AND status='pending' AND expired_at IS NULL")->execute([$this->now(),$id]);return ['status'=>'expired'];
            }
            $session=$this->session($pdo,$id);
            if($session===null||$this->sessionBindingError($session)!==null){return ['status'=>'binding_unavailable'];}
            if ($this->sessionScheduleConflict($pdo,$session,$this->now())) { return ['status'=>'conflict']; }
            $q=$pdo->prepare("UPDATE receipt_announcements SET status='sending',attempted_at=?,claim_owner=? WHERE session_id=? AND status='pending' AND expired_at IS NULL");$q->execute([$this->now(),$owner,$id]);
            return ['status'=>$q->rowCount()===1?'claimed':'sending','closes_at'=>(int)$row['closes_at']];
        });
        if ($state['status']!=='claimed') { return $state['status']==='sent'; }
        $payload=ReceiptMessage::build($id,$state['closes_at']);
        $outcome=TransportOutcome::Uncertain;$sent=false;
        try {
            if($this->discord instanceof DeliveryAwareGateway){$delivery=$this->discord->sendReceiptResult($id,$state['closes_at'],'receipt:'.$id);$sent=$delivery->ok;$outcome=$delivery->outcome;}
            else{$sent=($this->discord instanceof ReceiptGateway?$this->discord->sendReceipt($id,$state['closes_at'],'receipt:'.$id)
                :$this->discord->sendMessage($this->config['voice_channel_id'],$payload['content'],'receipt:'.$id,$payload['components']))===true;$outcome=$sent?TransportOutcome::Succeeded:TransportOutcome::Uncertain;}
        }catch(\Throwable){/* Without a definite no-send result, preserve uncertainty. */}
        $saved=$this->db->transaction(function(PDO $pdo)use($id,$owner,$outcome,$sent,$state):bool{
            $q=$pdo->prepare("UPDATE receipt_announcements SET status=?,sent_at=?,claim_owner=NULL,expired_at=? WHERE session_id=? AND status='sending' AND claim_owner=?");
            $q->execute([$sent?'sent':($outcome->retryable()?'pending':'uncertain'),$sent?$this->now():null,
                !$sent&&$outcome->retryable()&&$this->now()>=$state['closes_at']?$this->now():null,$id,$owner]);
            return $q->rowCount()===1;
        });
        return $sent&&$saved;
    }

    public function attend(string $sessionId, string $userId, string $interactionId): array
    {
        if (!$this->validationUserAllowed($userId)) { return $this->result(false, 'admin_validation_forbidden'); }
        if($this->schedule->isBeforeStart($sessionId)){return $this->result(false,'session_before_launch');}
        if(($replay=$this->interactionReplay('attend',$sessionId,$userId,$interactionId))!==null){return $replay;}
        if (!$this->adminValidationMode && !$this->schedule->contains($sessionId)) { return $this->result(false,'session_not_found'); }
        if ($this->adminValidationMode && ($preparation = $this->prepareValidationSession($sessionId)) !== null) { return $preparation; }
        $state = $this->discord->voiceState($this->config['guild_id'], $userId);
        $result = $this->interaction('attend', $sessionId, $userId, $interactionId, function (PDO $pdo) use ($sessionId, $userId, $state): array {
            $session = $this->session($pdo, $sessionId);
            if ($session === null) { return $this->result(false, 'session_not_found'); }
            if ($session['started_at'] === null) { return $this->result(false, 'session_not_started'); }
            if ($this->config['participant_commands_enabled']) {
                $notice = $pdo->prepare('SELECT status FROM receipt_announcements WHERE session_id=?');
                $notice->execute([$sessionId]);
                if ($notice->fetchColumn() !== 'sent') { return $this->result(false, 'receipt_announcement_unconfirmed'); }
            }
            $now = $this->now();
            if ($session['status'] !== 'started' || $now >= (int)$session['closes_at']) { return $this->result(false, 'session_closed'); }
            if ($now < (int)$session['started_at']) { return $this->result(false, 'session_not_started'); }
            $existing = $pdo->prepare('SELECT ordinal FROM attendance WHERE session_id=? AND user_id=?');
            $existing->execute([$sessionId, $userId]);
            if ($existing->fetchColumn() !== false) { return $this->result(false, 'already_attended', ['session_id' => $sessionId]); }
            if(($bindingError=$this->sessionBindingError($session))!==null){return $this->result(false,$bindingError,['session_id'=>$sessionId]);}
            $voiceError = $this->presenceError($state);
            if ($voiceError !== null) { return $this->result(false, $voiceError); }
            $pdo->prepare('INSERT OR IGNORE INTO participants(user_id,total_count) VALUES (?,0)')->execute([$userId]);
            $user = $this->participant($pdo, $userId);
            $total = (int)$user['total_count'] + 1;
            $month = $this->clock->now()->setTimezone($this->timezone)->format('Y-m');
            $previousDays = ParticipationDays::summary($pdo, $userId, $month)['total_days'];
            [$threshold, $title] = $this->titleFor($total);
            $stamp=$this->adminValidationMode?StampPolicy::LEGACY_STAMPS[($total-1)%count(StampPolicy::LEGACY_STAMPS)]:$session['stamp'];
            $pdo->prepare('INSERT INTO attendance(session_id,user_id,count_date,attended_at,ordinal,stamp) VALUES (?,?,?,?,?,?)')
                ->execute([$sessionId, $userId, $session['count_date'], $now, $total, $stamp]);
            $pdo->prepare('UPDATE participants SET total_count=?,latest_threshold=?,latest_title=? WHERE user_id=?')->execute([$total, $threshold, $title, $userId]);
            $daySummary = ParticipationDays::summary($pdo, $userId, $month);
            $newMilestone = $daySummary['total_days'] > $previousDays && in_array($daySummary['total_days'], ParticipationDays::MILESTONES, true)
                ? $daySummary['total_days'] : null;
            if(!$this->adminValidationMode&&$newMilestone!==null&&preg_match('/\A[1-9][0-9]{16,19}\z/D',$userId)===1){
                $owner=$newMilestone===365?$this->noticeOwner():null;
                $pdo->prepare("INSERT OR IGNORE INTO day_achievement_notices(user_id,milestone,total_days,owner_user_id,status,created_at) VALUES(?,?,?,?,'pending',?)")
                    ->execute([$userId,$newMilestone,$daySummary['total_days'],$owner,$now]);
            }
            // Legacy count-based memorial_awards stay intact, but are no longer granted or read as day eligibility.
            $result = $this->result(true, 'attended', [
                'session_id' => $sessionId, 'count_date' => $session['count_date'], 'stamp' => $stamp,
                'total_count' => $total, 'latest_title' => $title, 'title_basis' => 'attendance_count',
                'new_memorial' => $newMilestone, 'new_day_milestone' => $newMilestone,
                'total_days' => $daySummary['total_days'], 'month_days' => $daySummary['month_days'],
                'participation_date' => (new DateTimeImmutable('@' . $now))->setTimezone($this->timezone)->format('Y-m-d'),
            ]);
            // Operation results are returned through the caller's ephemeral response only.
            return $result;
        });
        return $result;
    }

    public function cardData(string $userId, string $displayName, string $month): array
    {
        if (!$this->validationUserAllowed($userId)) { return $this->result(false, 'admin_validation_forbidden'); }
        if (!preg_match('/^([0-9]{4})-(0[1-9]|1[0-2])$/D', $month, $matches) || (int)$matches[1] < 1) {
            throw new \InvalidArgumentException('A valid YYYY-MM month is required.');
        }
        // Read a consistent snapshot while concurrent attendance transactions continue.
        return $this->db->transaction(function (PDO $pdo) use ($userId, $displayName, $month): array {
            $user = $this->participant($pdo, $userId);
            $query = $pdo->prepare('SELECT count_date,stamp FROM attendance WHERE user_id=? AND substr(count_date,1,7)=? ORDER BY attended_at,ordinal');
            $query->execute([$userId, $month]);
            $days = [];
            $count = 0;
            foreach ($query as $record) { $days[$record['count_date']][] = $record['stamp']; $count++; }
            ksort($days);
            $daySummary = ParticipationDays::summary($pdo, $userId, $month);
            $total = (int)($user['total_count'] ?? 0);
            // Resolve display labels from the current policy without rewriting stored records.
            [, $title] = $this->titleFor($total);
            return $this->result(true, 'card_data', [
                'user_id' => $userId, 'display_name' => $displayName, 'month' => $month, 'month_count' => $count,
                'total_count' => $total, 'days' => $days,
                'latest_title' => $title, 'title_basis' => 'attendance_count',
                'total_days' => $daySummary['total_days'], 'month_days' => $daySummary['month_days'],
                'awards' => $daySummary['day_milestones'], 'day_milestones' => $daySummary['day_milestones'],
                'milestone_basis' => 'attendance_days_jst',
            ]);
        });
    }

    /** Own counters only. No voice lookup, session planning, attendance write, or effects. */
    public function statusData(string $userId): array
    {
        if (!$this->validationUserAllowed($userId)) { return $this->result(false, 'admin_validation_forbidden'); }
        return $this->db->transaction(function (PDO $pdo) use ($userId): array {
            $user = $this->participant($pdo, $userId);
            $month = $this->clock->now()->setTimezone($this->timezone)->format('Y-m');
            $daySummary = ParticipationDays::summary($pdo, $userId, $month);
            return $this->result(true, 'status_ready', [
                'total_count' => (int)($user['total_count'] ?? 0), 'total_days' => $daySummary['total_days'],
            ]);
        });
    }

    private function processSession(string $id): array
    {
        $initial=$this->session($this->db->pdo(),$id);
        if($initial===null||$initial['status']==='closed'){return $this->result(true,'closed',['session_id'=>$id]);}
        // Preserve pre-launch history/plans verbatim, including pending receipts and effects.
        if($this->schedule->isBeforeStart($id)){return $this->result(true,'session_before_launch',['session_id'=>$id]);}
        if ($this->config['participant_commands_enabled'] && $initial['started_at']!==null && (int)$initial['closes_at']<=$this->now()) {
            // A2's definite no-send expiry is local bookkeeping, even when an old window conflicts.
            $this->flushReceiptAnnouncement($id);
            $query=$this->db->pdo()->prepare('SELECT expired_at FROM receipt_announcements WHERE session_id=?');$query->execute([$id]);$expired=$query->fetchColumn();
            if ($expired!==false && $expired!==null) { return $this->closeExpiredUnsentReceipt($id); }
        }
        $missedPlanned=$initial['status']==='planned'&&$initial['started_at']===null&&(int)$initial['unstarted_closes_at']<=$this->now();
        if(!$missedPlanned&&($bindingError=$this->sessionBindingError($initial))!==null){return $this->result(false,$bindingError,['session_id'=>$id]);}
        if ($this->sessionScheduleConflict($this->db->pdo(),$initial,$this->now())) {
            return $this->result(false,'session_schedule_conflict',['session_id'=>$id]);
        }
        if ($this->config['participant_commands_enabled']) {
            $this->flushReceiptAnnouncement($id);
            $receipt=$this->session($this->db->pdo(),$id);
            if ($receipt !== null && $receipt['started_at'] !== null) {
                $q=$this->db->pdo()->prepare('SELECT status,expired_at FROM receipt_announcements WHERE session_id=?');$q->execute([$id]);$notice=$q->fetch();
                if($notice!==false&&$notice['expired_at']!==null){return $this->closeExpiredUnsentReceipt($id);}
                if ($notice===false||$notice['status']!=='sent') { return $this->result(false,'receipt_announcement_unconfirmed',['session_id'=>$id]); }
            }
        }
        $pdo = $this->db->pdo();
        $session = $this->session($pdo, $id);
        if ($session === null || $session['status'] === 'closed') { return $this->result(true, 'closed', ['session_id' => $id]); }
        $now = $this->now();
        $preparation = $this->db->transaction(function (PDO $pdo) use ($id, $now): ?string {
            $current = $this->session($pdo, $id);
            if ($current['status'] === 'closed') { return 'closed'; }
            if ($current['status'] === 'planned' && $now >= (int)$current['unstarted_closes_at']) {
                // Never open a never-started historical session after a worker outage.
                $pdo->prepare("UPDATE sessions SET status='closed',closed_at=? WHERE id=?")->execute([$now, $id]);
                return 'missed_session_expired';
            }
            if(($bindingError=$this->sessionBindingError($current))!==null){return $bindingError;}
            if ($this->sessionScheduleConflict($pdo,$current,$now)) { return 'session_schedule_conflict'; }
            $this->ensureOpen($pdo, $current);
            return null;
        });
        if ($preparation !== null) { return $this->result(!in_array($preparation,['session_schedule_conflict','slot_binding_required','session_slot_binding_conflict'],true),$preparation,['session_id'=>$id]); }
        $opened = $this->flushOpening($id);
        $session = $this->session($pdo, $id);
        $now = $this->now();
        if(($bindingError=$this->sessionBindingError($session))!==null){return $this->result(false,$bindingError,['session_id'=>$id]);}
        if ($this->sessionScheduleConflict($pdo,$session,$now)) { return $this->result(false,'session_schedule_conflict',['session_id'=>$id]); }
        $due = (int)($session['closes_at'] ?? $session['unstarted_closes_at']);
        $warningAt = $due - 60;
        $phase = $session['started_at'] === null ? 'unstarted' : 'started:' . $session['started_at'];
        $warningKey = 'warning:' . $id . ':' . $phase;
        if ($now < $warningAt) { return $this->result($opened, $opened ? 'session_active' : 'opening_pending', ['session_id' => $id]); }
        if ($session['warning_sent_at'] === null) {
            $this->enqueue($warningKey, 'warning', ['session_id' => $id, 'phase' => $phase]);
            $warning=$this->operationsMode&&$this->config['closure_policy']==='manual'
                ?'閉場まであと1分です。続けて話す方は牧歌的温泉街へ自分で移動してください。残留者の切断は管理者が確認します。'
                :($this->config['participant_commands_enabled']
                    ?'閉場まであと1分です。続けて話す方は牧歌的温泉街へ自分で移動してください。閉場時に残っている方は参加登録の有無にかかわらず切断します。'
                    :'閉場まであと1分です。参加登録者は牧歌的温泉街へ移動し、未登録者は切断されます。移動に失敗した方を切断することはありません。');
            $sent = $this->dispatch($warningKey, fn(string $key) =>
                $this->discord->sendMessage($this->config['warning_channel_id'], $warning, $key));
            if (!$sent) { return $this->result(false, 'warning_pending', ['session_id' => $id]); }
            // Completion time is persisted by dispatch after sending, outside any write lock.
            $startedAt = $session['started_at'];
            $this->db->transaction(function (PDO $pdo) use ($id, $warningKey, $startedAt): void {
                $query = $pdo->prepare("SELECT completed_at FROM core_effects WHERE effect_key=? AND status='done'");
                $query->execute([$warningKey]);
                $sentAt = $query->fetchColumn();
                if ($sentAt !== false) {
                    $sql = 'UPDATE sessions SET warning_sent_at=COALESCE(warning_sent_at,?) WHERE id=? AND ';
                    if ($startedAt === null) { $pdo->prepare($sql . 'started_at IS NULL')->execute([$sentAt, $id]); }
                    else { $pdo->prepare($sql . 'started_at=?')->execute([$sentAt, $id, $startedAt]); }
                }
            });
            $session = $this->session($pdo, $id);
            $currentPhase = $session['started_at'] === null ? 'unstarted' : 'started:' . $session['started_at'];
            if ($currentPhase !== $phase) { return $this->result(true, 'session_active', ['session_id' => $id]); }
        }
        $now = $this->now();
        if(($bindingError=$this->sessionBindingError($session))!==null){return $this->result(false,$bindingError,['session_id'=>$id]);}
        if ($this->sessionScheduleConflict($pdo,$session,$now)) { return $this->result(false,'session_schedule_conflict',['session_id'=>$id]); }
        if ($session['warning_sent_at'] === null || $now < $due || $now < (int)$session['warning_sent_at'] + 60) {
            return $this->result(true, 'warning_grace', ['session_id' => $id, 'not_before' => max($due, (int)$session['warning_sent_at'] + 60)]);
        }
        $closing = $this->db->transaction(function (PDO $pdo) use ($id, $due): bool {
            $now = $this->now();
            $update = $pdo->prepare("UPDATE sessions SET status='closing' WHERE id=? AND status IN ('open','started','closing') AND COALESCE(closes_at,unstarted_closes_at)=CAST(? AS INTEGER) AND COALESCE(closes_at,unstarted_closes_at)<=CAST(? AS INTEGER) AND warning_sent_at IS NOT NULL AND warning_sent_at+60<=CAST(? AS INTEGER)");
            $update->execute([$id, $due, $now, $now]);
            return $update->rowCount() === 1;
        });
        if (!$closing) { return $this->result(true, 'closure_rescheduled', ['session_id' => $id]); }
        if ($this->config['member_actions_enabled'] !== true) {
            if($this->operationsMode&&$this->config['closure_policy']==='manual'){
                $key='close-room:'.$id;$this->enqueue($key,'room_close',['session_id'=>$id]);
                if(!$this->dispatch($key,fn(string $operation)=>$this->discord->setRoomOpen($this->config['voice_channel_id'],false,$operation))){return $this->result(false,'room_close_pending',['session_id'=>$id]);}
                $this->db->transaction(fn(PDO $pdo)=>$pdo->prepare("UPDATE sessions SET status='closed',closed_at=? WHERE id=?")->execute([$this->now(),$id]));
                return $this->result(true,'manual_member_actions_required',['session_id'=>$id]);
            }
            return $this->result(false, 'member_actions_disabled', ['session_id' => $id]);
        }
        $atomic=$this->discord instanceof AtomicMemberGateway&&$this->discord->supportsConditionalMemberActions();
        $bestEffort=$this->operationsMode&&($this->config['accept_voice_race_risk']??false)===true&&$this->discord instanceof BestEffortMemberGateway&&$this->discord->supportsBestEffortMemberActions();
        if (!$atomic&&!$bestEffort) {
            return $this->result(false, 'member_action_transport_unsafe', ['session_id' => $id]);
        }
        $destination = $this->config['destination_channel_id'];
        if (!is_string($destination) || $destination === '' || $destination === $this->config['voice_channel_id']) {
            return $this->result(false, 'destination_unverified', ['session_id' => $id]);
        }
        $roster = $this->discord->roster($this->config['guild_id'], $this->config['voice_channel_id']);
        $rosterError = $this->rosterError($roster);
        if ($rosterError !== null) { return $this->result(false, $rosterError, ['session_id' => $id]); }
        $this->enqueue('close-room:' . $id, 'room_close', ['session_id' => $id]);
        if (!$this->dispatch('close-room:' . $id, fn(string $key) => $this->discord->setRoomOpen($this->config['voice_channel_id'], false, $key))) {
            return $this->result(false, 'room_close_pending', ['session_id' => $id]);
        }
        $members = array_values(array_unique(array_map('strval', $roster->memberIds)));
        $pending = [];
        foreach ($members as $userId) {
            $state = $this->discord->voiceState($this->config['guild_id'], $userId);
            $presenceError = $this->presenceError($state);
            if ($presenceError === 'not_in_voice') { continue; }
            if ($presenceError !== null) { $pending[$userId] = $presenceError; continue; }
            $fence = new MemberActionFence($this->config['guild_id'], $userId, $this->config['voice_channel_id'], $state->generation, $state->observedAt);
            $registered = $pdo->prepare('SELECT 1 FROM attendance WHERE session_id=? AND user_id=?');
            $registered->execute([$id, $userId]);
            if (!$this->config['participant_commands_enabled'] && $registered->fetchColumn() !== false) {
                $this->enqueue('move:' . $id . ':' . $userId, 'move', ['session_id' => $id, 'user_id' => $userId]);
                $ok = $this->dispatch('move:' . $id . ':' . $userId, fn(string $key) =>
                    $this->presenceError($state) === null && ($atomic?$this->discord->moveMemberIfUnchanged($this->config['guild_id'], $userId, $destination, $fence, $key)
                    :$this->discord->moveMemberGuarded($this->config['guild_id'],$userId,$destination,$fence,$key)));
                if (!$ok) { $pending[$userId] = 'move_pending'; }
            } else {
                $this->enqueue('disconnect:' . $id . ':' . $userId, 'disconnect', ['session_id' => $id, 'user_id' => $userId]);
                $ok = $this->dispatch('disconnect:' . $id . ':' . $userId, fn(string $key) =>
                    $this->presenceError($state) === null && ($atomic?$this->discord->disconnectMemberIfUnchanged($this->config['guild_id'], $userId, $fence, $key)
                    :$this->discord->disconnectMemberGuarded($this->config['guild_id'],$userId,$fence,$key)));
                if (!$ok) { $pending[$userId] = 'disconnect_pending'; }
            }
        }
        // Recheck completeness. Newly arrived, re-entered, or unknown members keep closure pending.
        $remaining = $this->discord->roster($this->config['guild_id'], $this->config['voice_channel_id']);
        $remainingError = $this->rosterError($remaining);
        if ($remainingError !== null) { return $this->result(false, $remainingError, ['session_id' => $id]); }
        foreach ($remaining->memberIds as $userId) {
            $error = $this->presenceError($this->discord->voiceState($this->config['guild_id'], (string)$userId));
            if ($error !== 'not_in_voice') { $pending[(string)$userId] ??= $error ?? 'member_still_in_voice'; }
        }
        if ($pending !== []) { return $this->result(false, 'closure_pending', ['session_id' => $id, 'pending' => $pending]); }
        $this->db->transaction(fn(PDO $pdo) => $pdo->prepare("UPDATE sessions SET status='closed',closed_at=? WHERE id=?")->execute([$this->now(), $id]));
        return $this->result(true, 'closed', ['session_id' => $id]);
    }

    /** An announcement that never applied must not trigger unannounced member actions. Close only the gate. */
    private function closeExpiredUnsentReceipt(string $id):array
    {
        $state=$this->db->transaction(function(PDO $pdo)use($id):string{
            $session=$this->session($pdo,$id);if($session===null||$session['status']==='closed'){return 'closed';}
            $newer=$pdo->prepare('SELECT 1 FROM sessions WHERE scheduled_at>? AND opens_at<=? LIMIT 1');$newer->execute([$session['scheduled_at'],$this->now()]);
            if($newer->fetchColumn()!==false){
                // Definite no-send, already past its original cutoff: A2 can finish without any gate/member action.
                $pdo->prepare("UPDATE sessions SET status='closed',closed_at=? WHERE id=?")->execute([$this->now(),$id]);return 'superseded';
            }
            if(($bindingError=$this->sessionBindingError($session))!==null){return $bindingError;}
            if ($this->sessionScheduleConflict($pdo,$session,$this->now())) { return 'conflict'; }
            $pdo->prepare("UPDATE sessions SET status='closing' WHERE id=?")->execute([$id]);return 'close_gate';
        });
        if($state!=='close_gate'){
            if(in_array($state,['slot_binding_required','session_slot_binding_conflict'],true)){return $this->result(false,$state,['session_id'=>$id]);}
            return $this->result($state!=='conflict',$state==='conflict'?'session_schedule_conflict':($state==='superseded'?'receipt_expired_superseded':'closed'),['session_id'=>$id]);
        }
        $key='close-room:'.$id;$this->enqueue($key,'room_close',['session_id'=>$id]);
        if(!$this->dispatch($key,fn(string $operation):bool=>$this->discord->setRoomOpen($this->config['voice_channel_id'],false,$operation))){return $this->result(false,'receipt_expired_room_close_pending',['session_id'=>$id]);}
        $this->db->transaction(fn(PDO $pdo)=>$pdo->prepare("UPDATE sessions SET status='closed',closed_at=? WHERE id=?")->execute([$this->now(),$id]));
        return $this->result(true,'receipt_expired_closed',['session_id'=>$id]);
    }

    /**
     * Legacy rows can survive a schedule replacement. Live intervals conflict in both directions,
     * including a future opening before a promised receipt cutoff. An expired old row cannot act
     * after any newer opening, but it does not prevent a current row from making progress.
     * Never rewrite an existing deadline or silently close a conflicted receipt.
     */
    private function sessionScheduleConflict(PDO $pdo, array $session, int $now, ?int $admissionCloses = null): bool
    {
        // A different worker can complete closure while this worker is outside the DB in roster I/O.
        // Public entry points already handle closed replay; remaining pending effects must stop.
        if ($session['status']==='closed') { return true; }
        $due=$admissionCloses ?? (int)($session['closes_at'] ?? $session['unstarted_closes_at']);
        if ($due<=$now) {
            // Ordinary never-opened missed sessions expire without producing any room/member effect.
            if ($session['status']==='planned' && $session['started_at']===null) { return false; }
            $query=$pdo->prepare('SELECT 1 FROM sessions WHERE id!=? AND scheduled_at>? AND opens_at<=? LIMIT 1');
            $query->execute([$session['id'],$session['scheduled_at'],$now]);
        } else {
            if ($admissionCloses!==null) {
                // Legacy start() lasts 900 seconds. Protect its actual deadline even when a future
                // configured session has not yet been materialized by the periodic planner.
                $from=new DateTimeImmutable('@'.($now-1800));$to=new DateTimeImmutable('@'.($due+1800));
                foreach ($this->schedule->between($from,$to) as $scheduled) {
                    if ($scheduled->format('Y-m-d_Hi')!==$session['id'] && $scheduled->getTimestamp()-1800<$due
                        && $scheduled->getTimestamp()+1800>$now && $this->session($pdo,$scheduled->format('Y-m-d_Hi'))===null) { return true; }
                }
            }
            $sql="SELECT 1 FROM sessions WHERE id!=? AND status!='closed' AND opens_at<? AND COALESCE(closes_at,unstarted_closes_at)>CAST(? AS INTEGER)";
            $params=[$session['id'],$due,$now];$first=$this->schedule->firstSessionAt();
            if($first!==null){$sql.=' AND scheduled_at>=?';$params[]=$first;}
            $query=$pdo->prepare($sql.' LIMIT 1');$query->execute($params);
        }
        return $query->fetchColumn()!==false;
    }

    private function ensureOpen(PDO $pdo, array $session): bool
    {
        // Do not reopen an already closing room while retrying a failed member action.
        if ($session['status'] === 'closing') { return true; }
        $id = $session['id'];
        $this->enqueueEffect($pdo, 'open-room:' . $id, 'room_open', ['session_id' => $id]);
        $this->enqueueEffect($pdo, 'guide:' . $id, 'guide', ['session_id' => $id]);
        if($this->operationsMode){
            $this->enqueueEffect($pdo,OpeningNoticeMessage::key($id),'opening_notice',OpeningNoticeMessage::snapshot($session),(int)$session['scheduled_at']);
        }elseif ($this->now() >= (int)$session['opens_at'] && $this->now() < (int)$session['scheduled_at']) {
            $this->enqueueEffect($pdo, 'reminder:' . $id, 'reminder', ['session_id' => $id], (int)$session['scheduled_at']);
        }
        if ($session['status'] === 'planned') { $pdo->prepare("UPDATE sessions SET status='open' WHERE id=?")->execute([$id]); }
        return true;
    }

    private function flushOpening(string $id): bool
    {
        $session = $this->session($this->db->pdo(), $id);
        if ($session === null || in_array($session['status'], ['closed', 'closing'], true)) { return true; }
        $reminder = true;
        if (!$this->operationsMode && $this->now() >= (int)$session['opens_at'] && $this->now() < (int)$session['scheduled_at']) {
            $scheduled = (new DateTimeImmutable('@' . $session['scheduled_at']))->setTimezone($this->timezone);
            $content = "あと30分でラジオ体操だよー！⚡\n予定時刻: " . $scheduled->format('Y-m-d H:i') . ' JST';
            $reminder = $this->dispatch('reminder:' . $id, fn(string $key) => $this->discord->sendMessage($this->config['text_channel_id'], $content, $key));
        }
        $opened = $this->dispatch('open-room:' . $id, fn(string $key) => $this->discord->setRoomOpen($this->config['voice_channel_id'], true, $key));
        if (!$opened) { return false; }
        // Notification failure/hold must not prevent the existing VC guide or participant flow.
        if($this->operationsMode){$this->flushOpeningNotice($id);}
        if ($this->config['participant_commands_enabled']) {
            if ($session['started_at'] !== null) { return $reminder; }
            $guide=$this->dispatch('guide:'.$id,fn(string $key)=>$this->discord->sendMessage($this->config['voice_channel_id'],
                "会場を開きました。Watch Togetherで動画を手動再生して、第1・第2を1セットで体操してください。\n体操後に在室中の誰か1人が /radio-attend を実行すると、共有の参加受付を10分間開きます。全員が自分で参加ボタンを押してください。\nhttps://www.youtube.com/watch?v=RNlB93CuwOI",$key));
            return $guide && $reminder;
        }
        // The attached VC chat uses voice_channel_id; the text reminder above is separate.
        $guide = $this->dispatch('guide:' . $id, fn(string $key) =>
            $this->discord->sendMessage($this->config['voice_channel_id'], "会場を開きました。動画を開く→再生→体操開始ボタン→参加ボタン\nWatch Together で参加者が手動再生してください。https://www.youtube.com/watch?v=RNlB93CuwOI\n第1・第2を1セットとして、この開催への参加は1回記録できます。", $key, ButtonBuilder::sessionControls($id)));
        return $guide && $reminder;
    }

    /** pending+non-null owner is in-flight/held forever, never reclaimed by a lease timeout. */
    private function flushOpeningNotice(string $id):void
    {
        if(!$this->operationsMode||!$this->discord instanceof OpeningNoticeGateway){return;}
        $key=OpeningNoticeMessage::key($id);$owner=bin2hex(random_bytes(16));
        try{
            $peek=$this->session($this->db->pdo(),$id);$now=$this->now();
            if($peek===null||in_array($peek['status'],['planned','closing','closed'],true)||$now<(int)$peek['scheduled_at']-600||$now>=(int)$peek['scheduled_at']){return;}
            $q=$this->db->pdo()->prepare('SELECT status,cancelled_at,lease_owner,attempts,lease_until FROM core_effects WHERE effect_key=?');$q->execute([$key]);$pending=$q->fetch();$q->closeCursor();
            if($pending===false||$pending['status']!=='pending'||$pending['cancelled_at']!==null||$pending['lease_owner']!==null||(int)$pending['attempts']>=5||(int)$pending['lease_until']>$now){return;}
            // A closed/unverifiable room keeps its notification pending without spending send attempts.
            if(!$this->discord->openingRoomVerified()){return;}
            $snapshot=$this->db->transaction(function(PDO $pdo)use($id,$key,$owner):?array{
                $session=$this->session($pdo,$id);$now=$this->now();
                if($session===null||in_array($session['status'],['planned','closing','closed'],true)||$this->sessionBindingError($session)!==null
                    ||$this->sessionScheduleConflict($pdo,$session,$now)||$now<(int)$session['scheduled_at']-600||$now>=(int)$session['scheduled_at']){return null;}
                $q=$pdo->prepare('SELECT * FROM core_effects WHERE effect_key=?');$q->execute([$key]);$effect=$q->fetch();$q->closeCursor();
                if($effect===false||$effect['kind']!=='opening_notice'||$effect['status']!=='pending'||$effect['cancelled_at']!==null
                    ||$effect['lease_owner']!==null||(int)$effect['attempts']>=5||(int)$effect['lease_until']>$now
                    ||(int)$effect['expires_at']!==(int)$session['scheduled_at']){return null;}
                $snapshot=OpeningNoticeMessage::snapshot($session);
                if(json_decode($effect['payload_json'],true,32,JSON_THROW_ON_ERROR)!==$snapshot){return null;}
                $q=$pdo->prepare("SELECT 1 FROM core_effects WHERE effect_key=? AND kind='room_open' AND status='done' AND cancelled_at IS NULL");$q->execute(['open-room:'.$id]);$opened=$q->fetchColumn()!==false;$q->closeCursor();if(!$opened){return null;}
                // A previous-version reminder might already have arrived. Preserve it; do not add another message.
                $q=$pdo->prepare('SELECT status,attempts,last_attempt_at FROM core_effects WHERE effect_key=?');$q->execute(['reminder:'.$id]);$legacy=$q->fetch();$q->closeCursor();
                if($legacy!==false&&($legacy['status']==='done'||(int)$legacy['attempts']>0||$legacy['last_attempt_at']!==null)){
                    $pdo->prepare('UPDATE core_effects SET lease_owner=?,lease_until=0 WHERE effect_key=? AND lease_owner IS NULL')->execute([$owner,$key]);return null;
                }
                $q=$pdo->prepare('UPDATE core_effects SET lease_owner=?,lease_until=0,attempts=attempts+1,last_attempt_at=? WHERE effect_key=? AND lease_owner IS NULL');$q->execute([$owner,$now,$key]);
                return $q->rowCount()===1?$snapshot:null;
            });
            if($snapshot===null){return;}
            $delivery=$this->discord->sendOpeningNoticeResult($snapshot,$key);
            $success=$delivery->ok&&$delivery->outcome===TransportOutcome::Succeeded
                &&is_string($delivery->data['message_id']??null)&&preg_match('/\A[1-9][0-9]{16,19}\z/D',$delivery->data['message_id'])===1;
            if(!$success&&!$delivery->outcome->retryable()){return;} // Keep the owner: possibly delivered, never resend.
            $this->db->transaction(function(PDO $pdo)use($key,$owner,$delivery,$success):void{
                $q=$pdo->prepare('SELECT attempts FROM core_effects WHERE effect_key=? AND lease_owner=?');$q->execute([$key,$owner]);$attempts=$q->fetchColumn();$q->closeCursor();if($attempts===false){return;}
                $delay=max(min(3600,(int)ceil($delivery->retryAfter??0)),5*(2**max(0,(int)$attempts-1)));
                $q=$pdo->prepare("UPDATE core_effects SET status=?,completed_at=?,lease_owner=NULL,lease_until=?,cancelled_at=CASE WHEN ? THEN ? ELSE cancelled_at END WHERE effect_key=? AND lease_owner=? AND status='pending' AND cancelled_at IS NULL");
                $q->execute([$success?'done':'pending',$success?$this->now():null,$success?0:$this->now()+$delay,!$success&&(int)$attempts>=5?1:0,$this->now(),$key,$owner]);
            });
        }catch(\Throwable){/* A send/save exception leaves the owner as an explicit hold; a failed preclaim made no request. */}
    }

    private function syncRoles(): void
    {
        $transport=($this->discord instanceof AtomicMemberGateway&&$this->discord->supportsConditionalMemberActions())||($this->operationsMode&&$this->discord instanceof RoleGateway&&$this->discord->supportsRoleSync());
        if (($this->config['member_actions_enabled'] !== true&&!($this->operationsMode&&$this->config['roles_enabled'])) || $this->config['role_ids'] === [] || !$transport) { return; }
        foreach ($this->db->pdo()->query('SELECT user_id,total_count FROM participants WHERE total_count>0')->fetchAll() as $user) {
                [$threshold] = TitlePolicy::forCount((int)$user['total_count']);
                $roleId = $this->config['role_ids'][$threshold] ?? null;
                if (!is_string($roleId) || $roleId === '') { continue; }
                $userId = $user['user_id'];
                $effectKey = 'role-count-v2:' . $userId . ':' . $threshold;
                $this->enqueue($effectKey, 'decoration_role', ['user_id' => $userId, 'threshold' => $threshold]);
                $this->dispatch($effectKey, function (string $key) use ($userId, $threshold, $roleId): bool {
                    $current = $this->participant($this->db->pdo(), $userId);
                    if (TitlePolicy::forCount((int)($current['total_count'] ?? 0))[0] !== $threshold) { return true; }
                    if (!$this->discord->syncDecorationRole($this->config['guild_id'], $userId, $roleId, $key)) { return false; }
                    $this->recordAchievementGrant($userId, $threshold, (int)$current['total_count']);
                    return true;
                });
        }
    }

    private function recordAchievementGrant(string $userId, int $threshold, int $totalCount): void
    {
        if ($this->config['achievement_notifications_enabled'] !== true || !$this->discord instanceof AchievementGateway
            || preg_match('/\A[1-9][0-9]{16,19}\z/D', $userId) !== 1 || !isset(TitlePolicy::TITLES[$threshold])) { return; }
        $this->db->transaction(fn(PDO $pdo) => $pdo->prepare("INSERT OR IGNORE INTO achievement_notices(user_id,threshold,total_count,status,created_at) VALUES (?,?,?,'pending',?)")
            ->execute([$userId, $threshold, $totalCount, $this->now()]));
    }

    /** Retry only proven unapplied outcomes; ambiguous delivery and crashes remain held across restart. */
    private function flushAchievementNotices(): void
    {
        if ($this->config['achievement_notifications_enabled'] !== true || !$this->discord instanceof AchievementGateway) { return; }
        $rows = $this->db->pdo()->query("SELECT user_id,threshold,total_count,attempts FROM achievement_notices WHERE status='pending' AND retry_stopped_at IS NULL AND attempts<5 ORDER BY created_at,user_id,threshold LIMIT 50")->fetchAll();
        foreach ($rows as $row) {
            $owner=bin2hex(random_bytes(16));
            $claimed = $this->db->transaction(function (PDO $pdo) use ($row,$owner): bool {
                $claim = $pdo->prepare("UPDATE achievement_notices SET status='sending',attempted_at=?,claim_owner=?,attempts=attempts+1 WHERE user_id=? AND threshold=? AND status='pending' AND retry_stopped_at IS NULL AND attempts<5 AND attempts=?");
                $claim->execute([$this->now(),$owner,$row['user_id'],$row['threshold'],$row['attempts']]);
                return $claim->rowCount() === 1;
            });
            if (!$claimed) { continue; }
            $ok = false;$outcome=TransportOutcome::Uncertain;
            try {
                if($this->discord instanceof DeliveryAwareGateway){
                    $delivery=$this->discord->sendAchievementResult($this->config['records_channel_id'],$row['user_id'],(int)$row['threshold'],(int)$row['total_count'],'achievement:'.$row['user_id'].':'.$row['threshold']);
                    $ok=$delivery->ok;$outcome=$delivery->outcome;
                }else{
                    $ok = $this->discord->sendAchievement($this->config['records_channel_id'], $row['user_id'], (int)$row['threshold'],
                        (int)$row['total_count'], 'achievement:' . $row['user_id'] . ':' . $row['threshold']);
                    $outcome=$ok?TransportOutcome::Succeeded:TransportOutcome::Uncertain;
                }
            } catch (\Throwable) { /* Delivery is uncertain: never blindly send the mention again. */ }
            $retryStopped=!$ok&&$outcome->retryable()&&(int)$row['attempts']+1>=5?$this->now():null;
            $this->db->transaction(fn(PDO $pdo) => $pdo->prepare("UPDATE achievement_notices SET status=?,sent_at=?,claim_owner=NULL,retry_stopped_at=COALESCE(?,retry_stopped_at) WHERE user_id=? AND threshold=? AND status='sending' AND claim_owner=?")
                ->execute([$ok?'sent':($outcome->retryable()?'pending':'uncertain'),$ok?$this->now():null,$retryStopped,$row['user_id'],$row['threshold'],$owner]));
        }
    }

    private function noticeOwner(): ?string
    {
        $owner=$this->config['owner_user_id']??null;
        return is_string($owner)&&preg_match('/\A[1-9][0-9]{16,19}\z/D',$owner)===1?$owner:null;
    }

    /** New day grants only. Historical attendance and count awards are never backfilled into this outbox. */
    private function flushDayAchievementNotices(): void
    {
        if($this->config['achievement_notifications_enabled']!==true||!$this->discord instanceof DayAchievementGateway){return;}
        $owner=$this->noticeOwner();
        // Unresolved/changed special-award owners and retired milestones must not starve other recipients.
        $query=$this->db->pdo()->prepare("SELECT user_id,milestone,total_days FROM day_achievement_notices WHERE status='pending' AND attempts<5 AND milestone IN (".implode(',',ParticipationDays::MILESTONES).") AND (milestone<>365 OR (? IS NOT NULL AND (owner_user_id IS NULL OR owner_user_id=?))) ORDER BY created_at,user_id,milestone LIMIT 50");
        $query->execute([$owner,$owner]);$rows=$query->fetchAll();$query->closeCursor();
        foreach($rows as$row){
            if(!in_array((int)$row['milestone'],ParticipationDays::MILESTONES,true)){continue;}
            $claimOwner=bin2hex(random_bytes(16));
            $claimed=$this->db->transaction(function(PDO $pdo)use($row,$owner,$claimOwner):?array{
                $query=$pdo->prepare("SELECT * FROM day_achievement_notices WHERE user_id=? AND milestone=? AND status='pending' AND attempts<5");$query->execute([$row['user_id'],$row['milestone']]);$notice=$query->fetch();$query->closeCursor();
                if($notice===false){return null;}
                if((int)$row['milestone']===365){
                    if($owner===null){return null;}
                    // Snapshot an explicitly supplied owner before the first attempt; never retarget a retry.
                    if($notice['owner_user_id']===null&&(int)$notice['attempts']===0){$notice['owner_user_id']=$owner;}
                    if($notice['owner_user_id']!==$owner){return null;}
                }
                $pdo->prepare("UPDATE day_achievement_notices SET status='sending',attempted_at=?,claim_owner=?,owner_user_id=?,attempts=attempts+1 WHERE user_id=? AND milestone=? AND status='pending' AND attempts<5")
                    ->execute([$this->now(),$claimOwner,$notice['owner_user_id'],$row['user_id'],$row['milestone']]);
                return $notice;
            });
            if($claimed===null){continue;}
            $ok=false;$outcome=TransportOutcome::Uncertain;
            try{
                $result=$this->discord->sendDayAchievementResult($this->config['records_channel_id'],$row['user_id'],(int)$row['milestone'],(int)$row['total_days'],$claimed['owner_user_id'],'day-achievement:'.$row['user_id'].':'.$row['milestone']);
                $ok=$result->ok;$outcome=$result->outcome;
            }catch(\Throwable){/* An attempted mention may have arrived; preserve its uncertainty. */}
            $status=$ok?'sent':($outcome->retryable()?((int)$claimed['attempts']+1<5?'pending':'failed'):'uncertain');
            $this->db->transaction(fn(PDO $pdo)=>$pdo->prepare("UPDATE day_achievement_notices SET status=?,sent_at=?,claim_owner=NULL WHERE user_id=? AND milestone=? AND status='sending' AND claim_owner=?")
                ->execute([$status,$ok?$this->now():null,$row['user_id'],$row['milestone'],$claimOwner]));
        }
    }

    private function interaction(string $operation, string $sessionId, string $userId, string $interactionId, callable $action): array
    {
        if ($sessionId === '' || $userId === '' || $interactionId === '' || strlen($interactionId) > 200 || strlen($userId) > 200 || strlen($sessionId) > 100) {
            return $this->result(false, 'invalid_request');
        }
        return $this->db->transaction(function (PDO $pdo) use ($operation, $sessionId, $userId, $interactionId, $action): array {
            $query = $pdo->prepare('SELECT operation,session_id,user_id,result_json FROM core_interactions WHERE id=?');
            $query->execute([$interactionId]);
            $existing = $query->fetch();
            if ($existing !== false) {
                if ($existing['operation'] !== $operation || $existing['session_id'] !== $sessionId || $existing['user_id'] !== $userId) {
                    return $this->result(false, 'interaction_conflict');
                }
                return json_decode($existing['result_json'], true, 32, JSON_THROW_ON_ERROR);
            }
            $result = $action($pdo);
            $pdo->prepare('INSERT INTO core_interactions(id,operation,session_id,user_id,result_json,created_at) VALUES (?,?,?,?,?,?)')
                ->execute([$interactionId, $operation, $sessionId, $userId, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $this->now()]);
            return $result;
        });
    }

    private function enqueue(string $key, string $kind, array $payload): void
    {
        $this->db->transaction(fn(PDO $pdo) => $this->enqueueEffect($pdo, $key, $kind, $payload));
    }

    private function enqueueEffect(PDO $pdo, string $key, string $kind, array $payload, ?int $expiresAt = null): void
    {
        if ($this->adminValidationMode) { throw new \LogicException('Public effects are disabled during administrator validation.'); }
        $pdo->prepare("INSERT OR IGNORE INTO core_effects(effect_key,kind,payload_json,status,expires_at) VALUES (?,?,?,'pending',?)")
            ->execute([$key, $kind, json_encode($payload, JSON_THROW_ON_ERROR), $expiresAt]);
    }

    private function expireReminders(): void
    {
        $this->db->transaction(function (PDO $pdo): void {
            $now = $this->now();
            if($this->schedule->firstSessionAt()!==null){
                $q=$pdo->prepare("SELECT effect_key,payload_json FROM core_effects WHERE status='pending' AND cancelled_at IS NULL AND expires_at<=?");$q->execute([$now]);$expired=$q->fetchAll();$q->closeCursor();
                $update=$pdo->prepare("UPDATE core_effects SET cancelled_at=?,lease_until=0,lease_owner=NULL WHERE effect_key=? AND status='pending' AND cancelled_at IS NULL AND expires_at<=?");
                foreach($expired as$effect){$payload=json_decode($effect['payload_json'],true);$id=is_array($payload)?($payload['session_id']??null):null;
                    if(is_string($id)&&$this->schedule->isBeforeStart($id)){continue;}$update->execute([$now,$effect['effect_key'],$now]);}
                return;
            }
            $pdo->prepare("UPDATE core_effects SET cancelled_at=?,lease_until=0,lease_owner=NULL WHERE status='pending' AND cancelled_at IS NULL AND expires_at<=?")
                ->execute([$now, $now]);
        });
    }

    /** Exclude retained pre-launch rows from the cooperative worker's need-to-poll query. */
    public function scheduledWorkDueBy(int $timestamp):bool
    {
        $sql="SELECT 1 FROM sessions WHERE status!='closed' AND opens_at<=?";$params=[$timestamp];$first=$this->schedule->firstSessionAt();
        if($first!==null){$sql.=' AND scheduled_at>=?';$params[]=$first;}
        $q=$this->db->pdo()->prepare($sql.' LIMIT 1');$q->execute($params);return $q->fetchColumn()!==false;
    }

    /** Claim briefly, release the write lock, then invoke the gateway. No network under SQLite locks. */
    private function dispatch(string $key, callable $send): bool
    {
        if ($this->adminValidationMode) { return false; }
        $owner = bin2hex(random_bytes(12));
        $expiresAt = null;
        $sessionId = null;
        $claimed = $this->db->transaction(function (PDO $pdo) use ($key, $owner, &$expiresAt, &$sessionId): string {
            $query = $pdo->prepare('SELECT status,lease_until,expires_at,cancelled_at,payload_json FROM core_effects WHERE effect_key=?');
            $query->execute([$key]);
            $effect = $query->fetch();
            if ($effect === false) { return 'missing'; }
            if ($effect['status'] === 'done') { return 'done'; }
            if ($effect['cancelled_at'] !== null) { return 'expired'; }
            $expiresAt = $effect['expires_at'] === null ? null : (int)$effect['expires_at'];
            if ($expiresAt !== null && $this->now() >= $expiresAt) {
                $pdo->prepare('UPDATE core_effects SET cancelled_at=?,lease_until=0,lease_owner=NULL WHERE effect_key=?')->execute([$this->now(), $key]);
                return 'expired';
            }
            $payload=json_decode($effect['payload_json'],true,32,JSON_THROW_ON_ERROR);
            $sessionId=$payload['session_id']??null;
            if(is_string($sessionId)){
                $session=$this->session($pdo,$sessionId);
                if($session===null||$this->sessionBindingError($session)!==null||$this->sessionScheduleConflict($pdo,$session,$this->now())){return 'conflict';}
            }
            if ((int)$effect['lease_until'] > $this->now()) { return 'busy'; }
            $pdo->prepare('UPDATE core_effects SET lease_owner=?,lease_until=?,attempts=attempts+1,last_attempt_at=? WHERE effect_key=?')
                ->execute([$owner, $this->now() + 30, $this->now(), $key]);
            return 'claimed';
        });
        if ($claimed !== 'claimed') { return $claimed === 'done'; }
        if ($expiresAt !== null && $this->now() >= $expiresAt) {
            $this->db->transaction(fn(PDO $pdo) => $pdo->prepare('UPDATE core_effects SET cancelled_at=?,lease_until=0,lease_owner=NULL WHERE effect_key=? AND lease_owner=?')->execute([$this->now(), $key, $owner]));
            return false;
        }
        try {
            // Recheck after releasing the claim lock: a previous I/O or worker may have crossed an opening.
            $session=is_string($sessionId)?$this->session($this->db->pdo(),$sessionId):null;
            $success=(!is_string($sessionId)||($session!==null&&$this->sessionBindingError($session)===null&&!$this->sessionScheduleConflict($this->db->pdo(),$session,$this->now())))&&$send($key)===true;
        } catch (\Throwable) { $success = false; }
        return $this->db->transaction(function (PDO $pdo) use ($key, $owner, $success): bool {
            $update = $pdo->prepare('UPDATE core_effects SET status=?,completed_at=?,lease_until=0,lease_owner=NULL WHERE effect_key=? AND lease_owner=? AND cancelled_at IS NULL');
            $update->execute([$success ? 'done' : 'pending', $success ? $this->now() : null, $key, $owner]);
            return $success && $update->rowCount() === 1;
        });
    }

    private function validationUserAllowed(string $userId): bool
    {
        if($this->operationsMode){return \RadioStamp\Operations\ParticipantScope::allows($this->config,$userId);}
        if (!$this->adminValidationMode) { return true; }
        return in_array($userId, $this->config['admin_user_ids'], true)
            && $this->discord instanceof AdminValidationGateway
            && $this->discord->permitsValidationUser($this->config['guild_id'], $userId);
    }

    private function interactionReplay(string $operation, string $sessionId, string $userId, string $interactionId): ?array
    {
        if ($sessionId === '' || $userId === '' || $interactionId === '' || strlen($interactionId) > 200 || strlen($sessionId) > 100 || strlen($userId)>200) {
            return $this->result(false, 'invalid_request');
        }
        $query = $this->db->pdo()->prepare('SELECT operation,session_id,user_id,result_json FROM core_interactions WHERE id=?');
        $query->execute([$interactionId]);
        $existing = $query->fetch();
        if ($existing === false) { return null; }
        if ($existing['operation'] !== $operation || $existing['session_id'] !== $sessionId || $existing['user_id'] !== $userId) {
            return $this->result(false, 'interaction_conflict');
        }
        return json_decode($existing['result_json'], true, 32, JSON_THROW_ON_ERROR);
    }

    /** Prepare only a scheduled local validation slot; never call tick or emit effects. */
    private function prepareValidationSession(string $sessionId): ?array
    {
        if (!$this->schedule->contains($sessionId)) {
            return $this->result(false, 'invalid_request');
        }
        $scheduled = DateTimeImmutable::createFromFormat('!Y-m-d_Hi', $sessionId, $this->timezone);
        if ($scheduled === false) { return $this->result(false, 'invalid_request'); }
        $existing = $this->session($this->db->pdo(), $sessionId);
        $opensAt = (int)($existing['opens_at'] ?? ($scheduled->getTimestamp() - 1800));
        $closesAt = (int)($existing['closes_at'] ?? $existing['unstarted_closes_at'] ?? ($scheduled->getTimestamp() + 1800));
        if ($this->now() < $opensAt) { return $this->result(false, 'session_not_open'); }
        if ($this->now() >= $closesAt || in_array($existing['status'] ?? null, ['closed', 'closing'], true)) {
            return $this->result(false, 'session_closed');
        }
        if ($existing === null) { $this->planSessions($scheduled, $scheduled); }
        return null;
    }

    private function presenceError(VoiceState $state): ?string
    {
        if (!$state->known) { return 'voice_unknown'; }
        if (!$this->fresh($state->observedAt)) { return 'voice_stale'; }
        return $state->channelId === $this->config['voice_channel_id'] ? null : 'not_in_voice';
    }

    private function rosterError(VoiceRoster $roster): ?string
    {
        if (!$roster->known) { return 'voice_roster_unknown'; }
        return $this->fresh($roster->observedAt) ? null : 'voice_roster_stale';
    }

    private function fresh(int $observedAt): bool
    {
        $age = $this->now() - $observedAt;
        return $age >= 0 && $age <= $this->config['max_roster_age_seconds'];
    }

    private function session(PDO $pdo, string $id): ?array
    {
        $query = $pdo->prepare('SELECT * FROM sessions WHERE id=?');
        $query->execute([$id]);
        $result = $query->fetch();
        return $result === false ? null : $result;
    }

    private function participant(PDO $pdo, string $userId): ?array
    {
        $query = $pdo->prepare('SELECT * FROM participants WHERE user_id=?');
        $query->execute([$userId]);
        $result = $query->fetch();
        return $result === false ? null : $result;
    }

    private function titleFor(int $total): array
    {
        return TitlePolicy::forCount($total);
    }

    private function now(): int { return $this->clock->now()->getTimestamp(); }
    private function result(bool $ok, string $code, array $details = []): array { return ['ok' => $ok, 'code' => $code] + $details; }
}
