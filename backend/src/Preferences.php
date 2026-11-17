<?php
declare(strict_types=1);
namespace Banklord;
use PDO;

/** Only authenticated guild membership may invoke these methods. Every record is guild+user scoped. */
final class Preferences {
    public function __construct(private PDO $db) {}
    public static function validateLines(mixed $lines): array {
        if(!is_array($lines)||count($lines)<1||count($lines)>50)throw new DomainError('INVALID_TEMPLATE_LINES');
        $out=[];
        foreach($lines as $line) {
            if(!is_array($line)||!is_int($line['stock_id']??null)||($line['stock_id']??0)<1)throw new DomainError('INVALID_TEMPLATE_LINE');
            $quantity=Domain::quantity($line['quantity']??null);
            $id=$line['stock_id'];$out[$id]=($out[$id]??0)+$quantity;Domain::quantity($out[$id]);
        }
        ksort($out,SORT_NUMERIC);return array_map(fn($id,$quantity)=>['stock_id'=>$id,'quantity'=>$quantity],array_keys($out),array_values($out));
    }
    private function stock(int $guild,int $stock): void {
        $s=$this->db->prepare('SELECT id FROM guild_stock WHERE guild_id=? AND id=?');$s->execute([$guild,$stock]);
        if(!$s->fetchColumn())throw new DomainError('UNKNOWN_GUILD_STOCK');
    }
    public function favorites(int $guild,int $user): array {
        $s=$this->db->prepare('SELECT f.stock_id,i.name,i.wow_item_id FROM user_favorites f JOIN guild_stock gs ON gs.id=f.stock_id AND gs.guild_id=f.guild_id JOIN items i ON i.id=gs.item_id WHERE f.guild_id=? AND f.user_id=? ORDER BY i.name LIMIT 200');
        $s->execute([$guild,$user]);return $s->fetchAll();
    }
    public function setFavorite(int $guild,int $user,int $stock,bool $enabled): void {
        $this->stock($guild,$stock);
        if($enabled){
            $s=$this->db->prepare('SELECT COUNT(*) FROM user_favorites WHERE guild_id=? AND user_id=?');$s->execute([$guild,$user]);if((int)$s->fetchColumn()>=200)throw new DomainError('FAVORITES_LIMIT');
            $s=$this->db->prepare('INSERT IGNORE INTO user_favorites (guild_id,user_id,stock_id) VALUES (?,?,?)');$s->execute([$guild,$user,$stock]);
        }else{$s=$this->db->prepare('DELETE FROM user_favorites WHERE guild_id=? AND user_id=? AND stock_id=?');$s->execute([$guild,$user,$stock]);}
    }
    public function wishlist(int $guild,int $user): array {
        $s=$this->db->prepare('SELECT w.stock_id,w.desired_qty,i.name,i.wow_item_id FROM user_wishlists w JOIN guild_stock gs ON gs.id=w.stock_id AND gs.guild_id=w.guild_id JOIN items i ON i.id=gs.item_id WHERE w.guild_id=? AND w.user_id=? ORDER BY i.name LIMIT 200');
        $s->execute([$guild,$user]);return $s->fetchAll();
    }
    public function setWishlist(int $guild,int $user,int $stock,?int $qty): void {
        $this->stock($guild,$stock);
        if($qty!==null){Domain::quantity($qty);
            $s=$this->db->prepare('SELECT COUNT(*) FROM user_wishlists WHERE guild_id=? AND user_id=?');$s->execute([$guild,$user]);if((int)$s->fetchColumn()>=200)throw new DomainError('WISHLIST_LIMIT');
            $s=$this->db->prepare('INSERT INTO user_wishlists (guild_id,user_id,stock_id,desired_qty) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE desired_qty=VALUES(desired_qty)');$s->execute([$guild,$user,$stock,$qty]);
        }else{$s=$this->db->prepare('DELETE FROM user_wishlists WHERE guild_id=? AND user_id=? AND stock_id=?');$s->execute([$guild,$user,$stock]);}
    }
    public function templates(int $guild,int $user): array {
        $s=$this->db->prepare('SELECT id,title,lines_json,created_at FROM user_order_templates WHERE guild_id=? AND user_id=? ORDER BY id DESC LIMIT 30');$s->execute([$guild,$user]);
        $rows=$s->fetchAll();foreach($rows as &$row)$row['lines']=json_decode($row['lines_json'],true);unset($row);
        return array_map(function($r){unset($r['lines_json']);return $r;},$rows);
    }
    public function createTemplate(int $guild,int $user,string $title,mixed $lines): int {
        $title=trim($title);if(mb_strlen($title)<2||mb_strlen($title)>90)throw new DomainError('INVALID_TEMPLATE_TITLE');
        $clean=self::validateLines($lines);
        $s=$this->db->prepare('SELECT COUNT(*) FROM user_order_templates WHERE guild_id=? AND user_id=?');$s->execute([$guild,$user]);if((int)$s->fetchColumn()>=30)throw new DomainError('TEMPLATES_LIMIT');
        foreach($clean as $line)$this->stock($guild,$line['stock_id']);
        $s=$this->db->prepare('INSERT INTO user_order_templates (guild_id,user_id,title,lines_json) VALUES (?,?,?,?)');$s->execute([$guild,$user,$title,json_encode($clean,JSON_THROW_ON_ERROR)]);
        return (int)$this->db->lastInsertId();
    }
    public function removeTemplate(int $guild,int $user,int $id): void {
        $s=$this->db->prepare('DELETE FROM user_order_templates WHERE guild_id=? AND user_id=? AND id=?');$s->execute([$guild,$user,$id]);
    }
}
