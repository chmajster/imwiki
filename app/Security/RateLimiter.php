<?php
declare(strict_types=1);

namespace ImWiki\Security;

use RuntimeException;

final class RateLimiter
{
    public function __construct(private readonly string $dir)
    {
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new RuntimeException('Nie można utworzyć katalogu rate limitera.');
        }
    }

    public function tooManyAttempts(string $key, int $limit, int $windowSeconds): bool
    {
        $limit=max(1,$limit);$windowSeconds=max(1,$windowSeconds);
        $file=$this->dir.'/'.hash('sha256',$key).'.json';
        $fh=@fopen($file,'c+');
        if(!$fh)throw new RuntimeException('Rate limiter storage is unavailable.');
        try{
            if(!flock($fh,LOCK_EX))throw new RuntimeException('Rate limiter lock failed.');
            rewind($fh);$raw=stream_get_contents($fh);$now=time();$data=['start'=>$now,'count'=>0];
            if(is_string($raw)&&$raw!==''){$decoded=json_decode($raw,true);if(is_array($decoded))$data=$decoded+$data;}
            if(((int)$data['start']+$windowSeconds)<=$now)$data=['start'=>$now,'count'=>0];
            if((int)$data['count']>=$limit)return true;
            $data['count']=(int)$data['count']+1;
            rewind($fh);ftruncate($fh,0);fwrite($fh,json_encode($data,JSON_THROW_ON_ERROR));fflush($fh);@chmod($file,0640);
            return false;
        }finally{
            @flock($fh,LOCK_UN);fclose($fh);
        }
    }
}
