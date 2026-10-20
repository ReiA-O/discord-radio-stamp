<?php
declare(strict_types=1);
require_once __DIR__.'/artifact_directory.php';
require dirname(__DIR__) . '/bootstrap.php';

use RadioStamp\Core\{Database,FrozenClock,RadioService,TitlePolicy,ParticipationDays};
use RadioStamp\Discord\MockGateway;

$checks=0;
$same=static function(mixed $expected,mixed $actual,string $why)use(&$checks):void{++$checks;if($expected!==$actual){throw new RuntimeException($why . ': ' . json_encode([$expected,$actual],JSON_UNESCAPED_UNICODE));}};
$fixture=static function():array{
    $db=new Database(':memory:');$clock=new FrozenClock('2026-10-19T07:00:00+09:00');$gateway=new MockGateway($clock);
    $service=new RadioService($db,$gateway,$clock,['guild_id'=>'fictional-guild','voice_channel_id'=>'fictional-voice','text_channel_id'=>'fictional-guide','records_channel_id'=>'fictional-achievements']);
    return [$db,$clock,$gateway,$service];
};
$attend=static function(array $f,string $sessionDate,string $slot,string $instant,string $id)use($same):array{
    [$db,$clock,$gateway,$service]=$f;$clock->set($instant);
    $scheduled=new DateTimeImmutable($sessionDate . 'T' . substr($slot,0,2) . ':' . substr($slot,2,2) . ':00+09:00');
    $service->planSessions($scheduled,$scheduled);$session=$sessionDate . '_' . $slot;
    $gateway->setVoiceState('900000000000000101','fictional-voice');
    $same('started',$service->start($session,'900000000000000101','start-'.$id)['code'],'session starts');
    $result=$service->attend($session,'900000000000000101','attend-'.$id);
    $same('attended',$result['code'],'new session attendance');return $result;
};

// All five sessions remain five attendance records but exactly one participation day.
$f=$fixture();
foreach(['0000','0400','0700','1230','2200']as$i=>$slot){$r=$attend($f,'2026-10-19',$slot,'2026-10-19T'.substr($slot,0,2).':'.substr($slot,2,2).':00+09:00','same-day-'.$slot);$same(1,$r['total_days'],'same date is one day');$same(null,$r['new_day_milestone'],'first participation day issues no day benefit');$same(null,$r['new_memorial'],'legacy result alias also issues no first-day memorial');}
$same(5,$f[3]->statusData('900000000000000101')['total_count'],'all five sessions retained');
$same(5,(int)$f[0]->pdo()->query('SELECT COUNT(*) FROM attendance')->fetchColumn(),'five real history rows');
$same(1,$f[3]->statusData('900000000000000101')['total_days'],'status shows one day');
$same('already_attended',$f[3]->attend('2026-10-19_2200','900000000000000101','new-duplicate-id')['code'],'different interaction same session rejected');
$same(5,$f[3]->statusData('900000000000000101')['total_count'],'duplicate cannot add count');

// Actual JST date is independent of the scheduled session date and UTC date.
$mid=$fixture();
$first=$attend($mid,'2026-11-01','0000','2026-10-31T23:59:59+09:00','before-midnight');
$same('2026-11-01',$first['count_date'],'scheduled-date session history preserved');
$same('2026-10-31',$first['participation_date'],'benefit day uses actual JST participation');
$mid[1]->set('2026-11-01T00:00:00+09:00');$mid[2]->setVoiceState('900000000000000101','fictional-voice');
$same('already_attended',$mid[3]->attend('2026-11-01_0000','900000000000000101','after-midnight-repeat')['code'],'midnight does not turn a duplicate into another day');
$same(1,$mid[3]->statusData('900000000000000101')['total_days'],'duplicate across midnight retains one day');
$second=$attend($mid,'2026-11-01','0400','2026-11-01T04:00:00+09:00','new-jst-day');
$same(2,$second['total_days'],'JST boundary counts distinct date despite same UTC date');
$same(1,$mid[3]->cardData('900000000000000101','架空名','2026-10')['month_days'],'previous actual month keeps day');
$same(1,$mid[3]->cardData('900000000000000101','架空名','2026-11')['month_days'],'new actual month gets day');
$same(0,$mid[3]->cardData('900000000000000101','架空名','2026-10')['month_count'],'scheduled history and actual day month deliberately separate');

// 365 nonconsecutive dates: every milestone and no absence/streak reset.
$long=$fixture();$origin=new DateTimeImmutable('2023-01-01T07:00:00+09:00');
for($i=1;$i<=365;$i++){
    $when=$origin->modify('+'.(($i-1)*2).' days');$r=$attend($long,$when->format('Y-m-d'),'0700',$when->format(DATE_ATOM),'long-'.$i);
    $same($i,$r['total_days'],'nonconsecutive lifetime days increase');
    $same(in_array($i,[10,30,60,100,300,365],true)?$i:null,$r['new_day_milestone'],'only the six currently approved day milestones');
}
$all=$long[3]->cardData('900000000000000101','旧名','2024-12');
$same([10,30,60,100,300,365],$all['awards'],'six day milestones retained without a first-day benefit');
$same('🏆 永年稼働',$all['latest_title'],'365 counts reach last count title');
$renamed=$long[3]->cardData('900000000000000101','新名','2024-12');
foreach(['total_count','total_days','month_days','awards','latest_title']as$key){$same($all[$key],$renamed[$key],'rename preserves '.$key);}
$same(0,$long[3]->statusData('900000000000000102')['total_count'],'same-name different ID has no shared count');
$same(0,$long[3]->statusData('900000000000000102')['total_days'],'different ID has no shared days');
$long[1]->set('2030-01-01T00:00:00+09:00');
$same(365,$long[3]->statusData('900000000000000101')['total_days'],'long absence never resets dates');

// Historical count awards are preserved but never misread as day eligibility.
$long[0]->pdo()->prepare('INSERT INTO participants(user_id,total_count) VALUES (?,0)')->execute(['900000000000000103']);
$long[0]->pdo()->prepare('INSERT INTO memorial_awards(user_id,milestone,reached_at) VALUES (?,?,?)')->execute(['900000000000000103',100,1]);
$same([],$long[3]->cardData('900000000000000103','架空名','2026-10')['awards'],'old count award cannot grant day benefit');
$same(100,(int)$long[0]->pdo()->query("SELECT milestone FROM memorial_awards WHERE user_id='900000000000000103'")->fetchColumn(),'old record remains intact');

// Independently specified title labels and before/at/after boundaries; no daily-unit coupling.
$titles=[1=>'⚡ はじめの通電',40=>'🔌 接続完了',80=>'⚙️ 暖機運転',120=>'💻 起動完了',160=>'🔋 安定稼働',200=>'🔧 最適化中',240=>'📡 同期完了',280=>'🚀 定格突破',320=>'🌟 高効率運転',365=>'🏆 永年稼働'];
$same($titles,TitlePolicy::TITLES,'exact approved ten count titles');$previous=[null,null];
foreach($titles as$at=>$title){$same($previous,TitlePolicy::forCount($at-1),'before count title boundary');$same([$at,$title],TitlePolicy::forCount($at),'at count title boundary');$same([$at,$title],TitlePolicy::forCount($at+1),'after count title boundary');$previous=[$at,$title];}
$same([365,'🏆 永年稼働'],TitlePolicy::forCount(1460),'count continues above final title');
$same([],ParticipationDays::reached(1),'one participation day is counted but grants no benefit');
$same([10,30],ParticipationDays::reached(40),'day benefits do not use 40-count title threshold');
echo "Participation policy: {$checks} assertions passed (fictional users, in-memory SQLite, no live calls).\n";
