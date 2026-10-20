<?php
declare(strict_types=1);
namespace RadioStamp\Discord;

/** Pure guild-command response inspection. Never returns response values or performs I/O. */
final class CommandResponseInspector
{
    private const COMMAND = ['type','name','description','default_member_permissions','nsfw','name_localizations','description_localizations','options'];
    private const OPTION = ['type','name','description','required','autocomplete','name_localizations','description_localizations','choices','options','channel_types','min_value','max_value','min_length','max_length','file_types'];
    private const CHOICE = ['name','value','name_localizations'];

    public static function inspect(int $status, #[\SensitiveParameter] string $body, array $expected): array
    {
        $result = ['http_accepted'=>in_array($status,[200,201],true),'json_parsed'=>false,'json_error'=>'none','object'=>false,
            'id_present'=>false,'id_valid'=>false,'application_matches'=>null,'guild_matches'=>null,
            'definition_matches'=>false,'fields'=>[],'mismatches'=>[],'truncated'=>false,'verified'=>false];
        if (strlen($body)>1048576) { $result['json_error']='too_large'; return $result; }
        try { $object=json_decode($body,false,32,JSON_THROW_ON_ERROR); }
        catch (\JsonException $error) { $result['json_error']=$error->getCode()===JSON_ERROR_DEPTH?'depth':'syntax'; return $result; }
        $result['json_parsed']=true;
        if (!$object instanceof \stdClass) { return $result; }
        $result['object']=true; $actual=get_object_vars($object);
        $result['id_present']=array_key_exists('id',$actual);
        $result['id_valid']=is_string($actual['id']??null)&&preg_match('/\A[1-9][0-9]{16,19}\z/D',$actual['id'])===1;
        $result['application_matches']=($actual['application_id']??null)===DiscordRestClient::APPLICATION_ID;
        $result['guild_matches']=($actual['guild_id']??null)===DiscordRestClient::GUILD_ID;
        $comparison=self::compare($actual,$expected);
        $result['definition_matches']=$comparison['matches'];
        foreach (['fields','mismatches','truncated'] as $key) { $result[$key]=$comparison[$key]; }
        $result['verified']=$result['http_accepted']&&$result['id_valid']&&$result['application_matches']&&$result['guild_matches']&&$result['definition_matches'];
        return $result;
    }

    /** Full-localization collection GET; reports only fixed expected names and classifications. */
    public static function inspectCollection(int $status, #[\SensitiveParameter] string $body, array $definitions): array
    {
        $result=['http_accepted'=>$status===200,'json_parsed'=>false,'json_error'=>'none','array'=>false,'shape_valid'=>false,'scope_valid'=>false,'count'=>0,'commands'=>[],'valid'=>false];
        if(strlen($body)>1048576){$result['json_error']='too_large';return $result;}
        try{$rows=json_decode($body,false,32,JSON_THROW_ON_ERROR);}
        catch(\JsonException $error){$result['json_error']=$error->getCode()===JSON_ERROR_DEPTH?'depth':'syntax';return $result;}
        $result['json_parsed']=true;
        if(!is_array($rows)||count($rows)>200){return $result;}
        $result['array']=true;$result['count']=count($rows);$shape=true;$scope=true;
        foreach($rows as$row){
            if(!$row instanceof \stdClass||!is_string($row->name??null)){$shape=false;continue;}
            if(!is_string($row->id??null)||preg_match('/\A[1-9][0-9]{16,19}\z/D',$row->id)!==1
                ||($row->application_id??null)!==DiscordRestClient::APPLICATION_ID||($row->guild_id??null)!==DiscordRestClient::GUILD_ID){$scope=false;}
        }
        $result['shape_valid']=$shape;$result['scope_valid']=$scope;$duplicates=false;
        foreach($definitions as$definition){
            $name=$definition['name']??null;
            if(!in_array($name,['radio-card','radio-status','radio-attend'],true)){$result['shape_valid']=false;continue;}
            $matches=array_values(array_filter($rows,static fn(mixed $row):bool=>$row instanceof \stdClass&&($row->name??null)===$name));
            $count=count($matches);if($count>1){$duplicates=true;}
            $result['commands'][$name]=['count'=>$count,'inspection'=>$count===1?self::inspect(200,json_encode($matches[0],JSON_THROW_ON_ERROR),$definition):null];
        }
        $result['valid']=$result['http_accepted']&&$result['shape_valid']&&$scope&&!$duplicates;
        return $result;
    }

    /** Compare managed semantic fields; omit response-only IDs, version and localized display metadata. */
    public static function compare(#[\SensitiveParameter] array $actual, array $expected): array
    {
        $fields=[]; $truncated=false;
        self::node($actual,$expected,'command','',0,$fields,$truncated);
        $mismatches=array_keys(array_filter($fields,static fn(string $state):bool=>$state!=='match'));
        return ['matches'=>$mismatches===[]&&!$truncated,'fields'=>$fields,'mismatches'=>$mismatches,'truncated'=>$truncated];
    }

    private static function put(array &$fields,bool &$truncated,string $path,string $state): void
    {
        if(count($fields)>=256){$truncated=true;return;}
        $fields[$path]=$state;
    }

    private static function node(array $actual,array $expected,string $kind,string $prefix,int $depth,array &$fields,bool &$truncated): void
    {
        if($depth>4){self::put($fields,$truncated,$prefix.'shape','depth');return;}
        $keys=match($kind){'command'=>self::COMMAND,'option'=>self::OPTION,default=>self::CHOICE};
        if(array_diff(array_keys($expected),$keys)!==[]){self::put($fields,$truncated,$prefix.'definition_schema','invalid_expected');}
        $defaults=match($kind){
            'command'=>['type'=>1,'nsfw'=>false,'name_localizations'=>null,'description_localizations'=>null,'options'=>[]],
            'option'=>['required'=>false,'autocomplete'=>false,'name_localizations'=>null,'description_localizations'=>null,'choices'=>[],'options'=>[]],
            default=>['name_localizations'=>null],
        };
        foreach($keys as$key){
            $path=$prefix.$key;
            $ap=array_key_exists($key,$actual);$ep=array_key_exists($key,$expected);
            $av=$ap?$actual[$key]:($defaults[$key]??null);$ev=$ep?$expected[$key]:($defaults[$key]??null);
            if(array_key_exists($key,$defaults)){$ap=$ep=true;}
            if(!$ap&&!$ep){continue;}
            if(!$ap){self::put($fields,$truncated,$path,'missing');continue;}
            if(!$ep){self::put($fields,$truncated,$path,'unexpected');continue;}
            if(in_array($key,['options','choices'],true)){
                if(!is_array($ev)||!array_is_list($ev)||count($ev)>25){self::put($fields,$truncated,$path,'invalid_expected');continue;}
                if(!is_array($av)||!array_is_list($av)||count($av)>25){self::put($fields,$truncated,$path,'invalid_type');continue;}
                self::put($fields,$truncated,$path.'.count',count($av)===count($ev)?'match':'different');
                foreach($ev as$i=>$child){
                    if(!array_key_exists($i,$av)){continue;}
                    $aa=self::map($av[$i]);$ee=self::map($child);
                    if($ee===null){self::put($fields,$truncated,$path.'['.$i.'].shape','invalid_expected');continue;}
                    if($aa===null){self::put($fields,$truncated,$path.'['.$i.'].shape','invalid_type');continue;}
                    self::node($aa,$ee,$key==='choices'?'choice':'option',$path.'['.$i.'].',$depth+1,$fields,$truncated);
                }
                continue;
            }
            if(in_array($key,['name_localizations','description_localizations'],true)){
                $base=$key==='name_localizations'?'name':'description';
                [$aok,$av]=self::localizations($av,$actual[$base]??null);
                [$eok,$ev]=self::localizations($ev,$expected[$base]??null);
                self::put($fields,$truncated,$path,!$eok?'invalid_expected':(!$aok?'invalid_type':($av===$ev?'match':'different')));
                continue;
            }
            $valid=static function(mixed $value)use($key):bool{
                return match($key){
                    'type','min_length','max_length'=>is_int($value),
                    'nsfw','required','autocomplete'=>is_bool($value),
                    'name','description'=>is_string($value),
                    'default_member_permissions'=>$value===null||(is_string($value)&&preg_match('/\A(?:0|[1-9][0-9]*)\z/D',$value)===1),
                    'min_value','max_value'=>is_int($value)||is_float($value),
                    'channel_types'=>is_array($value)&&array_is_list($value)&&count(array_filter($value,is_int(...)))===count($value),
                    'file_types'=>is_array($value)&&array_is_list($value)&&count(array_filter($value,is_string(...)))===count($value),
                    'value'=>is_string($value)||is_int($value)||is_float($value),
                    default=>false,
                };
            };
            self::put($fields,$truncated,$path,!$valid($ev)?'invalid_expected':(!$valid($av)?'invalid_type':($av===$ev?'match':'different')));
        }
    }

    private static function map(mixed $value): ?array
    {
        if($value instanceof \stdClass){return get_object_vars($value);}
        return is_array($value)&&(!array_is_list($value)||$value===[])?$value:null;
    }

    private static function localizations(mixed $value,mixed $base): array
    {
        if($value===null){return [true,null];}
        $map=self::map($value);if($map===null){return [false,null];}
        foreach($map as$key=>$text){
            if(!is_string($key)||!is_string($text)){return [false,null];}
            // Discord documents removal of localized values identical to the base value.
            if($text===$base){unset($map[$key]);}
        }
        ksort($map,SORT_STRING);return [true,$map===[]?null:$map];
    }
}
