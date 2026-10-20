<?php
declare(strict_types=1);
umask(0077);
// DEVELOPMENT/LOCAL composition only. Do not copy this file into public_html.
// A deployment wrapper must pass the entire fixed public_html root to the guard,
// require the versioned private bootstrap, and keep owner configuration private.
// Deployment remains a separate approval; all flags/credentials default to OFF/null.
require dirname(__DIR__) . '/bootstrap.php';

use RadioStamp\Http\FastCgiPostResponseDriver;
use RadioStamp\Http\PublicEndpoint;

ini_set('display_errors', '0');
$configPath = getenv('RADIO_STAMP_PRIVATE_CONFIG');
$body = file_get_contents('php://input', false, null, 0, 65537);
$driver = new FastCgiPostResponseDriver();
(new PublicEndpoint(__DIR__, $driver))->prepare($_SERVER, $body === false ? '' : $body, is_string($configPath) ? $configPath : null)->deliver($driver);
