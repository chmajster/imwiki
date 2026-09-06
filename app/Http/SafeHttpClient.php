<?php
declare(strict_types=1);

namespace ImWiki\Http;

use ImWiki\Security\SsrfGuard;
use RuntimeException;

final class SafeHttpClient
{
    public function __construct(private readonly SsrfGuard $guard,private readonly int $timeout=5,private readonly int $maxBodyBytes=2097152){}

    public function getJson(string $url,array $headers=[]):array
    {
        $response=$this->request('GET',$url,$headers,'',false);$data=json_decode($response['body'],true,512,JSON_THROW_ON_ERROR);if(!is_array($data))throw new RuntimeException('Invalid JSON response.');return$data;
    }

    public function request(string $method,string $url,array $headers=[],string $body='',bool $allowRedirects=false,int $redirects=0):array
    {
        $method=strtoupper($method);if(!in_array($method,['GET','POST','PUT','PATCH','DELETE'],true))throw new RuntimeException('Unsupported HTTP method.');if(strlen($body)>$this->maxBodyBytes)throw new RuntimeException('Outbound body too large.');$dest=$this->guard->validate($url);$tls=$dest['scheme']==='https';$context=stream_context_create(['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'peer_name'=>$dest['host'],'SNI_enabled'=>true,'disable_compression'=>true]]);$target=($tls?'tls':'tcp').'://'.(str_contains($dest['ip'],':')?'['.$dest['ip'].']':$dest['ip']).':'.$dest['port'];$errno=0;$err='';$socket=@stream_socket_client($target,$errno,$err,$this->timeout,STREAM_CLIENT_CONNECT,$context);if(!$socket)throw new RuntimeException('Outbound connection failed.');stream_set_timeout($socket,$this->timeout);
        $host=$dest['host'].((($tls&&$dest['port']!==443)||(!$tls&&$dest['port']!==80))?':'.$dest['port']:'');$safeHeaders=['Host'=>$host,'User-Agent'=>'imWiki/'.(defined('IMWIKI_VERSION')?IMWIKI_VERSION:'dev'),'Accept'=>'application/json','Connection'=>'close'];foreach($headers as $k=>$v){$k=trim((string)$k);if(!preg_match('/^[A-Za-z0-9-]{1,80}$/',$k)||preg_match('/[\r\n]/',(string)$v))continue;if(strcasecmp($k,'Host')===0||strcasecmp($k,'Content-Length')===0)continue;$safeHeaders[$k]=(string)$v;}if($body!=='')$safeHeaders['Content-Length']=(string)strlen($body);
        $request=$method.' '.$dest['path']." HTTP/1.1\r\n";foreach($safeHeaders as $k=>$v)$request.=$k.': '.$v."\r\n";$request.="\r\n".$body;fwrite($socket,$request);
        $statusLine=fgets($socket,4096)?:'';if(!preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#',$statusLine,$m)){fclose($socket);throw new RuntimeException('Invalid HTTP response.');}$status=(int)$m[1];$responseHeaders=[];while(($line=fgets($socket,8192))!==false){$line=rtrim($line,"\r\n");if($line==='')break;$pos=strpos($line,':');if($pos!==false)$responseHeaders[mb_strtolower(trim(substr($line,0,$pos)))]=trim(substr($line,$pos+1));}
        $raw='';while(!feof($socket)&&strlen($raw)<=$this->maxBodyBytes){$chunk=fread($socket,min(65536,$this->maxBodyBytes+1-strlen($raw)));if($chunk===false||$chunk==='')break;$raw.=$chunk;}fclose($socket);if(strlen($raw)>$this->maxBodyBytes)throw new RuntimeException('Outbound response too large.');if(($responseHeaders['transfer-encoding']??'')==='chunked')$raw=$this->decodeChunked($raw);
        if($status>=300&&$status<400&&isset($responseHeaders['location'])){if(!$allowRedirects||$redirects>=2)throw new RuntimeException('Outbound redirect refused.');$next=$this->resolveRedirect($url,$responseHeaders['location']);return$this->request($method,$next,$headers,$body,true,$redirects+1);}if($status<200||$status>=300)throw new RuntimeException('Remote service returned HTTP '.$status.'.');return['status'=>$status,'headers'=>$responseHeaders,'body'=>$raw];
    }

    private function decodeChunked(string $raw):string
    {
        $out='';$offset=0;$len=strlen($raw);while($offset<$len){$eol=strpos($raw,"\r\n",$offset);if($eol===false)break;$sizeHex=trim(substr($raw,$offset,$eol-$offset));$semi=strpos($sizeHex,';');if($semi!==false)$sizeHex=substr($sizeHex,0,$semi);if(!ctype_xdigit($sizeHex))throw new RuntimeException('Invalid chunked response.');$size=hexdec($sizeHex);$offset=$eol+2;if($size===0)break;if($offset+$size>$len)throw new RuntimeException('Truncated chunked response.');$out.=substr($raw,$offset,$size);if(strlen($out)>$this->maxBodyBytes)throw new RuntimeException('Outbound response too large.');$offset+=$size+2;}return$out;
    }

    private function resolveRedirect(string $base,string $location):string
    {
        if(preg_match('#^https?://#i',$location))return$location;$parts=parse_url($base);if(!$parts||!isset($parts['scheme'],$parts['host']))throw new RuntimeException('Invalid redirect.');$port=isset($parts['port'])?':'.$parts['port']:'';$path=str_starts_with($location,'/')?$location:rtrim(dirname((string)($parts['path']??'/')),'/').'/'.$location;return$parts['scheme'].'://'.$parts['host'].$port.$path;
    }
}
