<?php
declare(strict_types=1);
// Local fictional previews only; no DB, real configuration or network.
if (PHP_SAPI !== 'cli') { http_response_code(503); exit; }
require dirname(__DIR__) . '/bootstrap.php';
use RadioStamp\Cards\CardRenderer;
use RadioStamp\Core\{ParticipationDays,TitlePolicy,StampPolicy};
$renderer = new CardRenderer([
    '/usr/share/fonts/ipa-gothic/ipag.ttf','/usr/share/fonts/ipa-pgothic/ipagp.ttf',
    '/usr/share/fonts/ja/TrueType/kochi-gothic-subst.ttf','/usr/share/fonts/vlgothic/VL-Gothic-Regular.ttf',
    '/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc','/usr/share/fonts/ipaexg/ipaexg.ttf',
    'C:/Windows/Fonts/BIZ-UDGothicR.ttc','C:/Windows/Fonts/msgothic.ttc',
]);
$requestedOutput = getenv('RADIO_STAMP_PREVIEW_OUTPUT_DIR');
$output = is_string($requestedOutput) && $requestedOutput !== ''
    ? $requestedOutput : dirname(__DIR__) . '/samples/stamp-cards';
if (!is_dir($output) && !mkdir($output,0700,true)) { throw new RuntimeException('Preview output is unavailable.'); }
$data = ['user_id'=>'fictional-preview','display_name'=>'見本・架空の参加者','month'=>'2026-10',
    'month_count'=>5,'total_count'=>1460,'month_days'=>1,'total_days'=>365,
    'days'=>['2026-10-19'=>array_values(StampPolicy::SLOT_STAMPS)],
    'latest_title'=>TitlePolicy::forCount(1460)[1],'awards'=>ParticipationDays::MILESTONES,
    'milestone_basis'=>'attendance_days_jst'];
$manifest = ['schema'=>4,'fictional_preview'=>true,
    'monthly'=>['individual_stamps'=>true,'art_source'=>'existing_GD_components','background'=>'white',
        'slot_policy'=>'five_fixed_slots','slot_stamps'=>StampPolicy::SLOT_STAMPS],
    'memorial'=>['placeholder'=>true,'artwork_status'=>'awaiting_owner_materials','milestones'=>ParticipationDays::MILESTONES,
        'benefit_contents'=>'not_finalized','day_365'=>['type'=>'owner_gift_special_award','item'=>'undecided','fulfilment'=>'not_implemented']],
    'files'=>[]];
$images = ['monthly.png'=>$renderer->monthly($data)];
foreach (ParticipationDays::MILESTONES as $day) { $images['day-'.$day.'.png']=$renderer->memorial($data,$day); }
foreach ($images as $name=>$png) {
    if (file_put_contents($output.'/'.$name,$png)!==strlen($png)) { throw new RuntimeException('Preview output is unavailable.'); }
    $manifest['files'][]=['file'=>$name,'kind'=>$name==='monthly.png'?'monthly_stamps':'memorial_placeholder',
        'placeholder'=>$name!=='monthly.png','bytes'=>strlen($png),'sha256'=>hash('sha256',$png)];
}
$json=json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
file_put_contents($output.'/manifest.json',$json);
echo 'Fictional previews: '.count($images)." files; monthly stamps and memorial placeholders stored separately from legacy samples.\n";
