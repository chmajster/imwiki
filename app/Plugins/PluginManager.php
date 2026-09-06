<?php
declare(strict_types=1);

namespace ImWiki\Plugins;

use ImWiki\Exceptions\ConflictException;
use ImWiki\Exceptions\ValidationException;
use ImWiki\Macros\MacroRegistry;
use ImWiki\Support\EventDispatcher;
use ImWiki\Support\FeatureFlags;
use PDO;
use RuntimeException;
use ZipArchive;

final class PluginManager
{
    private const MAX_ZIP_BYTES=20_971_520;
    private const MAX_FILES=1000;
    private const MAX_UNPACKED_BYTES=52_428_800;
    public function __construct(private readonly PDO $pdo,private readonly string $prefix,private readonly string $root,private readonly FeatureFlags $flags){}

    public function safeMode():bool
    {
        if(is_file($this->root.'/storage/safe-mode.flag'))return true;$s=$this->pdo->prepare("SELECT setting_value FROM `{$this->prefix}settings` WHERE setting_key='plugins.safe_mode' LIMIT 1");$s->execute();return(string)($s->fetchColumn()?:'0')==='1';
    }

    public function list():array
    {
        $this->syncDiscovered();$rows=$this->pdo->query("SELECT plugin_id,name,version,author,required_imwiki,permissions_json,entrypoint,enabled,compatible,is_core,updated_at FROM `{$this->prefix}plugins` ORDER BY is_core DESC,name")->fetchAll();foreach($rows as &$r)$r['permissions']=json_decode((string)$r['permissions_json'],true)?:[];return$rows?:[];
    }

    public function installZip(string $zipPath):array
    {
        if(!is_file($zipPath)||filesize($zipPath)>self::MAX_ZIP_BYTES)throw new ValidationException('Plugin archive is missing or too large.');if(!class_exists(ZipArchive::class))throw new RuntimeException('ZipArchive is required to install a plugin.');$zip=new ZipArchive();if($zip->open($zipPath)!==true)throw new ValidationException('Invalid plugin ZIP.');try{$manifestEntry=null;$count=$zip->numFiles;if($count<1||$count>self::MAX_FILES)throw new ValidationException('Plugin ZIP contains an invalid number of files.');$total=0;for($i=0;$i<$count;$i++){$stat=$zip->statIndex($i);$name=(string)($stat['name']??'');$size=(int)($stat['size']??0);$total+=$size;if($total>self::MAX_UNPACKED_BYTES)throw new ValidationException('Plugin archive expands beyond the allowed limit.');$this->validateArchivePath($name);if(preg_match('#(^|/)plugin\.json$#',$name)){$depth=substr_count(trim($name,'/'),'/');if($depth===0)$manifestEntry=$name;}}if($manifestEntry===null)throw new ValidationException('plugin.json must exist at the archive root.');$raw=$zip->getFromName($manifestEntry);if($raw===false||strlen($raw)>65536)throw new ValidationException('Invalid plugin manifest.');$manifest=$this->validateManifest(json_decode($raw,true,512,JSON_THROW_ON_ERROR));$target=$this->pluginDir($manifest['id']);if(is_dir($target))throw new ConflictException('Plugin is already installed.');$tmp=$this->root.'/storage/plugins/install-'.bin2hex(random_bytes(8));if(!@mkdir($tmp,0770,true))throw new RuntimeException('Cannot create plugin staging directory.');for($i=0;$i<$count;$i++){$stat=$zip->statIndex($i);$name=(string)$stat['name'];if(str_ends_with($name,'/'))continue;$data=$zip->getFromIndex($i);if($data===false)throw new ValidationException('Cannot read plugin archive member.');$dest=$tmp.'/'.$name;$dir=dirname($dest);if(!is_dir($dir)&&!@mkdir($dir,0770,true))throw new RuntimeException('Cannot create plugin directory.');if(file_put_contents($dest,$data,LOCK_EX)===false)throw new RuntimeException('Cannot extract plugin file.');}if(!@rename($tmp,$target)){ $this->removeTree($tmp);throw new RuntimeException('Cannot activate plugin directory.');}$this->syncOne($manifest);return$manifest;}finally{$zip->close();}
    }

    public function uninstall(string $pluginId):void
    {
        $row=$this->row($pluginId);if(!$row)return;if((bool)$row['is_core'])throw new ConflictException('Core plugin cannot be uninstalled.');$this->pdo->prepare("DELETE FROM `{$this->prefix}plugins` WHERE plugin_id=?")->execute([$pluginId]);$this->removeTree($this->pluginDir($pluginId));
    }

    public function setEnabled(string $pluginId,bool $enabled):void
    {
        $this->syncDiscovered();$row=$this->row($pluginId);if(!$row)throw new ValidationException('Unknown plugin.');if($enabled){if(!$this->flags->enabled('plugins'))throw new ConflictException('Plugins feature flag is disabled.');if($this->safeMode())throw new ConflictException('Safe Mode disables third-party plugins.');if(!(bool)$row['compatible'])throw new ConflictException('Plugin is not compatible with this imWiki version.');}$this->pdo->prepare("UPDATE `{$this->prefix}plugins` SET enabled=?,updated_at=UTC_TIMESTAMP() WHERE plugin_id=?")->execute([$enabled?1:0,$pluginId]);
    }

    public function bootEnabled(MacroRegistry $macros,EventDispatcher $events):array
    {
        if(!$this->flags->enabled('plugins')||$this->safeMode())return[];$this->syncDiscovered();$stmt=$this->pdo->query("SELECT * FROM `{$this->prefix}plugins` WHERE enabled=1 AND compatible=1 ORDER BY is_core DESC,plugin_id");$booted=[];foreach($stmt->fetchAll() as $row){$id=(string)$row['plugin_id'];$manifest=$this->manifest($id);if(!$manifest)continue;$entry=$this->safeEntrypoint($id,(string)$manifest['entrypoint']);$object=require$entry;if(!$object instanceof PluginInterface)throw new RuntimeException('Plugin '.$id.' entrypoint must return PluginInterface.');$permissions=json_decode((string)$row['permissions_json'],true)?:[];$object->boot(new PluginContext($id,$permissions,$macros,$events));$booted[]=$id;}return$booted;
    }

    public function syncDiscovered():void
    {
        $base=$this->root.'/plugins';if(!is_dir($base))return;foreach(glob($base.'/*/plugin.json')?:[] as $file){try{$raw=file_get_contents($file);$manifest=$this->validateManifest(json_decode((string)$raw,true,512,JSON_THROW_ON_ERROR));if(realpath(dirname($file))!==realpath($this->pluginDir($manifest['id'])))continue;$this->syncOne($manifest);}catch(\Throwable){continue;}}
    }

    private function syncOne(array $m):void
    {
        $compatible=$this->compatible((string)$m['required_imwiki']);$sql="INSERT INTO `{$this->prefix}plugins` (plugin_id,name,version,author,required_imwiki,permissions_json,entrypoint,manifest_json,enabled,compatible,is_core,updated_at) VALUES (?,?,?,?,?,?,?,?,0,?,0,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE name=VALUES(name),version=VALUES(version),author=VALUES(author),required_imwiki=VALUES(required_imwiki),permissions_json=VALUES(permissions_json),entrypoint=VALUES(entrypoint),manifest_json=VALUES(manifest_json),compatible=VALUES(compatible),enabled=IF(VALUES(compatible)=0,0,enabled),updated_at=UTC_TIMESTAMP()";$this->pdo->prepare($sql)->execute([$m['id'],$m['name'],$m['version'],$m['author']??null,$m['required_imwiki'],json_encode($m['permissions'],JSON_THROW_ON_ERROR),$m['entrypoint'],json_encode($m,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),$compatible?1:0]);
    }

    private function validateManifest(mixed $data):array
    {
        if(!is_array($data))throw new ValidationException('plugin.json must contain an object.');foreach(['id','name','version','required_imwiki','permissions','entrypoint'] as $field)if(!array_key_exists($field,$data))throw new ValidationException('Plugin manifest is missing '.$field.'.');$id=mb_strtolower(trim((string)$data['id']));if(!preg_match('/^[a-z][a-z0-9_.-]{2,119}$/',$id))throw new ValidationException('Invalid plugin id.');$name=trim((string)$data['name']);$version=trim((string)$data['version']);$required=trim((string)$data['required_imwiki']);$entry=trim((string)$data['entrypoint']);if($name===''||mb_strlen($name)>190||!preg_match('/^\d+\.\d+\.\d+(?:[-+][A-Za-z0-9.-]+)?$/',$version)||$required===''||mb_strlen($required)>100||!preg_match('#^[A-Za-z0-9._/-]+\.php$#',$entry)||str_contains($entry,'..')||str_starts_with($entry,'/'))throw new ValidationException('Invalid plugin manifest values.');$permissions=[];if(!is_array($data['permissions']))throw new ValidationException('Plugin permissions must be an array.');foreach(array_slice($data['permissions'],0,100) as $p){$p=(string)$p;if(!preg_match('/^[a-z][a-z0-9_.-]{1,99}$/',$p))throw new ValidationException('Invalid plugin permission.');$permissions[]=$p;}$author=isset($data['author'])?mb_substr(trim((string)$data['author']),0,190):null;return['id'=>$id,'name'=>$name,'version'=>$version,'author'=>$author,'required_imwiki'=>$required,'permissions'=>array_values(array_unique($permissions)),'entrypoint'=>$entry];
    }

    private function compatible(string $constraint):bool
    {
        $current=defined('IMWIKI_VERSION')?IMWIKI_VERSION:'0.0.0';$constraint=trim($constraint);if(preg_match('/^>=\s*(\d+\.\d+\.\d+)$/',$constraint,$m))return version_compare($current,$m[1],'>=');if(preg_match('/^\^(\d+)\.(\d+)\.(\d+)$/',$constraint,$m))return version_compare($current,$m[1].'.'.$m[2].'.'.$m[3],'>=')&&version_compare($current,((int)$m[1]+1).'.0.0','<');if(preg_match('/^\d+\.\d+\.\d+$/',$constraint))return version_compare($current,$constraint,'=');return false;
    }
    private function manifest(string $id):?array{$file=$this->pluginDir($id).'/plugin.json';if(!is_file($file))return null;try{return$this->validateManifest(json_decode((string)file_get_contents($file),true,512,JSON_THROW_ON_ERROR));}catch(\Throwable){return null;}}
    private function safeEntrypoint(string $id,string $entry):string{$base=realpath($this->pluginDir($id));$path=realpath($this->pluginDir($id).'/'.$entry);if($base===false||$path===false||!str_starts_with($path,$base.DIRECTORY_SEPARATOR)||!is_file($path))throw new RuntimeException('Plugin entrypoint is invalid.');return$path;}
    private function pluginDir(string $id):string{return$this->root.'/plugins/'.$id;}
    private function row(string $id):?array{$s=$this->pdo->prepare("SELECT * FROM `{$this->prefix}plugins` WHERE plugin_id=? LIMIT 1");$s->execute([$id]);return$s->fetch()?:null;}
    private function validateArchivePath(string $name):void{if($name===''||str_contains($name,"\0")||str_starts_with($name,'/')||preg_match('#(^|/)\.\.(/|$)#',$name)||preg_match('#^[A-Za-z]:[\\/]#',$name))throw new ValidationException('Unsafe plugin archive path.');$ext=mb_strtolower(pathinfo($name,PATHINFO_EXTENSION));if($ext!==''&&!in_array($ext,['php','json','css','js','md','txt','svg','png','jpg','jpeg','gif','webp'],true))throw new ValidationException('Unsupported plugin file type: '.$ext);}
    private function removeTree(string $dir):void{if(!is_dir($dir))return;$items=scandir($dir)?:[];foreach($items as $item){if($item==='.'||$item==='..')continue;$path=$dir.'/'.$item;if(is_link($path)||is_file($path))@unlink($path);elseif(is_dir($path))$this->removeTree($path);}@rmdir($dir);}
}
