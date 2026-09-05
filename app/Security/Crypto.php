<?php
declare(strict_types=1);

namespace ImWiki\Security;

use RuntimeException;

final class Crypto
{
    public function __construct(private readonly string $appSecret)
    {
        if(strlen($appSecret)<32)throw new RuntimeException('APP secret is too short.');
    }

    public function encrypt(string $plaintext):string
    {
        $key=hash('sha256',$this->appSecret,true);$iv=random_bytes(12);$tag='';
        $cipher=openssl_encrypt($plaintext,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,'imwiki',16);
        if($cipher===false)throw new RuntimeException('Encryption failed.');
        return rtrim(strtr(base64_encode($iv.$tag.$cipher),'+/','-_'),'=');
    }

    public function decrypt(string $encoded):string
    {
        $raw=base64_decode(strtr($encoded,'-_','+/'),true);
        if($raw===false||strlen($raw)<29)throw new RuntimeException('Invalid encrypted payload.');
        $iv=substr($raw,0,12);$tag=substr($raw,12,16);$cipher=substr($raw,28);$key=hash('sha256',$this->appSecret,true);
        $plain=openssl_decrypt($cipher,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,'imwiki');
        if($plain===false)throw new RuntimeException('Decryption failed.');return $plain;
    }
}
