<?php
declare(strict_types=1);
namespace RadioStamp\Discord;

/** RFC6455 framing only, uncompressed text. Does not retain frames in logs/debug output. */
final class WebSocketFrameCodec
{
    private string $buffer='';private ?string $fragment=null;
    public function __debugInfo():array{return ['buffered_bytes'=>strlen($this->buffer),'fragmented'=>$this->fragment!==null];}
    public static function client(string $payload,int $opcode=1):string
    {
        $length=strlen($payload);if($length>65536||!in_array($opcode,[1,8,9,10],true)||($opcode>=8&&$length>125)){throw new \InvalidArgumentException('websocket_frame_invalid');}
        $mask=random_bytes(4);$head=chr(0x80|$opcode);
        $head.=$length<126?chr(0x80|$length):($length<=65535?chr(0x80|126).pack('n',$length):chr(0x80|127).pack('NN',0,$length));
        $masked='';for($i=0;$i<$length;$i++){$masked.=$payload[$i]^$mask[$i%4];}
        return $head.$mask.$masked;
    }
    public function push(string $bytes):array
    {
        $this->buffer.=$bytes;if(strlen($this->buffer)>2162702){throw new \RuntimeException('websocket_frame_too_large');}
        $events=[];
        while(strlen($this->buffer)>=2){
            $a=ord($this->buffer[0]);$b=ord($this->buffer[1]);$fin=($a&128)!==0;$opcode=$a&15;$length=$b&127;$offset=2;
            if(($a&112)!==0||($b&128)!==0||!in_array($opcode,[0,1,8,9,10],true)){throw new \RuntimeException('websocket_frame_invalid');}
            if($length===126){if(strlen($this->buffer)<4){break;}$length=unpack('n',substr($this->buffer,2,2))[1];$offset=4;if($length<126){throw new \RuntimeException('websocket_frame_invalid');}}
            elseif($length===127){if(strlen($this->buffer)<10){break;}$parts=unpack('Nhigh/Nlow',substr($this->buffer,2,8));if($parts['high']!==0||$parts['low']<65536){throw new \RuntimeException('websocket_frame_invalid');}$length=$parts['low'];$offset=10;}
            if($length>2097152||($opcode>=8&&(!$fin||$length>125))){throw new \RuntimeException('websocket_frame_invalid');}
            if(strlen($this->buffer)<$offset+$length){break;}
            $payload=substr($this->buffer,$offset,$length);$this->buffer=substr($this->buffer,$offset+$length);
            if($opcode>=8){if($opcode===8&&$length===1){throw new \RuntimeException('websocket_close_invalid');}$events[]=['opcode'=>$opcode,'payload'=>$payload];continue;}
            if($opcode===0){if($this->fragment===null){throw new \RuntimeException('websocket_continuation_invalid');}$this->fragment.=$payload;}
            else{if($this->fragment!==null){throw new \RuntimeException('websocket_fragment_invalid');}$this->fragment=$payload;}
            if(strlen($this->fragment)>2097152){throw new \RuntimeException('websocket_frame_too_large');}
            if($fin){if(!mb_check_encoding($this->fragment,'UTF-8')){throw new \RuntimeException('websocket_text_invalid');}$events[]=['opcode'=>1,'payload'=>$this->fragment];$this->fragment=null;}
        }
        return $events;
    }
}
