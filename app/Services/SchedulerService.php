<?php
declare(strict_types=1);

namespace ImWiki\Services;

final class SchedulerService
{
    public function __construct(private readonly RetentionService $retention,private readonly DigestService $digest,private readonly string $stateDir){}
    public function opportunistic(): array
    {
        if(!is_dir($this->stateDir))@mkdir($this->stateDir,0770,true);$lock=$this->stateDir.'/scheduler-hourly.lock';
        $fh=@fopen($lock,'c+');if(!$fh||!flock($fh,LOCK_EX|LOCK_NB)){if($fh)fclose($fh);return ['ran'=>false];}
        try{$last=(int)trim((string)stream_get_contents($fh));if($last>time()-3600)return ['ran'=>false];ftruncate($fh,0);rewind($fh);fwrite($fh,(string)time());fflush($fh);$queued=$this->digest->opportunistic(25);$clean=$this->retention->cleanup();return ['ran'=>true,'digests'=>$queued,'cleanup'=>$clean];}
        finally{flock($fh,LOCK_UN);fclose($fh);}
    }
    public function runNow(): array { return ['digests'=>$this->digest->opportunistic(100),'cleanup'=>$this->retention->cleanup()]; }
}
