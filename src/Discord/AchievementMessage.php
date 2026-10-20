<?php
declare(strict_types=1);
namespace RadioStamp\Discord;

use RadioStamp\Core\{TitlePolicy,ParticipationDays};

final class AchievementMessage
{
    public static function build(string $userId, int $threshold, int $totalCount): array
    {
        if (preg_match('/\A[1-9][0-9]{16,19}\z/D', $userId) !== 1
            || !isset(TitlePolicy::TITLES[$threshold]) || $totalCount < $threshold) {
            throw new \InvalidArgumentException('Invalid achievement.');
        }
        return [
            'content' => '🎉 <@' . $userId . '> さんが「' . TitlePolicy::TITLES[$threshold] . '」ロールを獲得しました！！！' . "\n" . '累計' . $totalCount . '回参加！新たな段階へレベルアップ⚡',
            'allowed_mentions' => ['parse' => [], 'users' => [$userId], 'roles' => [], 'replied_user' => false],
            'tts' => false,
        ];
    }

    public static function buildDay(string $userId,int $milestone,int $totalDays,?string $ownerUserId=null): array
    {
        if(!self::id($userId)||!in_array($milestone,ParticipationDays::MILESTONES,true)||$totalDays<$milestone
            ||($milestone===365&&!self::id($ownerUserId))||($milestone!==365&&$ownerUserId!==null)){
            throw new \InvalidArgumentException('Invalid day achievement.');
        }
        $users=[$userId];
        $content='🏅 <@'.$userId.'> さんが「'.$milestone.'日参加特典」を獲得しました！！！！！！' . "\n" . $milestone.'日分の積み重ね、おめでとう！！👏🎉';
        if($milestone===365){
            $content='🎊🏆 <@'.$userId.'> さんが「1年がんばりました特別賞」を獲得しました！！！！！！！！！！' . "\n" . '累計365日参加！！！1年分、本当によく頑張りました！！！！！👏👏👏';
            if($ownerUserId!==$userId){$users[]=$ownerUserId;$content.="\n".'🎁 <@'.$ownerUserId.'> さん、特別賞のお届けをお願いします！！';}
        }
        return ['content'=>$content,'allowed_mentions'=>['parse'=>[],'users'=>$users,'roles'=>[],'replied_user'=>false],'tts'=>false];
    }
    private static function id(?string $id):bool{return is_string($id)&&preg_match('/\A[1-9][0-9]{16,19}\z/D',$id)===1;}
}
