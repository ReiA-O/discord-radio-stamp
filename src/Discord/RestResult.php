<?php
declare(strict_types=1);
namespace RadioStamp\Discord;

/** Error responses never retain Discord bodies, paths, request headers, or credentials. */
final readonly class RestResult
{
    public TransportOutcome $outcome;
    public function __construct(public bool $ok, public string $code, public int $status = 0, public array $data = [], public float $retryAfter = 0.0, public bool $syntheticStatus = false,?TransportOutcome $outcome=null)
    {
        $this->outcome=$outcome??($ok?TransportOutcome::Succeeded:TransportOutcome::Uncertain);
    }
}
