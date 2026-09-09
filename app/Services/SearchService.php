<?php
declare(strict_types=1);

namespace ImWiki\Services;

use PDO;

final class SearchService
{
    public function __construct(private readonly PDO $pdo,private readonly string $prefix){}

    public function parse(string $input):array
    {
        return SearchQuery::parse($input);
    }

    public function search(string $input,int $limit=50):array
    {
        return SearchQuery::run($this->pdo,$this->prefix,$input,0,true,$limit);
    }

    public function searchVisible(int $userId,bool $admin,string $input,int $limit=50):array
    {
        return SearchQuery::run($this->pdo,$this->prefix,$input,$userId,$admin,$limit);
    }
}
