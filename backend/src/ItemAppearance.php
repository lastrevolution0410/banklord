<?php
declare(strict_types=1);
namespace Banklord;

/**
 * External, public item metadata only. Never accepts a URL from clients and
 * never uses untrusted markup unfiltered. Works without this service: stocks,
 * orders and permissions always remain local/authoritative.
 */
final class ItemAppearance {
    public static function resolve(int $itemId, string $variantKey, string $cacheDir, string $locale = 'deDE', string $flavor = GameFlavor::DEFAULT): array {
        if (!in_array($locale,['deDE','enUS'],true)) throw new \InvalidArgumentException('INVALID_LOCALE');
        if (!GameFlavor::valid($flavor)) throw new \InvalidArgumentException('INVALID_GAME_VERSION');
        // The full tooltip provider only knows Mists of Pandaria Classic; other
        // game versions get the icon from Wowhead's matching database only.
        $mop = $flavor === 'mop_classic';
        // Full itemStrings, not only item IDs, distinguish enchantments, gems and random suffixes.
        $variant = self::variantInfo($itemId, $variantKey);
        if ($itemId < 1 || $itemId > 99999999) throw new \InvalidArgumentException('ITEM_ID_INVALID');
        $path = rtrim($cacheDir, '/').'/'.($mop?'mop':$flavor).'-'.$locale.'-'.$itemId.'-'.hash('sha256',$variant['cache_key']).'.json';
        if (is_file($path)) {
            $data=json_decode((string)file_get_contents($path),true);
            if (is_array($data) && array_key_exists('available',$data)) {
                $cachedIcon=$data['icon']??null;
                $normalizedIcon=self::iconUrl($cachedIcon);
                // 0.7.8 cached invalid numeric-CDN URLs as though valid icons.
                // Such data must not block fresh resolution for seven days.
                $ttl=($normalizedIcon!==null && $normalizedIcon===$cachedIcon)?7*86400:300;
                if ($cachedIcon!==null && $normalizedIcon===null) $ttl=0;
                if ((time()-(int)filemtime($path))<$ttl) return $data;
            }
        }
        $empty=['available'=>false,'icon'=>null,'quality'=>null,'tooltip_html'=>null,'provider'=>'mop.warcraftdb.com',
            'variant_status'=>$variant['status'],'variant_notes'=>$variant['notes'],
            'modifiers_applied'=>$variant['applied'], 'tooltip_locale'=>$locale];
        $result=$empty;
        if (!$mop) {
            // no MoP tooltip provider for this game version
        } elseif (function_exists('curl_init')) {
            // URL is constructed from a bounded INTEGER, never from client URL input.
            $url='https://mop.warcraftdb.com/api/v1/tooltip/item/'.$itemId.$variant['query'];
            $ch=curl_init($url);
            curl_setopt_array($ch,[
                CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>false,
                CURLOPT_CONNECTTIMEOUT=>2, CURLOPT_TIMEOUT=>4,
                CURLOPT_MAXREDIRS=>0, CURLOPT_SSL_VERIFYPEER=>true,
                CURLOPT_SSL_VERIFYHOST=>2, CURLOPT_USERAGENT=>'Banklord/0.7 (guild item metadata)',
                CURLOPT_HTTPHEADER=>['Accept: application/json'],
                CURLOPT_MAXFILESIZE=>131072,
            ]);
            $body=curl_exec($ch);
            $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if (is_string($body) && strlen($body)<=131072 && $status===200) {
                $normalized=self::normalize($body);
                if ($normalized !== null) { if ($locale==='deDE') $normalized['tooltip_html']=null; $result=array_merge($normalized, ['variant_status'=>$variant['status'], 'variant_notes'=>$variant['notes'], 'modifiers_applied'=>$variant['applied'], 'tooltip_locale'=>$locale]); }
            }
        } else if (filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            // Same fixed HTTPS host and bounded numeric item ID. For shared PHP
            // hosting without ext-curl; redirects and TLS downgrades forbidden.
            $url='https://mop.warcraftdb.com/api/v1/tooltip/item/'.$itemId.$variant['query'];
            $context=stream_context_create([
                'http'=>['method'=>'GET','timeout'=>4,'follow_location'=>0,'max_redirects'=>0,
                    'header'=>"Accept: application/json\r\nUser-Agent: Banklord/0.7\r\n"],
                'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true],
            ]);
            $body=@file_get_contents($url,false,$context,0,131073);
            $status=$http_response_header[0]??'';
            if (is_string($body) && strlen($body)<=131072 && preg_match('~^HTTP/\S+ 200(?: |$)~D',$status)) {
                $normalized=self::normalize($body);
                if ($normalized!==null) { if ($locale==='deDE') $normalized['tooltip_html']=null; $result=array_merge($normalized, ['variant_status'=>$variant['status'], 'variant_notes'=>$variant['notes'], 'modifiers_applied'=>$variant['applied'], 'tooltip_locale'=>$locale]); }
            }
        }
        // Public item metadata fallback. Fixed domains, numeric ID only, HTTPS, no redirect,
        // no user credentials, max 100KB, timeout. Only parse ICON NAMES, not external HTML.
        if (empty($result['icon'])) {
            $fallbackIcon=self::resolveWowheadIcon($itemId,$flavor);
            if ($fallbackIcon!==null) {
                $result['icon']=$fallbackIcon;
                $result['available']=true;
                $result['provider']='wowhead-item-icon-fallback';
            }
        }
        // Cached failures have a short TTL, successful metadata lasts a week.
        if (!is_dir($cacheDir)) @mkdir($cacheDir,0700,true);
        if (is_dir($cacheDir) && !is_link($cacheDir)) {
            $tmp=@tempnam($cacheDir,'.bl-');
            if ($tmp !== false) {
                @chmod($tmp,0600);
                file_put_contents($tmp,json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),LOCK_EX);
                @rename($tmp,$path);
                if (empty($result['icon'])) @touch($path,time()-7*86400+300);
            }
        }
        return $result;
    }

    /** A bounded public icon lookup; never forwards the user's stored variant string. */
    public static function resolveWowheadIcon(int $itemId, string $flavor = GameFlavor::DEFAULT): ?string {
        if ($itemId<1 || $itemId>99999999 || !function_exists('curl_init') || !GameFlavor::valid($flavor)) return null;
        $urls=[
            'https://nether.wowhead.com/tooltip/item/'.$itemId.'?dataEnv='.GameFlavor::tooltipEnv($flavor),
            GameFlavor::wowheadItem($flavor,$itemId).'&xml',
        ];
        foreach($urls as $url) {
            $ch=curl_init($url);
            curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,
                CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>4,CURLOPT_SSL_VERIFYPEER=>true,
                CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_MAXFILESIZE=>100000,
                CURLOPT_USERAGENT=>'Banklord/0.8.1 icon-only',
                CURLOPT_HTTPHEADER=>['Accept: application/json, application/xml;q=0.9'],
            ]);
            $body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
            if (!is_string($body)||strlen($body)>100000||$status!==200)continue;
            $candidate=null;
            if (str_starts_with(ltrim($body),'{')) {
                $data=json_decode($body,true,20);
                if (is_array($data)) {
                    $row=is_array($data['data']??null)?$data['data']:$data;
                    $candidate=$row['icon']??$row['iconName']??null;
                }
            } elseif (preg_match('~<icon(?:\s[^>]*)?>\s*([A-Za-z][A-Za-z0-9_]{1,79})\s*</icon>~i',$body,$m)) {
                $candidate=$m[1];
            }
            $icon=self::iconUrl($candidate);
            if($icon!==null)return $icon;
        }
        return null;
    }

    /**
     * Returns ONLY modifiers documented by the current MoP Warcraft DB tooltip API.
     * Non-renderable suffixes, reforging, upgrades and scaling are deliberately flagged.
     * Item IDs and itemString originate from an existing guild stock record, NEVER a URL.
     */
    public static function variantInfo(int $itemId, string $variantKey): array {
        $fallback = ['cache_key'=>'base:'.$itemId, 'query'=>'', 'status'=>'base',
            'notes'=>'Grundwerte der MoP-Datenbank. Keine Bestätigung individueller Ausrüstungswerte.', 'applied'=>[]];
        if (strlen($variantKey)>180 || !preg_match('/^item:[0-9]+(?::[-0-9]*)*$/D', $variantKey)) {
            return array_merge($fallback, ['status'=>'unverified','notes'=>'Gegenstandsvariante nicht lesbar; nur Grundwerte.']);
        }
        $parts=explode(':',$variantKey);
        if ((int)($parts[1]??0)!==$itemId) return array_merge($fallback, ['status'=>'unverified', 'notes'=>'Item-ID und gespeicherte Variante stimmen nicht überein.']);
        $num=static function (int $position) use ($parts): ?int {
            $raw=$parts[$position]??'';
            if ($raw==='') return 0;
            if (!preg_match('/^-?[0-9]{1,11}$/D',$raw))return null;
            $value=(int)$raw;
            return abs($value)<=4294967295 ? $value : null;
        };
        $enchant=$num(2);
        $gems=[$num(3),$num(4),$num(5)];
        // The fourth slot is part of itemStrings but is not handled by the documented
        // API's examples. Treat it as an unknown modifier when non-zero.
        $gem4=$num(6);
        $unknown=[];
        if ($enchant===null || $enchant<0 || in_array(null,$gems,true) || $gem4===null) $unknown[]='ungültige Item-Modifikatoren';
        $suffix=$num(7);
        if ($suffix!==0) $unknown[]='zufällige Eigenschaften/Suffix';
        if ($gem4!==0) $unknown[]='vierter Sockel';
        // Client link-level (position 9) can change heirloom stats. This API is not
        // documented as a lossless client-side itemString renderer.
        for($i=11;$i<count($parts);$i++) {
            $x=$num($i);
            if ($x===null || $x!==0) { $unknown[]='weitere Item-Modifikatoren'; break; }
        }
        $query=[]; $applied=[];
        if($enchant!==null && $enchant>0){$query['ench']=(string)$enchant;$applied[]='Verzauberung';}
        if(!in_array(null,$gems,true) && array_sum($gems)>0){
            $query['gems']=implode(':',$gems);$applied[]='Edelsteine';
        }
        $status=$unknown ? 'partial' : ($applied ? 'modifiers' : 'base');
        $notes= $unknown ? 'Nicht vollständig rekonstruierbar: '.implode(', ',array_unique($unknown)).'. Angezeigte Ausrüstungswerte können vom Ingame-Gegenstand abweichen.'
            : ($applied ? 'Verzauberung/Edelsteine wurden an die MoP-Datenquelle übergeben; Ingame-Abgleich noch erforderlich.'
                         : 'MoP-Datenbankwerte; aus dem Item-Link lassen sich nicht alle Ingame-Situationen abbilden.');
        return ['cache_key'=>$variantKey, 'query'=>$query?'?'.http_build_query($query,'','&',PHP_QUERY_RFC3986):'',
            'status'=>$status,'notes'=>$notes,'applied'=>$applied];
    }

    /** Isolated, deterministic parser; tests do not need internet access. */
    public static function normalize(string $payload): ?array {
        $data=json_decode($payload,true,32);
        if (!is_array($data)) return null;
        // Published API describes JSON html, icon, name and quality.
        $item=is_array($data['data']??null)?$data['data']:$data;
        $icon=self::iconUrl($item['icon']??($item['iconName']??null));
        $quality=self::quality($item['quality']??null);
        $html=self::cleanHtml($item['html']??($item['tooltipHtml']??null));
        if ($icon===null && $html===null && $quality===null) return null;
        return ['available'=>true,'icon'=>$icon,'quality'=>$quality,'tooltip_html'=>$html,'provider'=>'mop.warcraftdb.com'];
    }
    private static function quality(mixed $quality): ?int {
        if (is_int($quality) && $quality>=0 && $quality<=7) return $quality;
        if (is_string($quality)) {
            if (preg_match('/^[0-7]$/D',$quality)) return (int)$quality;
            $values=['poor'=>0,'common'=>1,'uncommon'=>2,'rare'=>3,'epic'=>4,'legendary'=>5,'artifact'=>6,'heirloom'=>7];
            return $values[strtolower($quality)]??null;
        }
        return null;
    }
    /** Trust only explicit image URLs on the two expected CDN/provider hosts.
     * Keep the provider's actual path: do not incorrectly rewrite an ID to a
     * Wowhead filename, or a WarcraftDB image into a different asset. */
    public static function iconUrl(mixed $value): ?string {
        if (is_array($value)) $value=$value['url']??$value['src']??$value['name']??$value['iconName']??null;
        if (!is_string($value)) return null;
        $value=trim($value);
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]{1,79}$/D',$value)) {
            return 'https://wow.zamimg.com/images/wow/icons/medium/'.strtolower($value).'.jpg';
        }
        if (str_starts_with($value,'/')) $value='https://mop.warcraftdb.com'.$value;
        $parsed=parse_url($value);
        if (!is_array($parsed) || ($parsed['scheme']??null)!=='https'
          || !in_array($parsed['host']??null,['wow.zamimg.com','mop.warcraftdb.com'],true)
          || (isset($parsed['query']) || isset($parsed['fragment'])) || isset($parsed['port'])
          || isset($parsed['user']) || isset($parsed['pass'])) return null;
        $path=$parsed['path']??'';
        // A numeric FileDataID is not a supported filename on Wowhead's
        // /images/wow/icons/... CDN. Reject stale 0.7.8 cache entries.
        if ($parsed['host']==='wow.zamimg.com' && preg_match('~^/images/wow/icons/(?:small|medium|large)/[0-9]+\.(?:png|jpg|jpeg|webp)$~iD',$path)) return null;
        if (strlen($path)>220 || str_contains($path,'..')
          || !preg_match('~^/[A-Za-z0-9_./%-]+\.(?:png|jpg|jpeg|webp)$~iD',$path)) return null;
        return $value;
    }
    public static function cleanHtml(mixed $html): ?string {
        if (!is_string($html) || strlen($html)>60000 || trim($html)==='') return null;
        if (!class_exists(\DOMDocument::class)) {
            // No DOM extension? Render only escaped PLAIN TEXT, never raw provider markup.
            $text=preg_replace('~<(?:script|style)[^>]*>.*?</(?:script|style)>~is','',$html);
            $text=preg_replace('~<br\s*/?>|</(?:div|p|tr)>~i',"\n",$text);
            $text=trim(html_entity_decode(strip_tags($text),ENT_QUOTES|ENT_HTML5,'UTF-8'));
            return $text!=='' ? nl2br(htmlspecialchars($text,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')) : null;
        }
        $dom=new \DOMDocument();
        $previous=libxml_use_internal_errors(true);
        try {$ok=$dom->loadHTML('<?xml encoding="UTF-8"><div>'.$html.'</div>',LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);}
        finally {libxml_clear_errors();libxml_use_internal_errors($previous);}
        if (!$ok) return null;
        $wrap=$dom->getElementsByTagName('div')->item(0);
        if (!$wrap) return null;
        $out=''; foreach ($wrap->childNodes as $child) $out.=self::sanitizeNode($child,0);
        return $out!==''?$out:null;
    }
    private static function sanitizeNode(\DOMNode $node,int $depth): string {
        if ($depth>24) return '';
        if ($node instanceof \DOMText) return htmlspecialchars($node->textContent,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        if (!$node instanceof \DOMElement) return '';
        $tag=strtolower($node->tagName);
        // Never render scripts, embedded media, forms, SVG or stylesheet content.
        if (in_array($tag,['script','style','iframe','form','input','button','object','embed','svg','math','img','link','meta'],true)) return '';
        $inner='';foreach ($node->childNodes as $child)$inner.=self::sanitizeNode($child,$depth+1);
        $allowed=['div','span','p','b','strong','i','em','small','br','table','tbody','thead','tr','td','th','ul','ol','li','h3','h4'];
        if (!in_array($tag,$allowed,true)) return $inner;
        if ($tag==='br') return '<br>';
        $class='';
        if ($node->hasAttribute('class')) {
            // Accept quality classes only, no arbitrary provider CSS selectors.
            $tokens=preg_split('/\\s+/',trim($node->getAttribute('class')))?:[];
            foreach ($tokens as $token) if (preg_match('/^q[0-7]$/D',$token)){$class=' class="bl-'.$token.'"';break;}
        }
        return '<'.$tag.$class.'>'.$inner.'</'.$tag.'>';
    }
}
