<?php
declare(strict_types=1);
namespace RadioStamp\Http;

/** Fixed operations only; nothing in this API accepts a shell command or URL. */
interface ValidationRuntime
{
    public function runInteraction(array $config, string $interactionId, string $privateRoot, string $publicRoot): array;
    public function registerCommands(array $config): array;
    public function inlineCheck(array $config, float $deadline): array;
}
