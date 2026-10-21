<?php
declare(strict_types=1);
namespace RadioStamp\Core;

use DateTimeImmutable;
use DateTimeZone;

/** The only scheduled-time definition; logical slots survive an explicit time replacement. */
final class Schedule
{
    public const DEFAULT_SLOT_TIMES = ['midnight'=>'00:00','dawn'=>'04:00','morning'=>'07:00','midday'=>'12:30','night'=>'22:00'];
    public const DEFAULT_SPEC = ['daily_times'=>[self::DEFAULT_SLOT_TIMES['midnight'],self::DEFAULT_SLOT_TIMES['dawn'],self::DEFAULT_SLOT_TIMES['morning'],self::DEFAULT_SLOT_TIMES['midday'],self::DEFAULT_SLOT_TIMES['night']],'one_off_times'=>[]];
    /** Frozen actual administrator-validation/OneJoin compatibility, never an operations default. */
    public const LEGACY_VALIDATION_SPEC = ['daily_times'=>['00:00','04:00','07:00','22:00'],'one_off_times'=>[]];
    public const MIN_INTERVAL_SECONDS = 70 * 60;
    private array $daily=[];
    private array $oneOff=[];
    private array $replacements=[];
    private ?int $firstSessionAt=null;
    private DateTimeZone $timezone;

    public function __construct(array $spec = self::DEFAULT_SPEC)
    {
        $this->timezone=new DateTimeZone('Asia/Tokyo');
        if(array_diff(array_keys($spec),['daily_times','one_off_times','not_before'])!==[]){throw new \InvalidArgumentException('schedule_invalid');}
        if(array_key_exists('not_before',$spec)){
            $value=$spec['not_before'];$at=is_string($value)?DateTimeImmutable::createFromFormat('!Y-m-d H:i',$value,$this->timezone):false;
            if(!is_string($value)||strlen($value)!==16||$at===false||$at->format('Y-m-d H:i')!==$value||!self::validSessionId($at->format('Y-m-d_Hi'))){throw new \InvalidArgumentException('schedule_invalid');}
            $this->firstSessionAt=$at->getTimestamp();
        }
        $daily=$spec['daily_times']??[];$dates=$spec['one_off_times']??[];
        if(!is_array($daily)||!array_is_list($daily)||!is_array($dates)||!array_is_list($dates)||($daily===[]&&$dates===[])){throw new \InvalidArgumentException('schedule_invalid');}
        $dailySlots=[];
        foreach($daily as$value){
            if(is_array($value)){
                if(count($value)!==2||array_diff(array_keys($value),['time','slot_id'])!==[]||!is_string($value['slot_id']??null)){throw new \InvalidArgumentException('schedule_invalid');}
                $time=$value['time']??null;$slot=$value['slot_id'];StampPolicy::stampForSlot($slot);
            }else{$time=$value;$slot=is_string($time)?StampPolicy::defaultSlotForTime($time):null;}
            if(!is_string($time)||preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/D',$time)!==1){throw new \InvalidArgumentException('schedule_invalid');}
            if(array_key_exists($time,$this->daily)&&$this->daily[$time]!==$slot){throw new \InvalidArgumentException('schedule_slot_conflict');}
            if($slot!==null&&isset($dailySlots[$slot])&&$dailySlots[$slot]!==$time){throw new \InvalidArgumentException('schedule_slot_conflict');}
            $this->daily[$time]=$slot;if($slot!==null){$dailySlots[$slot]=$time;}
        }
        ksort($this->daily);
        foreach($dates as$value){
            if(is_array($value)){
                if(count($value)!==2||array_diff(array_keys($value),['at','slot_id'])!==[]||!is_string($value['slot_id']??null)){throw new \InvalidArgumentException('schedule_invalid');}
                $text=$value['at']??null;$slot=$value['slot_id'];StampPolicy::stampForSlot($slot);
            }else{$text=$value;$slot=null;}
            if(!is_string($text)||strlen($text)!==16){throw new \InvalidArgumentException('schedule_invalid');}
            $at=DateTimeImmutable::createFromFormat('!Y-m-d H:i',$text,$this->timezone);
            if($at===false||$at->format('Y-m-d H:i')!==$text||!self::validSessionId($at->format('Y-m-d_Hi'))){throw new \InvalidArgumentException('schedule_invalid');}
            $id=$at->format('Y-m-d_Hi');$date=$at->format('Y-m-d');
            if($slot!==null){
                if(isset($this->replacements[$date][$slot])){throw new \InvalidArgumentException('schedule_slot_conflict');}
                $this->replacements[$date][$slot]=$id;
            }
            if(isset($this->oneOff[$date][$id])&&$this->oneOff[$date][$id]['slot_id']!==$slot){throw new \InvalidArgumentException('schedule_slot_conflict');}
            $this->oneOff[$date][$id]=['at'=>$at,'slot_id'=>$slot];
        }
        ksort($this->oneOff);$this->validateSpacing();
    }
    /** An explicit replacement suppresses the original daily slot only on its own JST date. */
    private function occurrencesOnDay(DateTimeImmutable $day): array
    {
        $date=$day->format('Y-m-d');$found=[];
        foreach($this->daily as$time=>$slot){
            if($slot!==null&&isset($this->replacements[$date][$slot])){continue;}
            $at=$day->setTime((int)substr($time,0,2),(int)substr($time,3,2));
            $found[$at->format('Y-m-d_Hi')]=['at'=>$at,'slot_id'=>$slot];
        }
        foreach($this->oneOff[$date]??[]as$id=>$occurrence){
            if(isset($found[$id])){
                $existing=$found[$id]['slot_id'];$requested=$occurrence['slot_id'];
                if($existing!==null&&$requested!==null&&$existing!==$requested){throw new \InvalidArgumentException('schedule_slot_conflict');}
                // An exact duplicate literal is one occurrence, not a guessed nearby slot.
                if($requested===null){continue;}
            }
            $found[$id]=$occurrence;
        }
        if($this->firstSessionAt!==null){$found=array_filter($found,fn(array $row):bool=>$row['at']->getTimestamp()>=$this->firstSessionAt);}
        ksort($found);return $found;
    }
    private function validateSpacing(): void
    {
        $minutes=array_map(static fn(string $time):int=>(int)substr($time,0,2)*60+(int)substr($time,3,2),array_keys($this->daily));
        foreach($minutes as$index=>$minute){
            $next=$minutes[($index+1)%count($minutes)]+($index===count($minutes)-1?1440:0);
            if(($next-$minute)*60<self::MIN_INTERVAL_SECONDS){throw new \InvalidArgumentException('schedule_too_close');}
        }
        // All deviations from the daily cycle are confined to these dates and their neighbours.
        foreach(array_keys($this->oneOff)as$date){
            $day=new DateTimeImmutable($date,$this->timezone);$found=[];
            foreach([-1,0,1]as$offset){$found+=$this->occurrencesOnDay($day->modify(($offset>=0?'+':'').$offset.' day'));}
            ksort($found);$previous=null;
            foreach($found as$occurrence){
                $timestamp=$occurrence['at']->getTimestamp();
                if($previous!==null&&$timestamp-$previous<self::MIN_INTERVAL_SECONDS){throw new \InvalidArgumentException('schedule_too_close');}$previous=$timestamp;
            }
        }
    }
    public static function fromConfig(array $config): self
    {
        $default=($config['mode']??null)==='admin_validation'?self::LEGACY_VALIDATION_SPEC:self::DEFAULT_SPEC;
        $spec=$config['schedule']??$default;if(!is_array($spec)){throw new \InvalidArgumentException('schedule_invalid');}return new self($spec);
    }
    public static function validSessionId(string $id): bool
    {
        return preg_match('/\A(20[0-9]{2})-(0[1-9]|1[0-2])-([0-3][0-9])_([01][0-9]|2[0-3])([0-5][0-9])\z/D',$id,$m)===1&&checkdate((int)$m[2],(int)$m[3],(int)$m[1]);
    }
    public function firstSessionAt():?int{return $this->firstSessionAt;}
    public function isBeforeStart(string $id):bool
    {
        if($this->firstSessionAt===null||!self::validSessionId($id)){return false;}
        return DateTimeImmutable::createFromFormat('!Y-m-d_Hi',$id,$this->timezone)->getTimestamp()<$this->firstSessionAt;
    }
    public function contains(string $id): bool
    {
        if(!self::validSessionId($id)){return false;}
        $day=DateTimeImmutable::createFromFormat('!Y-m-d',substr($id,0,10),$this->timezone);return isset($this->occurrencesOnDay($day)[$id]);
    }
    public function slotForSession(string $id): ?string
    {
        if(!self::validSessionId($id)){return null;}
        $day=DateTimeImmutable::createFromFormat('!Y-m-d',substr($id,0,10),$this->timezone);return $this->occurrencesOnDay($day)[$id]['slot_id']??null;
    }
    public function bindingForSession(string $id): ?array
    {
        $slot=$this->slotForSession($id);return $slot===null?null:['slot_id'=>$slot,'stamp'=>StampPolicy::stampForSlot($slot)];
    }
    /** Inclusive instant range, sorted/deduplicated, with scheduled JST count_date unchanged. */
    public function between(DateTimeImmutable $from,DateTimeImmutable $to): array
    {
        if($to<$from){throw new \InvalidArgumentException('The planning range is reversed.');}
        $first=$from->setTimezone($this->timezone)->setTime(0,0);$last=$to->setTimezone($this->timezone)->setTime(0,0);
        if($last->getTimestamp()-$first->getTimestamp()>370*86400){throw new \InvalidArgumentException('Plan at most one year at a time.');}
        $found=[];for($day=$first;$day<=$last;$day=$day->modify('+1 day')){foreach($this->occurrencesOnDay($day)as$id=>$occurrence){$at=$occurrence['at'];if($at>=$from&&$at<=$to){$found[$id]=$at;}}}
        ksort($found);return array_values($found);
    }
    public function initialSessionAt(int $timestamp): ?string
    {
        $at=new DateTimeImmutable('@'.$timestamp);
        foreach($this->between($at->modify('-30 minutes'),$at->modify('+30 minutes'))as$scheduled){if($timestamp>=$scheduled->getTimestamp()-1800&&$timestamp<$scheduled->getTimestamp()+1800){return $scheduled->format('Y-m-d_Hi');}}
        return null;
    }
}
