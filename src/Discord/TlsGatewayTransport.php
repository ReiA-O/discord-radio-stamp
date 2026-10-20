<?php
declare(strict_types=1);
namespace RadioStamp\Discord;

/** One fixed TLS/WSS host; no cookies, redirects, voice connection, UDP, or raw logging. */
final class TlsGatewayTransport implements GuardedGatewayTransport
{
    private $socket=null;private WebSocketFrameCodec $codec;private array $pending=[];private ?\Closure $guard=null;
    private array $diagnostic=['stage'=>'not_started','tls_connected'=>false,'upgrade_status_101'=>false,'upgrade_verified'=>false];
    public function __construct(){$this->codec=new WebSocketFrameCodec();}
    public function setGuard(\Closure $guard):void{$this->guard=$guard;}
    /** Cooperative boundary checks cannot cancel an I/O call already in progress. */
    private function running():bool
    {
        try{$allowed=$this->guard===null||($this->guard)()===true;}catch(\Throwable){$allowed=false;}
        if(!$allowed){$this->close();}return $allowed;
    }
    private function requireRunning():void
    {
        if(!$this->running()){throw new \RuntimeException('gateway_guard_stopped');}
    }
    public function __debugInfo():array{return ['connected'=>is_resource($this->socket),'destination'=>'discord_gateway','credentials'=>'[redacted]'];}
    /** Fixed phase labels only: never expose TLS errors, addresses, certificates or HTTP headers. */
    public function diagnostics():array{return $this->diagnostic+['socket_open'=>is_resource($this->socket)];}
    public function connect():bool
    {
        $this->close();$this->codec=new WebSocketFrameCodec();
        $this->diagnostic=['stage'=>'tls_connect_failed','tls_connected'=>false,'upgrade_status_101'=>false,'upgrade_verified'=>false];
        try{
            $this->requireRunning();
            $context=stream_context_create(['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'peer_name'=>'gateway.discord.gg','SNI_enabled'=>true,'disable_compression'=>true]]);
            $this->requireRunning();$socket=@stream_socket_client('tls://gateway.discord.gg:443',$number,$detail,3,STREAM_CLIENT_CONNECT,$context);
            if(!is_resource($socket)){return false;}$this->socket=$socket;$this->requireRunning();
            $this->diagnostic['tls_connected']=true;
            stream_set_timeout($socket,2);$key=base64_encode(random_bytes(16));
            $request="GET /?v=10&encoding=json HTTP/1.1\r\nHost: gateway.discord.gg\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: ".$key."\r\nSec-WebSocket-Version: 13\r\n\r\n";
            $this->diagnostic['stage']='upgrade_request_failed';
            $this->requireRunning();if(@fwrite($socket,$request)!==strlen($request)){throw new \RuntimeException('gateway_handshake_failed');}$this->requireRunning();
            $this->diagnostic['stage']='upgrade_response_failed';
            $headers='';$deadline=microtime(true)+3;while(!str_ends_with($headers,"\r\n\r\n")){
                $this->requireRunning();if(microtime(true)>$deadline){throw new \RuntimeException('gateway_handshake_failed');}
                $line=@fgets($socket,1024);$this->requireRunning();
                if($line===false||strlen($headers)+strlen($line)>16384){throw new \RuntimeException('gateway_handshake_failed');}$headers.=$line;
            }
            $this->diagnostic['stage']='upgrade_status_rejected';
            $lines=explode("\r\n",$headers);if(!preg_match('~\AHTTP/1\.[01] 101(?: |$)~',array_shift($lines))){throw new \RuntimeException('gateway_handshake_failed');}
            $this->diagnostic['upgrade_status_101']=true;$this->diagnostic['stage']='upgrade_headers_rejected';
            $map=[];foreach($lines as$line){if(str_contains($line,':')){[$name,$value]=explode(':',$line,2);$map[strtolower(trim($name))]=trim($value);}}
            $accept=base64_encode(sha1($key.'258EAFA5-E914-47DA-95CA-C5AB0DC85B11',true));
            if(!hash_equals($accept,$map['sec-websocket-accept']??'')||strtolower($map['upgrade']??'')!=='websocket'||!str_contains(strtolower($map['connection']??''),'upgrade')||isset($map['sec-websocket-extensions'])){throw new \RuntimeException('gateway_handshake_failed');}
            $this->diagnostic['upgrade_verified']=true;$this->diagnostic['stage']='websocket_ready';
            stream_set_blocking($socket,false);$this->requireRunning();return true;
        }catch(\Throwable){$this->close();return false;}
    }
    private function write(string $bytes):bool
    {
        $end=microtime(true)+1;$written=0;
        if(!$this->running()){return false;}
        while(is_resource($this->socket)&&$written<strlen($bytes)&&microtime(true)<$end){
            if(!$this->running()){return false;}
            $read=[];$write=[$this->socket];$except=[];if(@stream_select($read,$write,$except,0,100000)!==1){continue;}
            if(!$this->running()){return false;}
            $count=@fwrite($this->socket,substr($bytes,$written));if(!$this->running()||$count===false){return false;}$written+=$count;
        }
        return $this->running()&&$written===strlen($bytes);
    }
    public function send(#[\SensitiveParameter] array $payload):bool
    {
        try{$this->requireRunning();return $this->write(WebSocketFrameCodec::client(json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)));}
        catch(\Throwable){$this->close();return false;}
    }
    public function receive(float $timeoutSeconds):?array
    {
        $end=microtime(true)+max(0,min(2,$timeoutSeconds));
        do{
            $this->requireRunning();
            while($this->pending){
                $this->requireRunning();
                $frame=array_shift($this->pending);
                if($frame['opcode']===8){$this->close();throw new \RuntimeException('gateway_closed');}
                if($frame['opcode']===9){if(!$this->write(WebSocketFrameCodec::client($frame['payload'],10))){throw new \RuntimeException('gateway_write_failed');}continue;}
                if($frame['opcode']===10){continue;}
                $data=json_decode($frame['payload'],true,64,JSON_THROW_ON_ERROR);
                if(!is_array($data)){throw new \RuntimeException('gateway_payload_invalid');}$this->requireRunning();return $data;
            }
            if(!is_resource($this->socket)||feof($this->socket)){throw new \RuntimeException('gateway_disconnected');}
            $remaining=max(0,$end-microtime(true));$read=[$this->socket];$write=[];$except=[];
            $this->requireRunning();
            $ready=@stream_select($read,$write,$except,(int)$remaining,(int)(($remaining-floor($remaining))*1000000));
            $this->requireRunning();
            if($ready===false){throw new \RuntimeException('gateway_read_failed');}if($ready===0){return null;}
            $chunk=@fread($this->socket,65536);$this->requireRunning();if($chunk===false||$chunk===''&&feof($this->socket)){throw new \RuntimeException('gateway_disconnected');}
            $this->pending=array_merge($this->pending,$this->codec->push($chunk));
        }while(microtime(true)<$end||$this->pending);
        $this->requireRunning();return null;
    }
    public function close():void
    {
        if(is_resource($this->socket)){@fclose($this->socket);}$this->socket=null;$this->pending=[];
    }
    public function __destruct(){$this->close();}
}
