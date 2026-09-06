<?php
declare(strict_types=1);
namespace ImWiki\Storage;
final class NoopFileScanner implements FileScannerInterface{public function scan(string $path,string $originalName,string $mimeType):array{return['status'=>'not_scanned','reason'=>null];}public function name():string{return'none';}}
