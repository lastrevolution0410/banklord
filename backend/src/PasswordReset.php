<?php
declare(strict_types=1);
namespace Banklord;

use PDO;

/**
 * "Forgot password": a one-time link by e-mail, valid for one hour.
 * The answer never reveals whether an e-mail address has an account.
 */
final class PasswordReset
{
    public function __construct(private PDO $db, private Mailer $mail) {}

    /** Always returns quietly; sends a link only to an existing, real address. */
    public function request(string $email,string $lang='de'): void
    {
        $email=mb_strtolower(trim($email));
        if (!filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($email)>254) throw new DomainError('INVALID_EMAIL');
        if (!$this->mail->ready()) throw new DomainError('MAIL_NOT_CONFIGURED');
        $s=$this->db->prepare('SELECT id,display_name FROM users WHERE email=?');$s->execute([$email]);$u=$s->fetch(PDO::FETCH_ASSOC);
        if (!$u || str_ends_with($email,'.invalid')) return;
        // At most 3 links per account and hour.
        $c=$this->db->prepare('SELECT COUNT(*) FROM password_resets WHERE user_id=? AND created_at>NOW()-INTERVAL 1 HOUR');$c->execute([(int)$u['id']]);
        if ((int)$c->fetchColumn()>=3) return;
        $link=$this->mail->siteUrl().'/#reset='.$this->createFor((int)$u['id'],1);
        $en=$lang==='en';
        $body=$en
            ? "Hello {$u['display_name']},\n\nsomeone asked to reset your Banklord password. Choose a new one here (valid for one hour):\n\n$link\n\nIf that was not you, simply ignore this e-mail – your password stays as it is.\n\nBanklord"
            : "Hallo {$u['display_name']},\n\njemand möchte dein Banklord-Passwort zurücksetzen. Hier legst du ein neues fest (eine Stunde gültig):\n\n$link\n\nWarst du das nicht? Dann ignoriere diese E-Mail einfach – dein Passwort bleibt wie es ist.\n\nBanklord";
        try { $this->mail->send($email,$en?'Banklord: reset your password':'Banklord: Passwort zurücksetzen',$body); }
        catch (DomainError $e) { if ($e->getMessage()!=='MAIL_BAD_RECIPIENT') throw $e; }
    }

    /** New one-time token for an account (also used by the operator); returns the token. */
    public function createFor(int $user,int $hours): string
    {
        $token='bpr1_'.bin2hex(random_bytes(32));
        $this->db->prepare('INSERT INTO password_resets (user_id,token_hash,expires_at) VALUES (?,?,UTC_TIMESTAMP()+INTERVAL ? HOUR)')->execute([$user,hash('sha256',$token),max(1,min(72,$hours))]);
        return $token;
    }

    /** Sets the new password with a valid link; returns the user id. */
    public function reset(string $token,string $password): int
    {
        if (!preg_match('/^bpr1_[a-f0-9]{64}$/D',$token)) throw new DomainError('RESET_INVALID');
        if (!Community::passwordAllowed($password)) throw new DomainError('WEAK_PASSWORD');
        $this->db->beginTransaction();
        try {
            $s=$this->db->prepare('SELECT id,user_id FROM password_resets WHERE token_hash=? AND used_at IS NULL AND expires_at>UTC_TIMESTAMP() FOR UPDATE');
            $s->execute([hash('sha256',$token)]);$r=$s->fetch(PDO::FETCH_ASSOC);
            if (!$r) throw new DomainError('RESET_INVALID');
            $uid=(int)$r['user_id'];
            $this->db->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($password,PASSWORD_DEFAULT),$uid]);
            // All open links of this account become invalid.
            $this->db->prepare('UPDATE password_resets SET used_at=UTC_TIMESTAMP() WHERE user_id=? AND used_at IS NULL')->execute([$uid]);
            $this->db->commit();return $uid;
        } catch (\Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
    }
}
