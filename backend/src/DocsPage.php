<?php
declare(strict_types=1);
namespace Banklord;

/**
 * Public guides and changelog on the website. The Markdown files come from the
 * repository's docs/ folder; the release build ships them as src/doc_<name>.md,
 * so the website always shows exactly the state of the release (= git).
 * Small Markdown subset, everything escaped, no HTML passes through.
 */
final class DocsPage
{
    public const PAGES=['README','mitglieder','bankverwalter','faq','neuigkeiten','installation','selbst-hosten','sicherheit','entwicklung'];
    /** Website root for picture sizes (set before rendering; null = no sizes). */
    public static ?string $root=null;
    private const REPO='https://github.com/lastrevolution0410/banklord/blob/main/';

    /** Resolves "mitglieder", "mitglieder.en" or "" (overview); null = unknown page. */
    public static function resolve(string $name, string $acceptLanguage=''): ?array
    {
        if ($name==='') $name='README';
        if (!preg_match('/^([A-Za-z-]{1,30})(\.en)?$/D',$name,$m) || !in_array($m[1],self::PAGES,true)) return null;
        $en=isset($m[2]) && $m[2]==='.en';
        // Bare name: pick the language from the browser (German unless English is preferred).
        if (!isset($m[2]) && self::prefersEnglish($acceptLanguage)) $en=true;
        return ['base'=>$m[1],'en'=>$en,'file'=>$m[1].($en?'.en':'')];
    }

    public static function prefersEnglish(string $header): bool
    {
        foreach (explode(',',strtolower($header)) as $part) {
            $lang=trim(explode(';',$part)[0]);
            if (str_starts_with($lang,'de')) return false;
            if (str_starts_with($lang,'en')) return true;
        }
        return false;
    }

    /** Picture for a guide: [content type, bytes] or null. Only plain names, only webp/png. */
    public static function image(string $root, string $name): ?array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{1,60}\.(webp|png)$/D',$name,$m)) return null;
        foreach ([$root.'/src/docimg_'.$name, dirname($root).'/docs/assets/'.$name] as $p) {
            if (is_file($p)) { $b=file_get_contents($p); return is_string($b)?['image/'.$m[1],$b]:null; }
        }
        return null;
    }

    public static function source(string $root, string $file): ?string
    {
        foreach ([$root.'/src/doc_'.$file.'.md', dirname($root).'/docs/'.$file.'.md'] as $p) {
            if (is_file($p)) { $s=file_get_contents($p); return is_string($s)?$s:null; }
        }
        return null;
    }

    public static function render(string $md, array $page, string $version): string
    {
        $body=self::markdown($md);
        $title='Banklord';
        if (preg_match('/^#\s+(.+)$/m',$md,$t)) $title=trim($t[1]).' – Banklord';
        $en=$page['en'];$other=$page['base'].($en?'':'.en');
        $nav=$en
            ? [['README.en','Overview'],['mitglieder.en','Members'],['bankverwalter.en','Bank managers'],['faq.en','FAQ'],['neuigkeiten.en','What\'s new']]
            : [['README','Übersicht'],['mitglieder','Mitglieder'],['bankverwalter','Bankverwalter'],['faq','Fragen'],['neuigkeiten','Neuigkeiten']];
        $links='';
        foreach ($nav as [$f,$label]) $links.='<a href="'.self::e($f).'"'.($f===$page['file']?' aria-current="page"':'').'>'.self::e($label).'</a>';
        return '<!doctype html><html lang="'.($en?'en':'de').'"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<meta name="referrer" content="no-referrer"><title>'.self::e($title).'</title><link rel="stylesheet" href="../../../app.css"></head>'
            .'<body class="doc-page"><header class="doc-head"><a class="doc-back" href="../../../">← '.($en?'Back to the website':'Zur Website').'</a>'
            .'<nav class="doc-nav">'.$links.'</nav><a class="doc-lang" href="'.self::e($other).'" hreflang="'.($en?'de':'en').'">'.($en?'Deutsch':'English').'</a></header>'
            .'<main class="doc">'.$body.'</main><footer class="doc-foot">Banklord '.self::e($version).' · '
            .'<a href="'.self::REPO.'docs/'.self::e($page['file']).'.md" rel="noopener" target="_blank">GitHub</a></footer></body></html>';
    }

    public static function markdown(string $md): string
    {
        $lines=preg_split('/\r\n|\n|\r/',$md);$out='';$i=0;$n=count($lines);
        while ($i<$n) {
            $l=$lines[$i];
            if (trim($l)==='') {$i++;continue;}
            if (preg_match('/^```/',$l)) {
                $code=[];$i++;
                while ($i<$n && !preg_match('/^```/',$lines[$i])) $code[]=$lines[$i++];
                $i++;$out.='<pre><code>'.self::e(implode("\n",$code)).'</code></pre>';continue;
            }
            if (preg_match('/^(#{1,6})\s+(.+?)\s*#*$/',$l,$m)) {
                $h=strlen($m[1]);$out.='<h'.$h.' id="'.self::e(self::slug($m[2])).'">'.self::inline($m[2]).'</h'.$h.'>';$i++;continue;
            }
            if (preg_match('/^(-{3,}|\*{3,})\s*$/',$l)) {$out.='<hr>';$i++;continue;}
            if (str_starts_with($l,'>')) {
                $q=[];while ($i<$n && str_starts_with($lines[$i],'>')) $q[]=preg_replace('/^>\s?/','',$lines[$i++]);
                $out.='<blockquote>'.self::markdown(implode("\n",$q)).'</blockquote>';continue;
            }
            if (str_starts_with(ltrim($l),'|') && $i+1<$n && preg_match('/^\s*\|?\s*:?-{3,}/',$lines[$i+1])) {
                $head=self::cells($l);$i+=2;$rows='';
                while ($i<$n && str_starts_with(ltrim($lines[$i]),'|')) {
                    $rows.='<tr>'.implode('',array_map(fn($c)=>'<td>'.self::inline($c).'</td>',self::cells($lines[$i++]))).'</tr>';
                }
                $out.='<div class="doc-table"><table><thead><tr>'.implode('',array_map(fn($c)=>'<th>'.self::inline($c).'</th>',$head)).'</tr></thead><tbody>'.$rows.'</tbody></table></div>';continue;
            }
            if (preg_match('/^\s*([-*]|\d+\.)\s+/',$l,$m)) {
                $ordered=ctype_digit($m[1][0]);$items=[];
                while ($i<$n && trim($lines[$i])!=='') {
                    if (preg_match('/^\s*(?:[-*]|\d+\.)\s+(.*)$/',$lines[$i],$it)) $items[]=$it[1];
                    elseif ($items) $items[count($items)-1].=' '.trim($lines[$i]);
                    else break;
                    $i++;
                }
                $tag=$ordered?'ol':'ul';
                $out.='<'.$tag.'>'.implode('',array_map(fn($x)=>'<li>'.self::inline($x).'</li>',$items)).'</'.$tag.'>';continue;
            }
            $p=[];
            while ($i<$n && trim($lines[$i])!=='' && !preg_match('/^(#{1,6}\s|```|>|\s*\||\s*(?:[-*]|\d+\.)\s+|-{3,}\s*$)/',$lines[$i])) $p[]=trim($lines[$i++]);
            if (!$p) {$p[]=trim($lines[$i++]);}
            $out.='<p>'.self::inline(implode(' ',$p)).'</p>';
        }
        return $out;
    }

    private static function cells(string $row): array
    {
        $row=trim($row);$row=preg_replace('/^\||\|$/','',$row);
        // Split on pipes that are not inside `code`.
        $cells=[];$cur='';$code=false;
        foreach (preg_split('//u',$row,-1,PREG_SPLIT_NO_EMPTY) as $ch) {
            if ($ch==='`') $code=!$code;
            if ($ch==='|' && !$code) {$cells[]=trim($cur);$cur='';continue;}
            $cur.=$ch;
        }
        $cells[]=trim($cur);return $cells;
    }

    /** Inline Markdown on escaped text: code, links, bold, italic, autolinks. */
    public static function inline(string $s): string
    {
        $codes=[];
        $s=preg_replace_callback('/`([^`]+)`/',function($m) use (&$codes){$codes[]='<code>'.self::e($m[1]).'</code>';return "\x01".(count($codes)-1)."\x02";},$s);
        $links=[];
        // Pictures from docs/assets (shipped with the release).
        $s=preg_replace_callback('/!\[([^\]]*)\]\(assets\/([A-Za-z0-9_-]{1,60}\.(?:webp|png))\)/',function($m) use (&$links){
            // Fixed size so jumping to a section does not shift while pictures load.
            $size='';
            $img=self::$root!==null?self::image(self::$root,$m[2]):null;
            if ($img && ($d=@getimagesizefromstring($img[1])) && $d[0]>0) $size=' width="'.(int)$d[0].'" height="'.(int)$d[1].'"';
            $links[]='<img src="img/'.self::e($m[2]).'" alt="'.self::e($m[1]).'"'.$size.' loading="lazy">';return "\x03".(count($links)-1)."\x04";
        },$s);
        $s=preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/',function($m) use (&$links){
            $href=self::href($m[2]);$text=self::e($m[1]);
            $links[]=$href===null?$text:'<a href="'.self::e($href).'"'.(preg_match('~^https?://~',$href)?' rel="noopener" target="_blank"':'').'>'.$text.'</a>';
            return "\x03".(count($links)-1)."\x04";
        },$s);
        $s=preg_replace_callback('/<(https?:\/\/[^>\s]+)>/',function($m) use (&$links){$links[]='<a href="'.self::e($m[1]).'" rel="noopener" target="_blank">'.self::e($m[1]).'</a>';return "\x03".(count($links)-1)."\x04";},$s);
        $s=self::e($s);
        $s=preg_replace('/\*\*(.+?)\*\*/','<strong>$1</strong>',$s);
        $s=preg_replace('/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/','<em>$1</em>',$s);
        $s=preg_replace_callback('/\x03(\d+)\x04/',fn($m)=>$links[(int)$m[1]],$s);
        // Link texts may contain code spans.
        return preg_replace_callback('/\x01(\d+)\x02/',fn($m)=>$codes[(int)$m[1]],$s);
    }

    /** Maps Markdown link targets: other docs stay on the website, repo files go to GitHub. */
    public static function href(string $h): ?string
    {
        if (preg_match('~^https?://~i',$h)) return $h;
        if (str_starts_with($h,'#')) return '#'.self::slug(substr($h,1));
        if (preg_match('~^([A-Za-z-]+(?:\.en)?)\.md(#.*)?$~D',$h,$m)) return $m[1].(isset($m[2])?'#'.self::slug(substr($m[2],1)):'');
        if (preg_match('~^\.\./([A-Za-z0-9_./-]+)$~D',$h,$m) && !str_contains($m[1],'..')) return self::REPO.$m[1];
        return null;
    }

    /** GitHub-style heading anchor. */
    public static function slug(string $s): string
    {
        $s=mb_strtolower(trim(strip_tags($s)));
        $s=preg_replace('/[^\p{L}\p{N} _-]/u','',$s);
        return str_replace(' ','-',$s);
    }

    private static function e(string $s): string { return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
}
