<?php
declare(strict_types=1);

namespace ImWiki\Macros;

use ImWiki\Security\Html;

final class MacroRegistry
{
    /** @var array<string,array{name:string,params:array,permission:?string,cacheable:bool,renderer:callable}> */
    private array $macros=[];

    public function register(string $identifier,string $name,array $allowedParameters,?string $permission,bool $cacheable,callable $renderer):void
    {
        if(!preg_match('/^[a-z][a-z0-9-]{1,79}$/',$identifier))throw new \InvalidArgumentException('Invalid macro identifier.');if(isset($this->macros[$identifier]))throw new \LogicException('Macro already registered: '.$identifier);$params=[];foreach($allowedParameters as $p){$p=(string)$p;if(!preg_match('/^[a-z_][a-z0-9_]{0,49}$/',$p))throw new \InvalidArgumentException('Invalid macro parameter.');$params[]=$p;}$this->macros[$identifier]=['name'=>$name,'params'=>$params,'permission'=>$permission,'cacheable'=>$cacheable,'renderer'=>$renderer];
    }

    public function definitions():array
    {
        $out=[];foreach($this->macros as $id=>$m)$out[]=['identifier'=>$id,'name'=>$m['name'],'allowed_parameters'=>$m['params'],'permission'=>$m['permission'],'cacheable'=>$m['cacheable']];return$out;
    }

    public function render(string $html,array $context):string
    {
        return preg_replace_callback('/(?:<p>\s*)?\{\{([a-z][a-z0-9-]{1,79})(?::([^{}]{0,500}))?\}\}(?:\s*<\/p>)?/i',function(array $match)use($context):string{$id=mb_strtolower($match[1]);$macro=$this->macros[$id]??null;if(!$macro)return'<span class="macro macro-warning">'.Html::e('Unknown macro: '.$id).'</span>';$params=$this->parseParams((string)($match[2]??''),$macro['params']);$can=$context['can']??null;if($macro['permission']!==null&&(!is_callable($can)||!$can($macro['permission'])))return'<span class="macro macro-warning">Macro unavailable</span>';try{$result=($macro['renderer'])($params,$context);return is_string($result)?$result:'<span class="macro macro-warning">Invalid macro result</span>';}catch(\Throwable){return'<span class="macro macro-warning">Macro rendering failed</span>'; }},$html)??$html;
    }

    private function parseParams(string $raw,array $allowed):array
    {
        $raw=trim($raw);if($raw==='')return[];$out=[];$positionals=[];foreach(preg_split('/\s*;\s*/',$raw)?:[] as $part){if($part==='')continue;if(str_contains($part,'=')){[$k,$v]=array_map('trim',explode('=',$part,2));$k=mb_strtolower($k);if(in_array($k,$allowed,true))$out[$k]=mb_substr($v,0,300);}else$positionals[]=mb_substr(trim($part),0,300);}$i=0;foreach($allowed as $name)if(!array_key_exists($name,$out)&&isset($positionals[$i]))$out[$name]=$positionals[$i++];return$out;
    }
}
