<?php
declare(strict_types=1);
namespace Banklord;
use PDO;
require_once __DIR__.'/IconMap.php';

/** Displays only; neither a scan nor its client strings authorize stock mutations. */
final class ItemMetadata {
    public const LOCALES=['deDE','enUS','enGB','frFR','esES','esMX','ruRU','itIT','ptBR','koKR','zhCN','zhTW'];
    /** The FileDataID is the texture the client actually rendered in the bank slot,
     * so it wins over a name guessed from GetItemInfo. Unresolved => null. */
    public static function iconUrl(mixed $name, mixed $fileID): ?string {
        $mapped=IconMap::name($fileID);
        if ($mapped!==null) return IconMap::url($mapped);
        if (is_string($name)) return IconMap::url($name);
        return null;
    }

    private static function string(mixed $x, int $max, string $field, bool $optional=false): ?string {
        if ($optional && $x===null) return null;
        if (!is_string($x) || strlen($x)>$max || ($x==='' && !$optional) || !preg_match('//u',$x)
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$x)) throw new \InvalidArgumentException('META_'.$field);
        return $x;
    }
    private static function num(mixed $x, int $max, string $field): ?int {
        if ($x===null) return null;
        if (!is_int($x) || $x<0 || $x>$max) throw new \InvalidArgumentException('META_'.$field);
        return $x;
    }
    /** Old v2 scans without itemMetadata stay valid. No hidden stock authority. */
    public static function fromScan(array $scan, array $lines): array {
        if (!isset($scan['itemMetadata'])) return [];
        $raw=$scan['itemMetadata'];
        if (!is_array($raw) || count($raw)>250) throw new \InvalidArgumentException('META_COUNT');
        if ($raw===[]) return [];
        $locale=self::string($scan['locale']??null,8,'LOCALE');
        if (!in_array($locale,self::LOCALES,true)) throw new \InvalidArgumentException('META_LOCALE');
        $version=self::string($scan['clientVersion']??null,32,'CLIENT_VERSION');
        if (!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:\.[0-9]+)?$/D',$version)) throw new \InvalidArgumentException('META_CLIENT_VERSION');
        $build=self::num($scan['clientBuild']??null,2147483647,'CLIENT_BUILD');
        if ($build===null) throw new \InvalidArgumentException('META_CLIENT_BUILD');
        $allowed=[];foreach($lines as $line)$allowed[$line['variant_key']]=$line['wow_item_id'];
        $output=[];
        foreach ($raw as $key=>$m) {
            if (!is_string($key) || !isset($allowed[$key]) || !is_array($m)) throw new \InvalidArgumentException('META_VARIANT_FOREIGN');
            if (($m['itemKey']??null)!==$key || ($m['itemID']??null)!==$allowed[$key]) throw new \InvalidArgumentException('META_VARIANT_CONFLICT');
            $name=self::string($m['name']??null,200,'NAME');
            $quality=self::num($m['quality']??null,7,'QUALITY');
            $stats=$m['stats']??[];if (!is_array($stats) || count($stats)>48) throw new \InvalidArgumentException('META_STATS');
            $checkedStats=[];
            foreach ($stats as $k=>$v) {
                if (!is_string($k) || !preg_match('/^[A-Z0-9_]{1,70}$/D',$k) || !is_numeric($v) || !is_finite((float)$v) || abs((float)$v)>10000000) throw new \InvalidArgumentException('META_STAT_VALUE');
                $checkedStats[$k]=(float)$v;
            }
            $rows=$m['tooltipLines']??[];
            if (!is_array($rows) || count($rows)>40) throw new \InvalidArgumentException('META_TOOLTIP_ROWS');
            $checked=[];
            foreach($rows as $row){
                if(!is_array($row))throw new \InvalidArgumentException('META_TOOLTIP_ROW');
                $l=self::string($row['left']??'',240,'TOOLTIP_LEFT',true);
                $r=self::string($row['right']??'',240,'TOOLTIP_RIGHT',true);
                $colors=[];
                foreach(['leftColor','rightColor'] as $field){
                    $c=$row[$field]??null;
                    if ($c!==null && (!is_string($c)||!preg_match('/^[0-9a-f]{6}$/D',$c))) throw new \InvalidArgumentException('META_TOOLTIP_COLOR');
                    $colors[$field]=$c;
                }
                if($l!==''||$r!=='')$checked[]=['left'=>$l,'right'=>$r]+$colors;
            }
            $iconName=self::string($m['iconName']??null,80,'ICON_NAME',true);
            if ($iconName!==null && !preg_match('/^[A-Za-z][A-Za-z0-9_]{1,79}$/D',$iconName)) throw new \InvalidArgumentException('META_ICON_NAME');
            $output[]=[
                'itemID'=>$allowed[$key], 'variantKey'=>$key, 'variantHash'=>hash('sha256',$key),
                'clientVersion'=>$version, 'clientBuild'=>$build, 'locale'=>$locale, 'name'=>$name,
                'quality'=>$quality, 'itemLevel'=>self::num($m['itemLevel']??null,100000,'ITEM_LEVEL'),
                'requiredLevel'=>self::num($m['requiredLevel']??null,100000,'REQUIRED_LEVEL'),
                'itemType'=>self::string($m['itemType']??null,100,'TYPE',true),
                'itemSubType'=>self::string($m['itemSubType']??null,100,'SUBTYPE',true),
                'equipLoc'=>self::string($m['equipLoc']??null,50,'EQUIPLOC',true),
                'classID'=>self::num($m['classID']??null,100000,'CLASSID'),
                'subclassID'=>self::num($m['subclassID']??null,100000,'SUBCLASSID'),
                'iconFileID'=>self::num($m['iconFileID']??null,4294967295,'ICON_FILEID'),
                'iconName'=>$iconName,'stats'=>$checkedStats, 'tooltip'=>$checked,
                'completeness'=>count($checked)>0?'tooltip':'basic',
            ];
        }
        return $output;
    }
    public static function persist(PDO $db,int $guildId,array $import): void {
        if (empty($import['metadata'])) return;
        $sql="INSERT INTO item_client_metadata
          (guild_id,client_flavor,client_version,client_build,locale,wow_item_id,variant_hash,variant_key,
          localized_name,quality,item_level,required_level,item_type,item_sub_type,equip_loc,class_id,
          subclass_id,icon_file_id,icon_name,completeness,stats_json,tooltip_json,captured_at)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
          ON DUPLICATE KEY UPDATE
          localized_name=IF(VALUES(captured_at)>=captured_at,VALUES(localized_name),localized_name),
          quality=IF(VALUES(captured_at)>=captured_at,VALUES(quality),quality),
          item_level=IF(VALUES(captured_at)>=captured_at,VALUES(item_level),item_level),
          required_level=IF(VALUES(captured_at)>=captured_at,VALUES(required_level),required_level),
          item_type=IF(VALUES(captured_at)>=captured_at,VALUES(item_type),item_type),
          item_sub_type=IF(VALUES(captured_at)>=captured_at,VALUES(item_sub_type),item_sub_type),
          equip_loc=IF(VALUES(captured_at)>=captured_at,VALUES(equip_loc),equip_loc),
          class_id=IF(VALUES(captured_at)>=captured_at,VALUES(class_id),class_id),
          subclass_id=IF(VALUES(captured_at)>=captured_at,VALUES(subclass_id),subclass_id),
          icon_file_id=IF(VALUES(captured_at)>=captured_at,VALUES(icon_file_id),icon_file_id),
          icon_name=IF(VALUES(captured_at)>=captured_at,VALUES(icon_name),icon_name),
          stats_json=IF(VALUES(captured_at)>=captured_at,VALUES(stats_json),stats_json),
          tooltip_json=IF(VALUES(captured_at)>=captured_at,VALUES(tooltip_json),tooltip_json),
          completeness=IF(VALUES(captured_at)>=captured_at,VALUES(completeness),completeness),
          captured_at=GREATEST(captured_at,VALUES(captured_at))";
        $s=$db->prepare($sql);$flavor=GameFlavor::of($db,$guildId);
        foreach($import['metadata'] as $m){
            $s->execute([$guildId,$flavor,$m['clientVersion'],$m['clientBuild'],$m['locale'],
                $m['itemID'],$m['variantHash'],$m['variantKey'],$m['name'],$m['quality'],
                $m['itemLevel'],$m['requiredLevel'],$m['itemType'],$m['itemSubType'],$m['equipLoc'],
                $m['classID'],$m['subclassID'],$m['iconFileID'],$m['iconName'],$m['completeness'],
                json_encode($m['stats'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
                json_encode($m['tooltip'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
                gmdate('Y-m-d H:i:s',$import['observed_at'])]);
        }
    }
    public static function resolve(PDO $db,int $guildId,int $itemID,string $variant,string $locale): ?array {
        if(!in_array($locale,self::LOCALES,true)) throw new \InvalidArgumentException('META_LOCALE');
        // No cross-version or cross-guild fallback; those can show wrong equipment stats.
        $s=$db->prepare("SELECT localized_name,quality,item_level,required_level,item_type,item_sub_type,
           icon_file_id,icon_name,stats_json,tooltip_json,completeness,client_build,client_version,
           captured_at,variant_key FROM item_client_metadata WHERE guild_id=? AND client_flavor=(SELECT wow_flavor FROM guilds WHERE id=guild_id)
           AND locale=? AND wow_item_id=? AND variant_hash=?
           ORDER BY captured_at DESC,client_build DESC LIMIT 1");
        $s->execute([$guildId,$locale,$itemID,hash('sha256',$variant)]);
        $m=$s->fetch(PDO::FETCH_ASSOC);
        if(!$m || !hash_equals((string)$m['variant_key'],$variant)) return null;
        $icon=self::iconUrl($m['icon_name'], $m['icon_file_id']);
        return [
            'available'=>true,'provider'=>'game-client-snapshot', 'icon'=>$icon,'icon_file_id'=>$m['icon_file_id']===null?null:(int)$m['icon_file_id'],
            'quality'=>$m['quality']===null?null:(int)$m['quality'],'name'=>$m['localized_name'],
            'tooltip_lines'=>json_decode($m['tooltip_json']??'[]',true)??[],
            'stats'=>json_decode($m['stats_json']??'{}',true)??[],
            'item_level'=>$m['item_level']===null?null:(int)$m['item_level'],
            'required_level'=>$m['required_level']===null?null:(int)$m['required_level'],
            'item_type'=>$m['item_type'],'item_sub_type'=>$m['item_sub_type'],
            'client_build'=>(int)$m['client_build'],'client_version'=>$m['client_version'],
            'source_observed_at'=>$m['captured_at'],'tooltip_locale'=>$locale,
            'variant_status'=>($m['completeness']==='tooltip'?'captured':'partial'),
            'variant_notes'=>($m['completeness']==='tooltip'?'Tooltip aus einem vollständigen Itemlink des WoW-Clients übernommen; Importdaten nicht unabhängig verifiziert.':'Die vollständigen Tooltip-Zeilen wurden im Client nicht erfasst.'),
        ];
    }
}
