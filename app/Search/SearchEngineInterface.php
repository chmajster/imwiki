<?php
declare(strict_types=1);
namespace ImWiki\Search;
interface SearchEngineInterface{public function key():string;public function search(string $query,int $userId,int $limit=50,array $filters=[]):array;public function rebuildBatch(int $cursorId=0,int $limit=250):array;public function status():array;}
