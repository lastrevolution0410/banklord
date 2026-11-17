<?php
declare(strict_types=1);
namespace Banklord;
use PDO;

/** Untrusted drafts relayed over Blizzard addon messages.
 * No privileges, stock changes, orders, reservations or identity assertions.
 */
final class PeerRelay {
 public function __construct(private readonly PDO $db) {}
 public static function extracts(string $savedVars):array {
  $db=(new SavedVariablesParser($savedVars))->parse();
  $rows=$db['peerInbox']??[];
  if(!is_array($rows)||count($rows)>12)throw new \InvalidArgumentException('PEER_INBOX_LIMIT');
  $out=[];
  foreach($rows as $row){
   if(!is_array($row))throw new \InvalidArgumentException('PEER_ROW_INVALID');
   $sender=$row['sender']??null;$mid=$row['messageId']??null;$code=$row['code']??null;$timestamp=$row['receivedAt']??null;
   if(($row['status']??null)!=='unverified_draft'||!is_string($sender)||strlen($sender)<1||strlen($sender)>120||preg_match('/[|\r\n\x00-\x1f]/',$sender)||
      !is_string($mid)||!preg_match('/^[0-9]{1,12}-[0-9]{6}$/D',$mid)||
      !is_int($timestamp)||$timestamp<=0||$timestamp>time()+300||$timestamp<time()-30*86400||
      !is_string($code)||strlen($code)>4000||!self::validCode($code))throw new \InvalidArgumentException('PEER_ROW_INVALID');
   $out[]=['sender'=>$sender,'message_id'=>$mid,'code'=>$code,'received_at'=>$timestamp];
  }
  return $out;
 }
 public static function validCode(string $text):bool {
  if(strlen($text)<12||strlen($text)>4000||!str_starts_with($text,'WGB1|'))return false;
  $parts=explode('|',substr($text,5));
  if(count($parts)<1||count($parts)>50)return false;
  foreach($parts as $part){
   if(!preg_match('/^([1-9][0-9]{0,9}),([1-9][0-9]{0,6}),(item:[0-9:\-]+)$/D',$part,$m))return false;
   if((int)$m[1]>4294967295||(int)$m[2]>1000000||!str_starts_with($m[3],'item:'.$m[1].':'))return false;
  }
  return true;
 }
 public function stage(int $guild,int $actor,string $raw):int {
  $entries=self::extracts($raw);if(!$entries)return 0;
  $this->db->beginTransaction();
  try {
   $auth=$this->db->prepare("SELECT role FROM guild_memberships WHERE guild_id=? AND user_id=? AND status='active' FOR UPDATE");
   $auth->execute([$guild,$actor]);
   if(!in_array($auth->fetchColumn(),['admin','bank_manager'],true))throw new DomainError('FORBIDDEN');
   $count=$this->db->prepare('SELECT COUNT(*) FROM peer_order_drafts WHERE guild_id=? AND imported_at>UTC_TIMESTAMP()-INTERVAL 1 DAY');
   $count->execute([$guild]);if((int)$count->fetchColumn()>100)throw new DomainError('PEER_DRAFT_DAILY_LIMIT');
   $ins=$this->db->prepare("INSERT INTO peer_order_drafts (guild_id,imported_by,sender,message_id,transfer_code,code_sha256,received_at,status)
    VALUES (?,?,?,?,?,?,?,'unverified') ON DUPLICATE KEY UPDATE id=id");
   $created=0;
   foreach($entries as $row){
    $ins->execute([$guild,$actor,$row['sender'],$row['message_id'],$row['code'],hash('sha256',$row['code']),gmdate('Y-m-d H:i:s',$row['received_at'])]);
    if($ins->rowCount()===1)$created++;
   }
   if($created>0){
    $a=$this->db->prepare("INSERT INTO audit_events (guild_id,actor_user_id,action,entity_type,details_json) VALUES (?,?,'peer.drafts.staged','peer_order_draft',?)");
    $a->execute([$guild,$actor,json_encode(['new_drafts'=>$created,'trusted'=>false,'created_orders'=>false],JSON_THROW_ON_ERROR)]);
   }
   $this->db->commit();return $created;
  }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
 }
}
