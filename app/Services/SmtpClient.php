<?php
declare(strict_types=1);

namespace ImWiki\Services;

use RuntimeException;

final class SmtpClient
{
    public function send(array $config,string $to,string $subject,string $html,string $text):void
    {
        $host=(string)($config['host']??'');$port=(int)($config['port']??587);$encryption=(string)($config['encryption']??'tls');$from=(string)($config['from_address']??'');
        if($host===''||$from===''||!filter_var($to,FILTER_VALIDATE_EMAIL)||!filter_var($from,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Niepełna konfiguracja SMTP.');
        $transport=$encryption==='ssl'?'ssl://':'';$context=stream_context_create(['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'peer_name'=>$host,'allow_self_signed'=>false]]);
        $socket=@stream_socket_client($transport.$host.':'.$port,$errno,$errstr,10,STREAM_CLIENT_CONNECT,$context);if(!$socket)throw new RuntimeException('Nie można połączyć się z SMTP.');stream_set_timeout($socket,10);
        try{
            $this->expect($socket,[220]);$hostname=preg_replace('/[^a-zA-Z0-9.-]/','',gethostname()?:'imwiki')?:'imwiki';$this->command($socket,'EHLO '.$hostname,[250]);
            if($encryption==='tls'){$this->command($socket,'STARTTLS',[220]);if(!stream_socket_enable_crypto($socket,true,STREAM_CRYPTO_METHOD_TLS_CLIENT))throw new RuntimeException('Nie udało się uruchomić TLS.');$this->command($socket,'EHLO '.$hostname,[250]);}
            $username=(string)($config['username']??'');$password=(string)($config['password']??'');if($username!==''){$this->command($socket,'AUTH LOGIN',[334]);$this->command($socket,base64_encode($username),[334]);$this->command($socket,base64_encode($password),[235]);}
            $this->command($socket,'MAIL FROM:<'.$from.'>',[250]);$this->command($socket,'RCPT TO:<'.$to.'>',[250,251]);$this->command($socket,'DATA',[354]);
            $boundary='imwiki-'.bin2hex(random_bytes(8));$headers=[
                'From: '.$this->header((string)($config['from_name']??'imWiki')).' <'.$from.'>',
                'To: <'.$to.'>','Subject: '.$this->header($subject),'MIME-Version: 1.0','Content-Type: multipart/alternative; boundary="'.$boundary.'"','Date: '.date(DATE_RFC2822),'Message-ID: <'.bin2hex(random_bytes(12)).'@'.$hostname.'>'
            ];
            $body="--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$text}\r\n--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$html}\r\n--{$boundary}--\r\n";
            $data=implode("\r\n",$headers)."\r\n\r\n".$body;$data=preg_replace('/(?m)^\./','..',$data)??$data;fwrite($socket,$data."\r\n.\r\n");$this->expect($socket,[250]);$this->command($socket,'QUIT',[221]);
        }finally{fclose($socket);}
    }

    private function command($socket,string $command,array $codes):string{fwrite($socket,$command."\r\n");return $this->expect($socket,$codes);}
    private function expect($socket,array $codes):string
    {
        $response='';$lastCode=0;while(($line=fgets($socket,2048))!==false){$response.=$line;if(preg_match('/^(\d{3})([ -])/',$line,$m)){$lastCode=(int)$m[1];if($m[2]===' ')break;}}
        if(!in_array($lastCode,$codes,true))throw new RuntimeException('SMTP zwrócił błąd '.$lastCode.'.');return $response;
    }
    private function header(string $value):string{return '=?UTF-8?B?'.base64_encode(str_replace(["\r","\n"],' ',$value)).'?=';}
}
