<?php
declare(strict_types=1);
namespace RadioStamp\Http;

interface PostResponseDriver
{
    public function available(): bool;
    public function finish(): bool;
}
