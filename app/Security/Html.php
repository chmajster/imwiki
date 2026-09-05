<?php
declare(strict_types=1);

namespace ImWiki\Security;

final class Html
{
    private const ALLOWED_TAGS = [
        'p','br','div','span','h1','h2','h3','h4','h5','h6','strong','b','em','i','u','s',
        'ul','ol','li','blockquote','pre','code','a','table','thead','tbody','tr','th','td','hr','img','input','details','summary'
    ];

    public static function e(mixed $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function sanitizeRichText(string $html): string
    {
        // Remove active/content-bearing tags together with their body before the generic tag whitelist.
        $html = preg_replace('#<\s*(script|iframe|object|embed|style|link|meta|svg|math)[^>]*>.*?<\s*/\s*\1\s*>#is', '', $html) ?? '';
        $html = preg_replace('#<\s*(script|iframe|object|embed|style|link|meta|svg|math)\b[^>]*/?\s*>#is', '', $html) ?? '';
        $allowed = '<'.implode('><',self::ALLOWED_TAGS).'>';
        $html = strip_tags($html,$allowed);

        $html = preg_replace_callback('/<([a-zA-Z0-9]+)(\s[^>]*)?>/u', static function(array $m): string {
            $tag = strtolower($m[1]);
            if (!in_array($tag,self::ALLOWED_TAGS,true)) return '';
            $raw = $m[2] ?? '';
            $attrs = self::sanitizeAttributes($tag,$raw);
            $void = in_array($tag,['br','hr','img','input'],true);
            return '<'.$tag.$attrs.($void?'':'').'>';
        }, $html) ?? '';

        // Drop closing tags that are no longer on the whitelist.
        $html = preg_replace_callback('/<\/\s*([a-zA-Z0-9]+)\s*>/u', static function(array $m): string {
            $tag=strtolower($m[1]);
            return in_array($tag,self::ALLOWED_TAGS,true) && !in_array($tag,['br','hr','img','input'],true) ? '</'.$tag.'>' : '';
        }, $html) ?? '';

        return $html;
    }

    private static function sanitizeAttributes(string $tag,string $raw): string
    {
        $allowed = match($tag) {
            'a' => ['href','title'],
            'img' => ['src','alt','title','width','height'],
            'input' => ['type','checked','disabled'],
            'code','pre','div','span','details','summary' => ['class'],
            'th','td' => ['colspan','rowspan'],
            default => [],
        };
        if (!$allowed || trim($raw)==='') return '';

        preg_match_all('/([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*(?:=\s*("[^"]*"|\'[^\']*\'|[^\s"\'=<>`]+))?/u',$raw,$matches,PREG_SET_ORDER);
        $out=[];
        foreach($matches as $attr){
            $name=strtolower($attr[1]);
            if(!in_array($name,$allowed,true) || str_starts_with($name,'on')) continue;
            $value=$attr[2]??'';
            if($value!==''){
                $first=$value[0]??'';
                if(($first==='"'||$first==="'") && str_ends_with($value,$first)) $value=substr($value,1,-1);
            }
            $value=html_entity_decode($value,ENT_QUOTES|ENT_HTML5,'UTF-8');

            if($name==='href' && !self::safeLink($value)) continue;
            if($name==='src' && !self::safeImageSource($value)) continue;
            if($name==='type'){
                if($tag!=='input'||strtolower($value)!=='checkbox') continue;
                $value='checkbox';
            }
            if(in_array($name,['checked','disabled'],true)){
                if($tag!=='input') continue;
                $out[]=$name;
                continue;
            }
            if(in_array($name,['width','height','colspan','rowspan'],true)){
                if(!preg_match('/^\d{1,4}$/',$value)) continue;
            }
            if($name==='class'){
                $ok=$tag==='code'?preg_match('/^language-[a-z0-9_+.-]{1,40}$/i',$value):preg_match('/^(?:macro(?:\s+macro-(?:info|warning|success|error|expand))?|mermaid)$/',$value);
                if(!$ok) continue;
            }
            $out[]=$name.'="'.self::e($value).'"';
        }
        return $out?' '.implode(' ',$out):'';
    }

    private static function safeLink(string $url): bool
    {
        $url=trim($url);
        if($url===''||str_starts_with($url,'#')||str_starts_with($url,'/')||str_starts_with($url,'./')||str_starts_with($url,'../')) return true;
        $scheme=strtolower((string)parse_url($url,PHP_URL_SCHEME));
        return in_array($scheme,['http','https','mailto'],true);
    }

    private static function safeImageSource(string $url): bool
    {
        $url=trim($url);
        if($url==='') return false;
        // Images in stored wiki content must be served through the application or a relative URL.
        return str_starts_with($url,'/')||str_starts_with($url,'./')||str_starts_with($url,'../');
    }
}
