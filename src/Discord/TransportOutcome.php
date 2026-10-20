<?php
declare(strict_types=1);
namespace RadioStamp\Discord;

/** Delivery knowledge, not an inference that an HTTP status means no connection was made. */
enum TransportOutcome:string
{
    case NotStarted='not_started';
    case Rejected='rejected';
    case Succeeded='succeeded';
    case Uncertain='uncertain';

    public function retryable():bool{return $this===self::NotStarted||$this===self::Rejected;}
}
