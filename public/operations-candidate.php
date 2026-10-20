<?php
declare(strict_types=1);
umask(0077);
// Separate candidate; deploying this file/config/environment is not authorized by its existence.
ini_set('display_errors','0');
$root=$_SERVER['DOCUMENT_ROOT']??null;
if(PHP_SAPI==='cli'||!is_string($root)||realpath($root)===false){http_response_code(503);echo '{"error":"operations_unavailable"}';exit;}
$release=getenv('RADIO_STAMP_OPERATIONS_RELEASE');
$publicReal=strtolower(str_replace('\\','/',rtrim(realpath($root),'/\\')));
$releaseReal=is_string($release)?realpath($release):false;
$releaseNormal=$releaseReal===false?'':strtolower(str_replace('\\','/',rtrim($releaseReal,'/\\')));
if($releaseReal===false||!is_dir($releaseReal)||is_link($release)||$releaseNormal===$publicReal||str_starts_with($releaseNormal,$publicReal.'/')
    ||!is_file($releaseReal.'/bootstrap.php')||is_link($releaseReal.'/bootstrap.php')){http_response_code(503);echo '{"error":"operations_unavailable"}';exit;}
try{require $releaseReal.'/bootstrap.php';}catch(\Throwable){http_response_code(503);echo '{"error":"operations_unavailable"}';exit;}
$path=getenv('RADIO_STAMP_OPERATIONS_CONFIG');
$driver=new RadioStamp\Http\FastCgiPostResponseDriver();
$body=file_get_contents('php://input',false,null,0,65537);
(new RadioStamp\Http\OperationsEndpoint($root,$driver))->prepare($_SERVER,$body===false?'':$body,is_string($path)?$path:null)->deliver($driver);
