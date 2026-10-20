<?php
declare(strict_types=1);
require_once __DIR__.'/artifact_directory.php';
require_once dirname(__DIR__).'/bootstrap.php';
use RadioStamp\Cards\CardRenderer;

$checks=0;$assert=static function(bool $ok,string $why)use(&$checks):void{++$checks;if(!$ok){throw new RuntimeException($why);}};
$invalid=static function(callable $call)use($assert):void{ob_start();try{try{$call();throw new LogicException('Invalid data accepted.');}catch(InvalidArgumentException $e){$assert(!str_contains($e->getMessage(),'/')&&!str_contains($e->getMessage(),'\\'),'error has no private path');}}finally{$assert(ob_get_clean()==='','no output on invalid input');}};
$renderer=new CardRenderer(['/usr/share/fonts/ipa-gothic/ipag.ttf','/usr/share/fonts/ipa-pgothic/ipagp.ttf','/usr/share/fonts/ja/TrueType/kochi-gothic-subst.ttf','/usr/share/fonts/vlgothic/VL-Gothic-Regular.ttf','/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc','/usr/share/fonts/ipaexg/ipaexg.ttf','C:/Windows/Fonts/BIZ-UDGothicR.ttc','C:/Windows/Fonts/msgothic.ttc']);
$data=['user_id'=>'fictional-member','display_name'=>'架空の参加者','month'=>'2028-02','month_count'=>3,'total_count'=>1000,'month_days'=>1,'total_days'=>365,
    'days'=>['2028-02-29'=>['IC','コンデンサ','LED']],'latest_title'=>'🏆 永年稼働','awards'=>[10,30,60,100,300,365],'milestone_basis'=>'attendance_days_jst'];
$decode=static function(string $png,int $w,int $h)use($assert):GdImage{
    $assert(str_starts_with($png,"\x89PNG\r\n\x1a\n"),'PNG signature');$image=imagecreatefromstring($png);
    $assert($image instanceof GdImage&&imagesx($image)===$w&&imagesy($image)===$h,'PNG dimensions and decode');
    $assert(imagecolorat($image,0,0)===0xffffff&&imagecolorat($image,$w-1,$h-1)===0xffffff,'plain white corners');
    foreach(['C:/Windows','/usr/share/fonts','fictional-member']as$private){$assert(!str_contains($png,$private),'no private PNG metadata');}
    return$image;
};
$monthly=$renderer->monthly($data);$image=$decode($monthly,1200,1250);imagedestroy($image);
$hashes=[];foreach([10,30,60,100,300,365]as$at){$png=$renderer->memorial($data,$at);$image=$decode($png,1200,800);imagedestroy($image);$hashes[]=hash('sha256',$png);}
$assert(count(array_unique($hashes))===6,'all six current day values produce distinguishable temporary cards');
$rename=$data;$rename['display_name']='別の架空名';$assert($renderer->monthly($rename)!==$monthly,'card uses supplied display label only for rendering');
foreach(['2026-00','2026-13','2026-2','0000-02','2026-02/../../','2026-02-01']as$month){$bad=$data;$bad['month']=$month;$invalid(static fn()=>$renderer->monthly($bad));}
$bad=$data;$bad['month']='2027-02';$invalid(static fn()=>$renderer->monthly($bad));
foreach([['2028-02-30'=>['IC']],['2028-03-01'=>['IC']],['2028-02-29'=>['IC','bad','LED']],['2028-02-29'=>array_fill(0,1441,'IC')]]as$days){$bad=$data;$bad['days']=$days;$bad['month_count']=count(reset($days));$bad['total_count']=max($bad['total_count'],$bad['month_count']);$invalid(static fn()=>$renderer->monthly($bad));}
foreach(['total_days','month_days']as$field){$bad=$data;unset($bad[$field]);$invalid(static fn()=>$renderer->monthly($bad));}
$bad=$data;$bad['total_days']=1001;$invalid(static fn()=>$renderer->monthly($bad));
$bad=$data;$bad['month_days']=30;$invalid(static fn()=>$renderer->monthly($bad));
$bad=$data;$bad['month_count']=2;$invalid(static fn()=>$renderer->monthly($bad));
$bad=$data;$bad['awards']=[10,10];$invalid(static fn()=>$renderer->monthly($bad));
$bad=$data;$bad['awards']=[1];$invalid(static fn()=>$renderer->monthly($bad));
foreach([0,1,9,11,40,80,366]as$at){$invalid(static fn()=>$renderer->memorial($data,$at));}
$bad=$data;$bad['total_days']=364;$bad['awards']=[10,30,60,100,300];$invalid(static fn()=>$renderer->memorial($bad,365));
$bad=$data;unset($bad['milestone_basis']);$invalid(static fn()=>$renderer->memorial($bad,100));
$bad=$data;$bad['awards']=[10,30,60];$invalid(static fn()=>$renderer->memorial($bad,100));
// First participation keeps its stamp and count-based title, without creating a day-one benefit.
$firstDay=$data;$firstDay['month_count']=1;$firstDay['total_count']=1;$firstDay['total_days']=1;$firstDay['awards']=[];
$firstDay['days']=['2028-02-29'=>['IC']];$firstDay['latest_title']='⚡ はじめの通電';
$firstDayPng=$renderer->monthly($firstDay);$image=$decode($firstDayPng,1200,1250);imagedestroy($image);
$invalid(static fn()=>$renderer->memorial($firstDay,1));

// The former four-per-day history remains readable without rewriting its saved kinds.
$full=$data;$full['month']='2026-08';$full['month_count']=124;$full['month_days']=31;$full['days']=[];
for($day=1;$day<=31;$day++){$full['days']['2026-08-'.sprintf('%02d',$day)]=['IC','コンデンサ','LED','IC'];}
$max=$renderer->monthly($full);$image=$decode($max,1200,1250);imagedestroy($image);
// Five distinct normal slots now fit inside the same calendar; the history ceiling is not five.
$five=$data;$five['month_count']=5;$five['days']=['2028-02-29'=>['IC','コンデンサ','LED','プラグ','電池']];
$fiveBefore=serialize($five);$monthlyFive=$renderer->monthly($five);$image=$decode($monthlyFive,1200,1250);imagedestroy($image);
$assert($fiveBefore===serialize($five),'five-kind rendering does not rewrite source history');
$normal155=$full;$normal155['month_count']=155;
foreach($normal155['days']as&$stamps){$stamps=['IC','コンデンサ','LED','プラグ','電池'];}unset($stamps);
$monthly155=$renderer->monthly($normal155);$image=$decode($monthly155,1200,1250);imagedestroy($image);
foreach([str_repeat('長いお名前',80).'😀',"架空名\n\r\t@everyone","invalid\xffname",'',str_repeat('x',9000)]as$name){$value=$data;$value['display_name']=$name;ob_start();$png=$renderer->monthly($value);$assert(ob_get_clean()==='','name handling emits no warnings');$image=$decode($png,1200,1250);imagedestroy($image);}
try{new CardRenderer(['../../private/settings.php','https://example.invalid/font.ttf','/arbitrary/internal/path.ttf']);throw new LogicException('arbitrary font accepted');}catch(RuntimeException $e){$assert($e->getMessage()==='A Japanese card font is unavailable.','font error excludes internals');}

// Historical local-image preservation assertions stay in the original audit tree.
// This clean export contains no owner archive, old images, or preservation manifest.
$stage=getenv('RADIO_STAMP_TEST_ARTIFACT_DIR');
$samples=$stage.'/cards-previews';if(!is_dir($samples)&&!mkdir($samples,0700,true)&&!is_dir($samples)){throw new RuntimeException('card_preview_directory_unavailable');}
file_put_contents($samples.'/monthly.png',$monthly);file_put_contents($samples.'/monthly-max124.png',$max);
file_put_contents($samples.'/monthly-five.png',$monthlyFive);file_put_contents($samples.'/monthly-normal155.png',$monthly155);
foreach([10,30,60,100,300,365]as$at){file_put_contents($samples.'/day-'.$at.'.png',$renderer->memorial($data,$at));}
$assert(!is_file($samples.'/day-1.png'),'current preview creates no removed day-one card');
echo "Cards: {$checks} checks passed (monthly cards and temporary memorials; previews isolated; historical archive checks excluded from clean export).\n";
