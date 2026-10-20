<?php
declare(strict_types=1);
umask(0077);
if(PHP_SAPI!=='cli'){http_response_code(503);exit;}
ini_set('display_errors','0');
require dirname(__DIR__).'/bootstrap.php';
exit((new RadioStamp\Operations\OperationsCli(new RadioStamp\Operations\OperationsRuntime()))->run(array_slice($argv,1),static function(string $line):void{echo $line;}));
