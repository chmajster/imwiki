<?php
declare(strict_types=1);

namespace ImWiki\Services;

use ImWiki\Security\Crypto;
use PDO;

final class MailService
{
    public function __construct(private readonly PDO $pdo,private readonly string $prefix,private readonly Crypto $crypto,private readonly SmtpClient $smtp){}
    public function settings():array
    {
        $stmt=$this->pdo->query("SELECT setting_key,setting_value FROM `{$this->prefix}settings` WHERE setting_key LIKE 'mail.%'");$raw=[];foreach($stmt->fetchAll() as $r)$raw[(string)$r['setting_key']]=(string)($r['setting_value']??'');
        $password='';if(($raw['mail.password']??'')!==''){try{$password=$this->crypto->decrypt($raw['mail.password']);}catch(\Throwable){$password='';}}
        return ['host'=>$raw['mail.host']??'','port'=>(int)($raw['mail.port']??587),'username'=>$raw['mail.username']??'','password'=>$password,'encryption'=>$raw['mail.encryption']??'tls','from_address'=>$raw['mail.from_address']??'','from_name'=>$raw['mail.from_name']??'imWiki'];
    }
    public function configured():bool{$c=$this->settings();return $c['host']!==''&&filter_var($c['from_address'],FILTER_VALIDATE_EMAIL)!==false;}
    public function save(array $input):void
    {
        $current=$this->settings();$password=(string)($input['password']??'');if($password==='')$password=(string)$current['password'];
        $values=['mail.host'=>trim((string)($input['host']??'')),'mail.port'=>(string)max(1,min(65535,(int)($input['port']??587))),'mail.username'=>trim((string)($input['username']??'')),'mail.password'=>$password===''?'':$this->crypto->encrypt($password),'mail.encryption'=>in_array((string)($input['encryption']??'tls'),['none','tls','ssl'],true)?(string)$input['encryption']:'tls','mail.from_address'=>mb_strtolower(trim((string)($input['from_address']??''))),'mail.from_name'=>trim((string)($input['from_name']??'imWiki'))];
        $stmt=$this->pdo->prepare("INSERT INTO `{$this->prefix}settings` (setting_key,setting_value,is_secret,updated_at) VALUES (?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),is_secret=VALUES(is_secret),updated_at=VALUES(updated_at)");foreach($values as $key=>$value)$stmt->execute([$key,$value,$key==='mail.password'?1:0]);
    }
    public function send(string $to,string $subject,string $html,string $text):void{$this->smtp->send($this->settings(),$to,$subject,$html,$text);}
}
