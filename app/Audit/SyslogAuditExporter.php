<?php
declare(strict_types=1);
namespace ImWiki\Audit;
use ImWiki\Support\SecretMasker;
final class SyslogAuditExporter implements AuditExporterInterface{public function __construct(private readonly string $ident='imwiki'){}public function name():string{return'syslog';}public function export(array $event):void{$safe=SecretMasker::mask($event);openlog($this->ident,LOG_PID|LOG_NDELAY,LOG_AUTH);syslog(LOG_INFO,json_encode($safe,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?:'{}');closelog();}}
