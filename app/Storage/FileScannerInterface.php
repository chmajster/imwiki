<?php
declare(strict_types=1);
namespace ImWiki\Storage;
interface FileScannerInterface{/** @return array{status:string,reason:?string} */public function scan(string $path,string $originalName,string $mimeType):array;public function name():string;}
