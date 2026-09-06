<?php
declare(strict_types=1);

namespace ImWiki\Auth;

use PDO;
use RuntimeException;

final class ExternalIdentityService
{
    public function __construct(private readonly PDO $pdo,private readonly string $prefix=''){}

    public function resolveOrProvision(string $providerKey,array $claims):array
    {
        $externalId=trim((string)($claims['sub']??$claims['external_id']??''));if($externalId==='')throw new RuntimeException('External identity has no stable subject.');$stmt=$this->pdo->prepare("SELECT u.* FROM `{$this->prefix}external_identities` e JOIN `{$this->prefix}users` u ON u.id=e.user_id WHERE e.provider_key=? AND e.external_id=? AND u.deleted_at IS NULL LIMIT 1");$stmt->execute([$providerKey,$externalId]);$user=$stmt->fetch();if($user){if($user['status']!=='active')throw new RuntimeException('Account is not active.');$this->pdo->prepare("UPDATE `{$this->prefix}external_identities` SET last_login_at=UTC_TIMESTAMP() WHERE provider_key=? AND external_id=?")->execute([$providerKey,$externalId]);$this->syncGroups((int)$user['id'],$providerKey,$claims);return$user;}
        $p=$this->pdo->prepare("SELECT * FROM `{$this->prefix}auth_providers` WHERE provider_key=? AND enabled=1 LIMIT 1");$p->execute([$providerKey]);$provider=$p->fetch();if(!$provider||!(bool)$provider['auto_provision'])throw new RuntimeException('External account is not linked.');$cfg=json_decode((string)$provider['config_json'],true)?:[];
        $username=$this->claim($claims,(string)($cfg['claim_username']??'preferred_username'));$email=mb_strtolower($this->claim($claims,(string)($cfg['claim_email']??'email')));if($username===''||$email===''||!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Provider did not return required identity claims.');$username=$this->uniqueUsername($username);$existingEmail=$this->pdo->prepare("SELECT id FROM `{$this->prefix}users` WHERE email=? AND deleted_at IS NULL LIMIT 1");$existingEmail->execute([$email]);if($existingEmail->fetchColumn())throw new RuntimeException('An account with this e-mail already exists; administrator must link the external identity explicitly.');
        $first=$this->claim($claims,(string)($cfg['claim_first_name']??'given_name'));$last=$this->claim($claims,(string)($cfg['claim_last_name']??'family_name'));$this->pdo->beginTransaction();try{$insert=$this->pdo->prepare("INSERT INTO `{$this->prefix}users` (username,first_name,last_name,email,password_hash,status,force_password_change,language,timezone,theme,created_at,updated_at) VALUES (?,?,?,?,?,'active',0,'pl','Europe/Warsaw','system',UTC_TIMESTAMP(),UTC_TIMESTAMP())");$insert->execute([$username,$first,$last,$email,password_hash(bin2hex(random_bytes(48)),PASSWORD_DEFAULT)]);$userId=(int)$this->pdo->lastInsertId();$this->pdo->prepare("INSERT INTO `{$this->prefix}external_identities` (user_id,provider_key,external_id,last_login_at,created_at) VALUES (?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute([$userId,$providerKey,$externalId]);$role=(string)($provider['default_role']??'');if($role!==''&&!in_array($role,['administrator','super_administrator'],true)){$r=$this->pdo->prepare("SELECT id FROM `{$this->prefix}roles` WHERE name=? LIMIT 1");$r->execute([$role]);$rid=(int)($r->fetchColumn()?:0);if($rid>0)$this->pdo->prepare("INSERT IGNORE INTO `{$this->prefix}user_roles` (user_id,role_id) VALUES (?,?)")->execute([$userId,$rid]);}$this->pdo->commit();$this->syncGroups($userId,$providerKey,$claims);$q=$this->pdo->prepare("SELECT * FROM `{$this->prefix}users` WHERE id=?");$q->execute([$userId]);return$q->fetch()?:throw new RuntimeException('Provisioned account could not be loaded.');}catch(\Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw$e;}
    }

    public function link(int $userId,string $providerKey,string $externalId):void
    {
        if($userId<=0||trim($externalId)==='')throw new RuntimeException('Invalid identity link.');$this->pdo->prepare("INSERT INTO `{$this->prefix}external_identities` (user_id,provider_key,external_id,created_at) VALUES (?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id)")->execute([$userId,$providerKey,trim($externalId)]);
    }

    private function syncGroups(int $userId,string $providerKey,array $claims):void
    {
        $p=$this->pdo->prepare("SELECT config_json FROM `{$this->prefix}auth_providers` WHERE provider_key=? LIMIT 1");$p->execute([$providerKey]);$cfg=json_decode((string)($p->fetchColumn()?:'{}'),true)?:[];$mode=(string)($cfg['group_sync_mode']??'off');if(!in_array($mode,['create_missing','map_existing','sync'],true))return;$claim=(string)($cfg['claim_groups']??'groups');$raw=$claims[$claim]??[];$groups=is_array($raw)?$raw:($raw!==''?[$raw]:[]);$wanted=[];
        foreach($groups as $group){$label=trim((string)$group);if($label===''||mb_strlen($label)>150)continue;$name='ext-'.$providerKey.'-'.substr(hash('sha256',$label),0,16);$g=$this->pdo->prepare("SELECT id,system FROM `{$this->prefix}groups` WHERE name=? OR (label=? AND external_source=?) LIMIT 1");$g->execute([$name,$label,$providerKey]);$row=$g->fetch();if(!$row&&$mode!=='map_existing'){$i=$this->pdo->prepare("INSERT INTO `{$this->prefix}groups` (name,label,system,external_source,created_at,updated_at) VALUES (?,?,0,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())");$i->execute([$name,$label,$providerKey]);$gid=(int)$this->pdo->lastInsertId();}elseif($row){$gid=(int)$row['id'];}else continue;$wanted[]=$gid;$this->pdo->prepare("INSERT IGNORE INTO `{$this->prefix}group_users` (group_id,user_id) VALUES (?,?)")->execute([$gid,$userId]);}
        if($mode==='sync'){$q=$this->pdo->prepare("SELECT g.id FROM `{$this->prefix}groups` g JOIN `{$this->prefix}group_users` gu ON gu.group_id=g.id WHERE gu.user_id=? AND g.external_source=? AND g.system=0");$q->execute([$userId,$providerKey]);foreach($q->fetchAll(PDO::FETCH_COLUMN) as $gid)if(!in_array((int)$gid,$wanted,true))$this->pdo->prepare("DELETE FROM `{$this->prefix}group_users` WHERE group_id=? AND user_id=?")->execute([(int)$gid,$userId]);}
    }

    private function claim(array $claims,string $name):string{$v=$claims[$name]??'';return is_scalar($v)?mb_substr(trim((string)$v),0,190):'';}
    private function uniqueUsername(string $value):string{$base=preg_replace('/[^A-Za-z0-9._-]/','-',trim($value))??'';$base=trim($base,'-.');if(strlen($base)<3)$base='user-'.substr(hash('sha256',$value),0,10);$base=substr($base,0,80);$candidate=$base;for($i=0;$i<100;$i++){$q=$this->pdo->prepare("SELECT 1 FROM `{$this->prefix}users` WHERE username=? LIMIT 1");$q->execute([$candidate]);if(!$q->fetchColumn())return$candidate;$candidate=substr($base,0,70).'-'.($i+1);}throw new RuntimeException('Could not allocate username.');}
}
