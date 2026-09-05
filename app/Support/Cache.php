<?php
declare(strict_types=1);

namespace ImWiki\Support;

final class Cache
{
    public function __construct(private readonly string $dir)
    {
        if (!is_dir($this->dir)) @mkdir($this->dir,0770,true);
    }

    public function get(string $key, int $ttlSeconds, callable $loader): mixed
    {
        $path=$this->path($key);
        if(is_file($path) && (time()-(int)filemtime($path))<$ttlSeconds){
            $raw=@file_get_contents($path);
            if(is_string($raw)){
                $data=@unserialize($raw,['allowed_classes'=>false]);
                if(is_array($data) && array_key_exists('value',$data)) return $data['value'];
            }
        }
        $value=$loader();
        $tmp=$path.'.'.bin2hex(random_bytes(4)).'.tmp';
        @file_put_contents($tmp,serialize(['value'=>$value]),LOCK_EX);
        @chmod($tmp,0660);
        @rename($tmp,$path);
        return $value;
    }

    public function forget(string $key): void { @unlink($this->path($key)); }

    public function clear(): int
    {
        $count=0;
        foreach(glob(rtrim($this->dir,'/').'/cache-*.bin')?:[] as $file){if(is_file($file)&&@unlink($file))$count++;}
        return $count;
    }

    public function status(): array
    {
        $files=glob(rtrim($this->dir,'/').'/cache-*.bin')?:[];$bytes=0;
        foreach($files as $f)$bytes+=(int)(@filesize($f)?:0);
        return ['writable'=>is_dir($this->dir)&&is_writable($this->dir),'entries'=>count($files),'bytes'=>$bytes];
    }

    private function path(string $key): string { return rtrim($this->dir,'/').'/cache-'.hash('sha256',$key).'.bin'; }
}
