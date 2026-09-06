<?php
declare(strict_types=1);

namespace ImWiki\Support;

use PDO;

final class FeatureFlags
{
    private array $cache=[];
    public function __construct(private readonly PDO $pdo,private readonly string $prefix=''){}

    public function enabled(string $key,?int $spaceId=null):bool
    {
        if(!preg_match('/^[a-z0-9_.-]{1,100}$/',$key))return false;
        $cacheKey=$key.':'.($spaceId??0);if(array_key_exists($cacheKey,$this->cache))return $this->cache[$cacheKey];
        if($spaceId){$s=$this->pdo->prepare("SELECT enabled FROM `{$this->prefix}feature_flags` WHERE flag_key=? AND scope_type='space' AND space_id=? LIMIT 1");$s->execute([$key,$spaceId]);$v=$s->fetchColumn();if($v!==false)return $this->cache[$cacheKey]=(bool)$v;}
        $s=$this->pdo->prepare("SELECT enabled FROM `{$this->prefix}feature_flags` WHERE flag_key=? AND scope_type='global' AND space_id IS NULL LIMIT 1");$s->execute([$key]);return $this->cache[$cacheKey]=(bool)($s->fetchColumn()?:0);
    }

    public function set(string $key,bool $enabled,int $actorId,?int $spaceId=null):void
    {
        if(!preg_match('/^[a-z0-9_.-]{1,100}$/',$key))throw new \InvalidArgumentException('Invalid feature flag key.');
        if($spaceId){$sql="INSERT INTO `{$this->prefix}feature_flags` (flag_key,scope_type,space_id,enabled,updated_by,updated_at) VALUES (?,'space',?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),updated_by=VALUES(updated_by),updated_at=UTC_TIMESTAMP()";$this->pdo->prepare($sql)->execute([$key,$spaceId,$enabled?1:0,$actorId]);}
        else{$sql="INSERT INTO `{$this->prefix}feature_flags` (flag_key,scope_type,space_id,enabled,updated_by,updated_at) VALUES (?,'global',NULL,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),updated_by=VALUES(updated_by),updated_at=UTC_TIMESTAMP()";$this->pdo->prepare($sql)->execute([$key,$enabled?1:0,$actorId]);}
        $this->cache=[];
    }

    public function all():array
    {
        $rows=$this->pdo->query("SELECT f.*,s.space_key FROM `{$this->prefix}feature_flags` f LEFT JOIN `{$this->prefix}spaces` s ON s.id=f.space_id ORDER BY f.flag_key,f.scope_type,f.space_id")->fetchAll();return $rows?:[];
    }
}
