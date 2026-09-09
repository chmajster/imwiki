<?php
declare(strict_types=1);

namespace ImWiki\Services;

use ImWiki\Security\Html;

final class MarkdownService
{
    public function toHtml(string $markdown):string
    {
        $markdown=str_replace(["\r\n","\r"],"\n",$markdown);
        $lines=explode("\n",$markdown);$out=[];$inCode=false;$code=[];$listType=null;$lang='';
        $closeList=static function()use(&$out,&$listType):void{if($listType!==null){$out[]='</'.$listType.'>';$listType=null;}};
        foreach($lines as $line){
            if(preg_match('/^```([a-zA-Z0-9_+.-]*)\s*$/',$line,$m)){
                if(!$inCode){$closeList();$inCode=true;$code=[];$lang=$m[1]??'';}
                else{$class=$lang!==''?' class="language-'.htmlspecialchars($lang,ENT_QUOTES,'UTF-8').'"':'';$out[]='<pre><code'.$class.'>'.htmlspecialchars(implode("\n",$code),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</code></pre>';$inCode=false;$lang='';}
                continue;
            }
            if($inCode){$code[]=$line;continue;}
            if(trim($line)===''){$closeList();continue;}
            if(preg_match('/^(#{1,6})\s+(.+)$/',$line,$m)){$closeList();$n=strlen($m[1]);$out[]='<h'.$n.'>'.$this->inline($m[2]).'</h'.$n.'>';continue;}
            if(preg_match('/^[-*_]{3,}\s*$/',$line)){$closeList();$out[]='<hr>';continue;}
            if(preg_match('/^>\s?(.*)$/',$line,$m)){$closeList();$out[]='<blockquote><p>'.$this->inline($m[1]).'</p></blockquote>';continue;}
            if(preg_match('/^[-*+]\s+(.+)$/',$line,$m)){$this->ensureList('ul',$out,$listType);$out[]='<li>'.$this->inline($m[1]).'</li>';continue;}
            if(preg_match('/^\d+[.)]\s+(.+)$/',$line,$m)){$this->ensureList('ol',$out,$listType);$out[]='<li>'.$this->inline($m[1]).'</li>';continue;}
            $closeList();
            if(str_starts_with(ltrim($line),'<'))$out[]=$line;else$out[]='<p>'.$this->inline($line).'</p>';
        }
        if($inCode)$out[]='<pre><code>'.htmlspecialchars(implode("\n",$code),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</code></pre>';
        $closeList();
        return Html::sanitizeRichText(implode("\n",$out));
    }

    public function fromHtml(string $html):string
    {
        $html=Html::sanitizeRichText($html);
        $blocks=[];
        $html=preg_replace_callback('#<pre(?:\s+class="[^"]*")?>\s*<code(?:\s+class="language-([a-zA-Z0-9_+.-]+)")?>(.*?)</code>\s*</pre>#is',static function(array $m)use(&$blocks):string{
            $lang=$m[1]??'';$code=html_entity_decode(strip_tags($m[2]),ENT_QUOTES|ENT_HTML5,'UTF-8');$code=rtrim(str_replace(["\r\n","\r"],"\n",$code),"\n");$key='@@IMWIKI_BLOCK_'.count($blocks).'@@';$blocks[$key]="```{$lang}\n{$code}\n```";return "\n{$key}\n";
        },$html)??$html;
        $html=preg_replace_callback('#<(table|details)\b[^>]*>.*?</\1>#is',static function(array $m)use(&$blocks):string{$key='@@IMWIKI_BLOCK_'.count($blocks).'@@';$blocks[$key]=trim($m[0]);return "\n{$key}\n";},$html)??$html;

        $html=preg_replace_callback('#<img\b[^>]*src="([^"]+)"[^>]*>#i',static function(array $m):string{
            $tag=$m[0];$src=html_entity_decode($m[1],ENT_QUOTES|ENT_HTML5,'UTF-8');$alt='';if(preg_match('/\balt="([^"]*)"/i',$tag,$a))$alt=html_entity_decode($a[1],ENT_QUOTES|ENT_HTML5,'UTF-8');return '!['.$alt.']('.$src.')';
        },$html)??$html;
        $html=preg_replace_callback('#<a\b[^>]*href="([^"]+)"[^>]*>(.*?)</a>#is',static fn(array $m):string=>'['.trim(strip_tags($m[2])).']('.html_entity_decode($m[1],ENT_QUOTES|ENT_HTML5,'UTF-8').')',$html)??$html;
        $html=preg_replace('#<(strong|b)>(.*?)</\1>#is','**$2**',$html)??$html;
        $html=preg_replace('#<(em|i)>(.*?)</\1>#is','*$2*',$html)??$html;
        $html=preg_replace('#<(s)>(.*?)</\1>#is','~~$2~~',$html)??$html;
        $html=preg_replace('#<code>(.*?)</code>#is','`$1`',$html)??$html;
        for($n=1;$n<=6;$n++)$html=preg_replace('#<h'.$n.'\b[^>]*>(.*?)</h'.$n.'>#is,"\n".str_repeat('#',$n).' $1'."\n",$html)??$html;
        $html=preg_replace_callback('#<blockquote\b[^>]*>(.*?)</blockquote>#is',static function(array $m):string{$text=trim(preg_replace('#</?p\b[^>]*>#i','',$m[1])??$m[1]);$text=trim(strip_tags($text));return "\n> ".str_replace("\n","\n> ",$text)."\n";},$html)??$html;
        $html=preg_replace_callback('#<ul\b[^>]*>(.*?)</ul>#is',static fn(array $m):string=>"\n".preg_replace_callback('#<li\b[^>]*>(.*?)</li>#is',static fn(array $li):string=>'- '.trim(strip_tags($li[1]))."\n",$m[1])."\n",$html)??$html;
        $html=preg_replace_callback('#<ol\b[^>]*>(.*?)</ol>#is',static function(array $m):string{$i=0;return "\n".(preg_replace_callback('#<li\b[^>]*>(.*?)</li>#is',static function(array $li)use(&$i):string{$i++;return $i.'. '.trim(strip_tags($li[1]))."\n";},$m[1])??'')."\n";},$html)??$html;
        $html=preg_replace('#<hr\s*/?>#i',"\n---\n",$html)??$html;
        $html=preg_replace('#<br\s*/?>#i',"  \n",$html)??$html;
        $html=preg_replace('#</p\s*>#i',"\n\n",$html)??$html;
        $html=preg_replace('#<p\b[^>]*>#i','',$html)??$html;
        $html=preg_replace('#</div\s*>#i',"\n\n",$html)??$html;
        $html=preg_replace('#<div\b[^>]*>#i','',$html)??$html;
        $html=strip_tags($html);
        $html=html_entity_decode($html,ENT_QUOTES|ENT_HTML5,'UTF-8');
        foreach($blocks as $key=>$value)$html=str_replace($key,$value,$html);
        $html=preg_replace("/[ \t]+\n/u","\n",$html)??$html;
        $html=preg_replace("/\n{3,}/u","\n\n",$html)??$html;
        return trim($html)."\n";
    }

    public function exportDocument(array $page,array $labels=[]):string
    {
        $front=['title'=>$page['title'],'id'=>(int)$page['id'],'slug'=>$page['slug'],'space'=>$page['space_key']??null,'owner'=>$page['owner_username']??null,'status'=>$page['status']??'published','review_date'=>$page['review_date']??null,'labels'=>$labels,'updated_at'=>$page['updated_at']??null];
        $yaml="---\n";foreach($front as $key=>$value){if($value===null||$value==='')continue;if(is_array($value))$yaml.=$key.': ['.implode(', ',array_map(fn($v)=>$this->yamlScalar((string)$v),$value))."]\n";else$yaml.=$key.': '.$this->yamlScalar((string)$value)."\n";}
        return $yaml."---\n\n".$this->fromHtml((string)$page['content']);
    }

    public function parseDocument(string $raw):array
    {
        $raw=str_replace(["\r\n","\r"],"\n",$raw);$meta=[];$body=$raw;
        if(str_starts_with($raw,"---\n")&&preg_match('/^---\n(.*?)\n---\n(.*)$/s',$raw,$m)){
            foreach(explode("\n",$m[1]) as $line){if(!str_contains($line,':'))continue;[$k,$v]=array_map('trim',explode(':',$line,2));if(!preg_match('/^[a-zA-Z0-9_-]+$/',$k))continue;$meta[$k]=$this->parseScalar($v);}$body=$m[2];
        }
        return ['meta'=>$meta,'html'=>$this->toHtml($body),'markdown'=>$body];
    }

    private function ensureList(string $type,array &$out,?string &$listType):void
    {
        if($listType===$type)return;if($listType!==null)$out[]='</'.$listType.'>';$out[]='<'.$type.'>';$listType=$type;
    }

    private function inline(string $s):string
    {
        $s=htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $s=preg_replace('/!\[([^\]]*)\]\((https?:\/\/[^\s)]+|\/[^\s)]*|\.\.?\/[^\s)]*)\)/','<img src="$2" alt="$1">',$s)??$s;
        $s=preg_replace('/`([^`]+)`/','<code>$1</code>',$s)??$s;
        $s=preg_replace('/\*\*([^*]+)\*\*/','<strong>$1</strong>',$s)??$s;
        $s=preg_replace('/~~([^~]+)~~/','<s>$1</s>',$s)??$s;
        $s=preg_replace('/\*([^*]+)\*/','<em>$1</em>',$s)??$s;
        $s=preg_replace('/\[([^\]]+)\]\((https?:\/\/[^\s)]+|mailto:[^\s)]+|\/[^\s)]*|\.\.?\/[^\s)]*)\)/','<a href="$2">$1</a>',$s)??$s;
        return $s;
    }

    private function yamlScalar(string $v):string{return '"'.str_replace(['\\','"'],['\\\\','\\"'],$v).'"';}

    private function parseScalar(string $value):mixed
    {
        $value=trim($value);
        if($value==='null'||$value==='~')return null;
        if($value==='true')return true;if($value==='false')return false;
        if(str_starts_with($value,'[')&&str_ends_with($value,']')){
            $inside=trim(substr($value,1,-1));if($inside==='')return[];
            $items=str_getcsv($inside,',','"','\\');return array_values(array_map(static fn(string $v):string=>trim($v),$items));
        }
        if(strlen($value)>=2&&$value[0]==='"'&&$value[-1]==='"')return stripcslashes(substr($value,1,-1));
        return $value;
    }
}
