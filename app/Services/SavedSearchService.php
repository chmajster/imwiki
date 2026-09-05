<?php
declare(strict_types=1);
namespace ImWiki\Services;
use PDO;
final class SavedSearchService{
 public function __construct(private readonly PDO $pdo,private readonly string $prefix){}
 public function all(int $userId):array{$s=$this->pdo->prepare("SELECT * FROM `{$this->prefix}saved_searches` WHERE user_id=? ORDER BY name LIMIT 100");$s->execute([$userId]);return$s->fetchAll();}
 public function save(int $userId,string $name,string $query):void{$name=trim($name);$query=trim($query);if($name===''||mb_strlen($name)>190||$query===''||mb_strlen($query)>1000)throw new \InvalidArgumentException('Nieprawidłowe zapisane wyszukiwanie.');$s=$this->pdo->prepare("INSERT INTO `{$this->prefix}saved_searches` (user_id,name,query_text,filters_json,sort_key,created_at,updated_at) VALUES (?,?,?,NULL,'relevance',UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE query_text=VALUES(query_text),updated_at=UTC_TIMESTAMP()");$s->execute([$userId,$name,$query]);}
 public function remove(int $userId,int $id):void{$this->pdo->prepare("DELETE FROM `{$this->prefix}saved_searches` WHERE id=? AND user_id=?")->execute([$id,$userId]);}
}
