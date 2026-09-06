<?php
declare(strict_types=1);
namespace ImWiki\Audit;
interface AuditExporterInterface{public function name():string;public function export(array $event):void;}
