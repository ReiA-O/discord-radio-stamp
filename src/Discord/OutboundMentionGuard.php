<?php
declare(strict_types=1);
namespace RadioStamp\Discord;

/** One shared boundary for REST requests and interaction callback responses. */
final class OutboundMentionGuard
{
    public static function assertRequest(string $method,string $path,array $headers,string $body,?OpeningNoticeScope $scope=null):void
    {
        if($body===''){return;}
        $types=[];
        foreach($headers as$header){if(preg_match('/\AContent-Type\s*:\s*(.+)\z/i',$header,$match)===1){$types[]=$match[1];}}
        if(count($types)>1){self::reject();}
        $type=$types[0]??'application/json';
        if(preg_match('/\Aapplication\/json(?:\s*;\s*charset=utf-8)?\z/i',$type)===1){
            // Keep the legacy scope argument compatible; it grants no broadcast exemption.
            self::json($body);return;
        }
        if(preg_match('/\Amultipart\/form-data;\s*boundary=([A-Za-z0-9_-]{1,70})\z/i',$type,$match)!==1){self::reject();}
        // This app supports only its existing one-PNG card form. Reject ambiguous or unknown parts.
        $boundary=$match[1];
        $prefix='--'.$boundary."\r\nContent-Disposition: form-data; name=\"payload_json\"\r\nContent-Type: application/json\r\n\r\n";
        $middle="\r\n--".$boundary."\r\nContent-Disposition: form-data; name=\"files[0]\"; filename=\"card.png\"\r\nContent-Type: image/png\r\n\r\n";
        $suffix="\r\n--".$boundary."--\r\n";
        if(!str_starts_with($body,$prefix)||!str_ends_with($body,$suffix)||substr_count($body,'--'.$boundary)!==3){self::reject();}
        $split=strpos($body,$middle,strlen($prefix));
        if($split===false){self::reject();}
        $json=substr($body,strlen($prefix),$split-strlen($prefix));
        $png=substr($body,$split+strlen($middle),-strlen($suffix));
        if(!str_starts_with($png,"\x89PNG\r\n\x1a\n")){self::reject();}
        self::json($json);
    }

    public static function assertJson(string $json):void{self::json($json);}

    private static function json(string $json):void
    {
        try{$value=json_decode($json,false,32,JSON_THROW_ON_ERROR);}
        catch(\Throwable){self::reject();}
        if(!is_array($value)&&!$value instanceof \stdClass){self::reject();}
        // Inspect every lexical string too: duplicate object keys must not hide an escaped token.
        preg_match_all('/"(?:[^"\\\\]|\\\\.)*"/s',$json,$strings);
        foreach($strings[0]as$encoded){
            $text=json_decode($encoded,false,32,JSON_THROW_ON_ERROR);
            if(preg_match('/@everyone|@here/i',$text)===1){self::reject();}
        }
        self::walk($value);
    }

    private static function walk(mixed $value):void
    {
        if(!is_array($value)&&!$value instanceof \stdClass){return;}
        foreach($value as$key=>$child){
            if($key==='allowed_mentions'){self::mentions($child);}
            self::walk($child);
        }
    }

    private static function mentions(mixed $value):void
    {
        if(!$value instanceof \stdClass||array_diff(array_keys(get_object_vars($value)),['parse','users','roles','replied_user'])!==[]){self::reject();}
        $parse=property_exists($value,'parse')?$value->parse:[];
        if(!is_array($parse)||!array_is_list($parse)||count(array_unique($parse,SORT_REGULAR))!==count($parse)){self::reject();}
        foreach($parse as$type){if(!is_string($type)||!in_array($type,['users','roles'],true)){self::reject();}}
        foreach(['users','roles']as$key){
            if(!property_exists($value,$key)){continue;}$ids=$value->$key;
            if(!is_array($ids)||!array_is_list($ids)||count($ids)>100||count(array_unique($ids,SORT_REGULAR))!==count($ids)||($ids!==[]&&in_array($key,$parse,true))){self::reject();}
            foreach($ids as$id){if(!is_string($id)||preg_match('/\A[1-9][0-9]{16,19}\z/D',$id)!==1){self::reject();}}
        }
        if(property_exists($value,'replied_user')&&!is_bool($value->replied_user)){self::reject();}
    }

    private static function reject():never{throw new \InvalidArgumentException('discord_mentions_rejected');}
}
