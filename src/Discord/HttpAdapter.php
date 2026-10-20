<?php
declare(strict_types=1);
namespace RadioStamp\Discord;

interface HttpAdapter
{
    public function exchange(HttpRequest $request): HttpResponse;
}
