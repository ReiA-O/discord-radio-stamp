<?php
declare(strict_types=1);

namespace RadioStamp\Cards {
    /** Observe native allocations without replacing the canonical renderer or its business rules. */
    function imagecreatetruecolor(int $width,int $height):\GdImage|false
    {
        $GLOBALS['stamp_allocations'][]=[$width,$height];
        if($width<1||$height<1||$width>1200||$height>10000||$width*$height>12_000_000){
            throw new \RuntimeException('card_stamp_test_allocation_boundary');
        }
        return \imagecreatetruecolor($width,$height);
    }
    /** Observe real FreeType text and its actual bounding box; retain neither fonts nor image references. */
    function imagettftext(\GdImage $image,float $size,float $angle,int $x,int $y,int $color,string $font,string $text,array $options=[]):array|false
    {
        $box=\imagettftext($image,$size,$angle,$x,$y,$color,$font,$text,$options);
        $GLOBALS['stamp_text'][]=['text'=>$text,'x'=>$x,'y'=>$y,'box'=>$box];
        return $box;
    }
}

namespace {
require_once __DIR__.'/artifact_directory.php';
require dirname(__DIR__).'/bootstrap.php';

use RadioStamp\Cards\CardRenderer;

$checks=0;$scenarios=[];$measurements=[];$GLOBALS['stamp_allocations']=[];$GLOBALS['stamp_text']=[];
$assert=static function(bool $ok,string $why)use(&$checks):void{++$checks;if(!$ok){throw new RuntimeException($why);}};
$same=static function(mixed $expected,mixed $actual,string $why)use($assert):void{
    $assert($expected===$actual,$why.' expected='.json_encode($expected,JSON_UNESCAPED_UNICODE).' actual='.json_encode($actual,JSON_UNESCAPED_UNICODE));
};
$fonts=['/usr/share/fonts/ipa-gothic/ipag.ttf','/usr/share/fonts/ipa-pgothic/ipagp.ttf','/usr/share/fonts/ja/TrueType/kochi-gothic-subst.ttf','/usr/share/fonts/vlgothic/VL-Gothic-Regular.ttf','/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc','/usr/share/fonts/ipaexg/ipaexg.ttf','C:/Windows/Fonts/BIZ-UDGothicR.ttc','C:/Windows/Fonts/msgothic.ttc'];
$renderer=new CardRenderer($fonts);
$stage=(getenv('RADIO_STAMP_TEST_ARTIFACT_DIR'));
$previews=$stage.'/card-stamps-previews';
if(!is_dir($previews)&&!mkdir($previews,0700,true)&&!is_dir($previews)){throw new RuntimeException('card_stamp_artifact_directory_unavailable');}
$kinds=['IC','コンデンサ','LED','プラグ','電池'];$palette=['IC'=>0x6355a0,'コンデンサ'=>0x418dbd,'LED'=>0xea6d63,'プラグ'=>0x354052,'電池'=>0x4a966d];
$data=static function(string $month,array $days,string $name='架空のスタンプ試験'):array{
    $count=array_sum(array_map('count',$days));$nonempty=count(array_filter($days,static fn(array $stamps):bool=>$stamps!==[]));
    return ['user_id'=>'STAMP_CANARY_FICTIONAL_ID','display_name'=>$name,'month'=>$month,'month_count'=>$count,
        'total_count'=>max(1000,$count),'month_days'=>$nonempty,'total_days'=>365,'days'=>$days,
        'awards'=>[10,30,60,100,300,365],'milestone_basis'=>'attendance_days_jst'];
};
$render=static function(array $input,string $label,bool $save=true)use($renderer,$assert,$same,&$measurements,$previews):array{
    $before=serialize($input);$level=ob_get_level();$start=hrtime(true);$allocationIndex=count($GLOBALS['stamp_allocations']);$GLOBALS['stamp_text']=[];
    ob_start();
    try{$png=$renderer->monthly($input);$same($level+1,ob_get_level(),$label.' output buffer depth');}
    finally{$same('',ob_get_clean(),$label.' no warning or partial output');}
    $same($level,ob_get_level(),$label.' original buffer restored');$same($before,serialize($input),$label.' source history unmodified');
    $assert(str_starts_with($png,"\x89PNG\r\n\x1a\n"),$label.' PNG signature');$assert(strlen($png)<=8_000_000,$label.' existing upload byte limit');
    $image=imagecreatefromstring($png);$assert($image instanceof GdImage,$label.' PNG decodes');
    $same(1200,imagesx($image),$label.' fixed width');$assert(imagesy($image)>=1250&&imagesy($image)<=10000,$label.' bounded height');
    $same(0xffffff,imagecolorat($image,0,0),$label.' white top corner');$same(0xffffff,imagecolorat($image,1199,imagesy($image)-1),$label.' white bottom corner');
    foreach(['STAMP_CANARY_FICTIONAL_ID','/usr/share/fonts','C:/Windows']as$private){$assert(!str_contains($png,$private),$label.' no private PNG metadata');}
    $allocations=array_slice($GLOBALS['stamp_allocations'],$allocationIndex);
    $assert(in_array([1200,imagesy($image)],$allocations,true),$label.' measured native full canvas allocation');
    $texts=$GLOBALS['stamp_text'];
    $requireText=static function(string $expected,?int $atX=null,?int $atY=null)use($texts,$assert,$image,$label):array{
        $matches=array_filter($texts,static fn(array $row):bool=>$row['text']===$expected&&($atX===null||$row['x']===$atX)&&($atY===null||$row['y']===$atY));
        $assert($matches!==[],$label.' actual GD text includes '.$expected);$row=reset($matches);$box=$row['box'];
        $assert(is_array($box)&&count($box)===8,$label.' native text has measured glyph bounds');
        $left=min($box[0],$box[2],$box[4],$box[6]);$right=max($box[0],$box[2],$box[4],$box[6]);$top=min($box[1],$box[3],$box[5],$box[7]);$bottom=max($box[1],$box[3],$box[5],$box[7]);
        $assert($left>=0&&$right<imagesx($image)&&$top>=0&&$bottom<imagesy($image),$label.' actual text is fully inside PNG');
        $ink=0;for($py=$top;$py<=$bottom;$py++){for($px=$left;$px<=$right;$px++){if((imagecolorat($image,$px,$py)&0xffffff)!==0xffffff){++$ink;}}}
        $assert($ink>3,$label.' measured text region contains rendered pixels');return $row;
    };
    foreach(['この月の開催分','今月の参加日数','累計参加','累計参加日数']as$i=>$metric){$requireText($metric,48+276*$i,222);}
    foreach([[$input['month_count'],'回'],[$input['month_days'],'日'],[$input['total_count'],'回'],[$input['total_days'],'日']]as$i=>[$value,$unit]){$requireText($value.' '.$unit,48+276*$i,264);}
    if($label==='normal-5'){
        $requireText('現在のロール: 永年稼働',420,179);
        $requireText('新規記録の通常枠（JST）',48,1090);
        foreach([['00:00','IC'],['04:00','コンデンサ'],['07:00','LED'],['12:30','プラグ'],['22:00','電池']]as$i=>[$time,$kind]){
            $requireText($kind,103+220*$i,1127);$requireText($time,54+220*$i,1160);
        }
        $requireText('過去は保存時の絵 / 日数はJST同日1日',48,1190);
    }
    $overflow=array_filter($input['days'],static fn(array $stamps):bool=>count($stamps)>5);ksort($overflow,SORT_STRING);
    if($overflow!==[]){
        $requireText('カレンダーの +n は下部の全件明細へ（先頭5件も再掲）');
        $requireText('先頭5件も含む全件 / 順序: 左→右、上→下');
        foreach($overflow as$date=>$stamps){
            $header=$requireText($date.' / 全'.count($stamps).'件（先頭5件を含む）');
            $counts=array_count_values($stamps);$requireText('IC '.($counts['IC']??0).' / コンデンサ '.($counts['コンデンサ']??0).' / LED '.($counts['LED']??0).' / プラグ '.($counts['プラグ']??0).' / 電池 '.($counts['電池']??0),48,$header['y']+28);
            $dateObject=new DateTimeImmutable($input['month'].'-01',new DateTimeZone('Asia/Tokyo'));$position=(int)$dateObject->format('w')+(int)substr($date,-2)-1;
            $x=(int)round(48+($position%7)*(1104/7));$y=342+intdiv($position,7)*120;
            $marker=$requireText('+'.(count($stamps)-5).' 明細',$x+50,$y+27);
            $assert(max($marker['box'][1],$marker['box'][3],$marker['box'][5],$marker['box'][7])<$y+35,$label.' additional-count marker is above all five component bodies');
        }
        $detailCount=array_sum(array_map('count',$overflow));
        $requireText($detailCount<=256?'個別スタンプを全件表示':($detailCount<=2048?'縮小表示 / 拡大して確認':'高密度表示 / 拡大して確認'));
    }
    $measurements[$label]=['width'=>1200,'height'=>imagesy($image),'pixels'=>1200*imagesy($image),'png_bytes'=>strlen($png),
        'elapsed_seconds'=>(hrtime(true)-$start)/1e9,'php_peak_memory_bytes'=>memory_get_peak_usage(true),
        'canvas_rgba_bytes_estimate'=>1200*imagesy($image)*4,'sha256'=>hash('sha256',$png)];
    if($save){file_put_contents($previews.'/'.$label.'.png',$png);}
    return [$image,$png];
};
// Expected display geometry is fixed by the independently reviewed visual specification.
// Never call renderer layout/component helpers: check the decoded PNG's colored bodies instead.
$cell=static function(string $month,int $day):array{
    $first=new DateTimeImmutable($month.'-01',new DateTimeZone('Asia/Tokyo'));$index=(int)$first->format('w')+$day-1;
    $column=$index%7;$row=intdiv($index,7);
    return ['column'=>$column,'row'=>$row,'x'=>48+$column*(1104/7),'y'=>342+$row*120];
};
$colors=static function(GdImage $image,int $x,int $y,int $width,int $height)use($palette):array{
    $counts=array_fill_keys(array_keys($palette),0);$reverse=array_flip($palette);
    for($py=$y;$py<$y+$height;$py++){for($px=$x;$px<$x+$width;$px++){
        $rgb=imagecolorat($image,$px,$py)&0xffffff;if(isset($reverse[$rgb])){++$counts[$reverse[$rgb]];}
    }}return $counts;
};
$hashRegion=static function(GdImage $image,int $x,int $y,int $width,int $height):string{
    $hash=hash_init('sha256');for($py=$y;$py<$y+$height;$py++){for($px=$x;$px<$x+$width;$px++){hash_update($hash,pack('N',imagecolorat($image,$px,$py)));}}
    return hash_final($hash);
};
// Removing every color difference still leaves independently visible component shapes.
$shapeMask=static function(GdImage $image,int $x,int $y,int $width,int $height):string{
    $hash=hash_init('sha256');for($py=$y;$py<$y+$height;$py++){for($px=$x;$px<$x+$width;$px++){
        hash_update($hash,(imagecolorat($image,$px,$py)&0xffffff)===0xffffff?"\x00":"\x01");
    }}return hash_final($hash);
};
$overview=static function(GdImage $image,string $month,array $days,string $label)use($cell,$colors,$assert,$palette):void{
    $first=new DateTimeImmutable($month.'-01',new DateTimeZone('Asia/Tokyo'));$length=(int)$first->format('t');$offset=(int)$first->format('w');
    for($index=0;$index<42;$index++){
        $day=$index-$offset+1;$stamps=$day>=1&&$day<=$length?($days[$month.'-'.sprintf('%02d',$day)]??[]):[];
        $x=48+($index%7)*(1104/7);$y=342+intdiv($index,7)*120;$accounted=array_fill_keys(array_keys($palette),0);
        foreach([[30,56],[79,56],[128,56],[54,98],[104,98]]as$slot=>[$offsetX,$offsetY]){
            $cx=(int)round($x+$offsetX);$cy=$y+$offsetY;
            $seen=$colors($image,$cx-18,$cy-19,37,38);$expected=$stamps[$slot]??null;
            $assert($expected===null?array_sum($seen)===0:($seen[$expected]>0&&array_sum($seen)===$seen[$expected]),$label.' date '.$day.' individual ordered slot '.$slot);
            foreach($seen as$kind=>$count){$accounted[$kind]+=$count;}
        }
        // This also tests empty weekdays before/after the month and stamp spill into an adjacent cell.
        $whole=$colors($image,(int)ceil($x)+1,$y+1,(int)floor(1104/7)-2,118);
        $assert($whole===$accounted,$label.' date '.$day.' no colored component outside its five bounded slots');
        $assert(array_sum($colors($image,(int)ceil($x)+1,$y+116,(int)floor(1104/7)-2,3))===0,$label.' date '.$day.' bottom stamp cannot spill beyond day');
    }
};
$detail=static function(GdImage $image,array $days,string $label)use($colors,$assert,$same,$palette,$shapeMask):array{
    $overflow=array_filter($days,static fn(array $stamps):bool=>count($stamps)>=6);ksort($overflow,SORT_STRING);
    $total=array_sum(array_map('count',$overflow));$pitch=$total<=256?52:($total<=2048?32:11);$columns=(int)floor(1104/$pitch);$dayTop=1370;$observed=0;$daySummaries=[];
    foreach($overflow as$date=>$stamps){
        $firstCenter=null;$lastCenter=null;$sum=array_fill_keys(array_keys($palette),0);$pixelSum=$sum;$shapes=[];
        foreach($stamps as$index=>$expected){
            $column=$index%$columns;$row=intdiv($index,$columns);$x=48+$column*$pitch;$y=$dayTop+68+$row*$pitch;
            $seen=$colors($image,$x,$y,$pitch,$pitch);
            $assert($seen[$expected]>0&&array_sum($seen)===$seen[$expected],$label.' full detail '.$date.' stamp '.($index+1).' individually visible and ordered');
            $shapes[$expected]??=$shapeMask($image,$x,$y,$pitch,$pitch);
            $firstCenter??=[$x+intdiv($pitch,2),$y+intdiv($pitch,2)];$lastCenter=[$x+intdiv($pitch,2),$y+intdiv($pitch,2)];
            ++$sum[$expected];++$observed;
            foreach($seen as$kind=>$count){$pixelSum[$kind]+=$count;}
        }
        $blockHeight=68+(int)ceil(count($stamps)/$columns)*$pitch+28;
        $same($pixelSum,$colors($image,48,$dayTop,1104,$blockHeight),$label.' no extra or spilled component in day header, unused columns or padding');
        if(count($shapes)===5){$same(5,count(array_unique($shapes)),$label.' '.$date.' all five shapes remain distinct after removing color');}
        $daySummaries[$date]=['count'=>count($stamps),'kinds'=>$sum,'first_center'=>$firstCenter,'last_center'=>$lastCenter];
        $dayTop+=$blockHeight;
    }
    $same($total,$observed,$label.' all detail stamps include overview first five');
    $assert($dayTop+20<=imagesy($image),$label.' last detail row and footer fully inside decoded image');
    return ['stamps_verified_from_pixels'=>$observed,'pitch'=>$pitch,'columns'=>$columns,'dates'=>array_keys($overflow),'days'=>$daySummaries];
};

try{
    foreach(range(0,5)as$n){
        $stamps=array_slice($kinds,0,$n);$days=$n===0?[]:['2026-10-20'=>$stamps];
        [$image]=$render($data('2026-10',$days),'normal-'.$n);$same(1250,imagesy($image),'normal '.$n.' stays 1200x1250');$overview($image,'2026-10',$days,'normal '.$n);
        if($n===5){$position=$cell('2026-10',20);$shapes=[];foreach([[30,56],[79,56],[128,56],[54,98],[104,98]]as[$dx,$dy]){
            $shapes[]=$shapeMask($image,(int)round($position['x']+$dx)-18,$position['y']+$dy-19,37,38);
        }$same(5,count(array_unique($shapes)),'all five normal component shapes differ independently of their color');}
        imagedestroy($image);unset($image);
    }
    $same(['column'=>2,'row'=>4],array_intersect_key($cell('2028-02',29),['column'=>true,'row'=>true]),'leap day Tuesday in fifth row');
    $same(['column'=>6,'row'=>0],array_intersect_key($cell('2026-08',1),['column'=>true,'row'=>true]),'six-week month begins Saturday');
    $same(['column'=>1,'row'=>5],array_intersect_key($cell('2026-08',31),['column'=>true,'row'=>true]),'six-week month ends Monday in sixth row');
    $six=[];for($d=1;$d<=31;$d++){$six['2026-08-'.sprintf('%02d',$d)]=$kinds;}
    [$image]=$render($data('2026-08',$six),'six-week-155');$same(1250,imagesy($image),'155 stamps preserve normal height');$overview($image,'2026-08',$six,'six-week 155');imagedestroy($image);unset($image);
    $leap=['2028-02-29'=>['IC','コンデンサ','LED','LED']];[$image]=$render($data('2028-02',$leap),'leap-day');$overview($image,'2028-02',$leap,'leap day');imagedestroy($image);unset($image);

    // Equal totals: a component-kind mutation must alter only the intended stamp slot.
    $baseDays=['2026-10-20'=>$kinds];[$original]=$render($data('2026-10',$baseDays),'order-original');
    $changedDays=['2026-10-20'=>['LED','コンデンサ','IC','プラグ','電池']];[$changed]=$render($data('2026-10',$changedDays),'order-swapped');
    $position=$cell('2026-10',20);$samePositions=[];
    foreach([[30,56],[79,56],[128,56],[54,98],[104,98]]as[$offsetX,$offsetY]){$cx=(int)round($position['x']+$offsetX);$cy=$position['y']+$offsetY;
        $equal=$hashRegion($original,$cx-18,$cy-19,37,38)===$hashRegion($changed,$cx-18,$cy-19,37,38);$samePositions[]=$equal;
    }
    $same([false,true,false,true,true],$samePositions,'swapped types change precisely the two corresponding component images');
    $same($hashRegion($original,48,342,1104,360),$hashRegion($changed,48,342,1104,360),'unaffected earlier weeks remain identical');
    imagedestroy($original);imagedestroy($changed);unset($original,$changed);$scenarios['ordered_local_component_mutation']=true;

    $early=['2026-10-02'=>['LED']];$late=['2026-10-20'=>['LED']];[$a]=$render($data('2026-10',$early),'date-early',false);[$b]=$render($data('2026-10',$late),'date-late',false);
    foreach([2,20]as$d){$p=$cell('2026-10',$d);$equal=$hashRegion($a,(int)ceil($p['x'])+1,$p['y']+30,150,86)===$hashRegion($b,(int)ceil($p['x'])+1,$p['y']+30,150,86);$same(false,$equal,'moving same stamp changes date cell '.$d);}
    $same($hashRegion($a,48,462,1104,240),$hashRegion($b,48,462,1104,240),'moving a stamp does not alter intervening empty weeks');imagedestroy($a);imagedestroy($b);unset($a,$b);
    $scenarios['date_location_changes_actual_pixels']=true;

    // Historical three-kind cycles retain their stored kinds; current slot labels do not reassign them.
    $legacy=['2026-10-20'=>['IC','コンデンサ','LED','IC','LED']];[$image]=$render($data('2026-10',$legacy),'legacy-three-kind-history');
    $same(1250,imagesy($image),'five old records remain a normal-height history');$overview($image,'2026-10',$legacy,'legacy three-kind history');imagedestroy($image);unset($image);$scenarios['legacy_saved_kinds_preserved']=true;
    $overflowSix=['2026-10-20'=>['IC','コンデンサ','LED','プラグ','電池','IC']];[$image]=$render($data('2026-10',$overflowSix),'six-detail');
    $assert(imagesy($image)>1250,'six stamps get an appended detail section');$overview($image,'2026-10',$overflowSix,'six overview');$scenarios['six_full_detail']=$detail($image,$overflowSix,'six');imagedestroy($image);unset($image);
    $sixChanged=$overflowSix;$sixChanged['2026-10-20'][5]='電池';[$a]=$render($data('2026-10',$overflowSix),'sixth-original',false);[$b]=$render($data('2026-10',$sixChanged),'sixth-changed',false);
    $same($hashRegion($a,48,342,1104,720),$hashRegion($b,48,342,1104,720),'sixth stamp changes no overview component');
    $same(false,$hashRegion($a,48+5*52,1438,52,52)===$hashRegion($b,48+5*52,1438,52,52),'sixth stamp is visibly retained in its own appended slot');imagedestroy($a);imagedestroy($b);unset($a,$b);

    $unordered=['2026-10-20'=>['IC','コンデンサ','LED','プラグ','電池','IC'],'2026-10-02'=>['電池','プラグ','LED','コンデンサ','IC','電池','プラグ']];
    [$a,$forward]=$render($data('2026-10',$unordered),'date-sorted-detail');[$b,$reverse]=$render($data('2026-10',array_reverse($unordered,true)),'date-reversed-input',false);
    $same($forward,$reverse,'reversed day insertion order has exactly the same chronological PNG');$scenarios['chronological_full_details']=$detail($a,$unordered,'chronological');imagedestroy($a);imagedestroy($b);unset($a,$b);

    foreach([256,257,2048,2049]as$n){
        $days=[];$remaining=$n;$d=1;while($remaining>0){$count=min(1440,$remaining);$stamps=[];for($i=0;$i<$count;$i++){$stamps[]=$kinds[$i%5];}$days['2026-10-'.sprintf('%02d',$d++)]=$stamps;$remaining-=$count;}
        [$image]=$render($data('2026-10',$days),'density-'.$n);$scenarios['density_'.$n]=$detail($image,$days,'density '.$n);imagedestroy($image);unset($image);
    }
    $maximum=[];for($d=1;$d<=31;$d++){$stamps=[];for($i=0;$i<1440;$i++){$stamps[]=$kinds[($d+$i)%5];}$maximum['2026-10-'.sprintf('%02d',$d)]=$stamps;}
    [$image]=$render($data('2026-10',$maximum),'maximum-44640');$overview($image,'2026-10',$maximum,'maximum overview');
    $scenarios['maximum_44640']=$detail($image,$maximum,'maximum 44640');$same(44640,$scenarios['maximum_44640']['stamps_verified_from_pixels'],'every supported maximum monthly detail has an independently observed body');imagedestroy($image);unset($image);

    [$image]=$render($data('2026-10',$baseDays,str_repeat('長い名前',80)."\n@everyone 😀"),'long-name');$overview($image,'2026-10',$baseDays,'long name');imagedestroy($image);unset($image);
    // Historical owner-asset hashes remain in the original local audit tree.
    // Pixel, capacity, layout, order, and bounds assertions above remain unchanged.
    $scenarios['historical_asset_checks_excluded_from_clean_export']=true;$scenarios['normal_0_to_5_and_calendar_cells']=true;$scenarios['five_distinct_component_kinds']=$kinds;
    $scenarios['density_readability_limit']='High-density component bodies remain distinguishable by pixels; normal-size legibility is not claimed. Inspect saved zoomed previews.';
    $scenarios['memory_measurement_scope']='PHP allocator peak is measured; RGBA canvas bytes are calculated, not OS/GD RSS measurements.';
    $result=['status'=>'LOCAL_CARD_STAMPS_VERIFIED_NOT_DEPLOYED','assertions'=>$checks,'php'=>PHP_VERSION,'sqlite'=>SQLite3::version()['versionString'],
        'real_network_calls'=>0,'server_commands'=>0,'deployed'=>false,'measurements'=>$measurements,'scenarios'=>$scenarios,
        'maximum_native_allocation_pixels'=>max(array_map(static fn(array $size):int=>$size[0]*$size[1],$GLOBALS['stamp_allocations']))];
    file_put_contents($stage.'/card-stamps.safe.json',json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
    echo "Card stamps: $checks assertions passed (decoded PNG kinds/order/dates, complete 44640 detail slots, bounded canvas and isolated previews; local only).\n";
}finally{unset($GLOBALS['stamp_allocations'],$GLOBALS['stamp_text']);}
}
