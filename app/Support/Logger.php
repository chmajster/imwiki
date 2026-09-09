<?php
declare(strict_types=1);

namespace ImWiki\Support;

final class Logger
{
    public function __construct(private readonly string $logDir, private readonly bool $debug = false) {}

    public function debug(string $message, array $context = []): void
    {
        if ($this->debug) $this->write('DEBUG', $message, $context);
    }

    public function info(string $message, array $context = []): void {$this->write('INFO', $message, $context);}
    public function warning(string $message, array $context = []): void {$this->write('WARNING', $message, $context);}
    public function error(string $message, array $context = []): void {$this->write('ERROR', $message, $context);}

    private function write(string $level, string $message, array $context): void
    {
        if (!is_dir($this->logDir)) @mkdir($this->logDir, 0770, true);
        $context=$this->sanitize($context);
        $line=sprintf("[%s] %s %s %s\n",gmdate('c'),$level,str_replace(["\r","\n"],' ',$message),$context?json_encode($context,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):'');
        $path=$this->logDir.'/imwiki-'.gmdate('Y-m-d').'.log';
        @file_put_contents($path,$line,FILE_APPEND|LOCK_EX);
        @chmod($path,0640);
    }

    private function sanitize(array $context):array
    {
        $blocked=['password','db_password','smtp_password','csrf','session','authorization','cookie','token','secret'];
        $walk=static function(array $data)use(&$walk,$blocked):array{
            $out=[];
            foreach($data as $key=>$value){
                $lower=mb_strtolower((string)$key);
                if(array_filter($blocked,static fn(string $needle):bool=>str_contains($lower,$needle)))continue;
                $out[$key]=is_array($value)?$walk($value):$value;
            }
            return $out;
        };
        return $walk($context);
    }
}
