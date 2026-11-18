<?php
declare(strict_types=1);
namespace Banklord;

/**
 * WoW game versions a guild can belong to. Each guild has exactly one; its
 * items, scans and in-game data are kept apart from other versions.
 * Keys match the addon (A.gameFlavor) and guilds.wow_flavor.
 */
final class GameFlavor
{
    public const DEFAULT='mop_classic';
    /** key => [German label, Wowhead path, Wowhead tooltip data environment] */
    public const ALL=[
        'retail'=>['Retail (aktuelles WoW)','',1],
        'mop_classic'=>['Mists of Pandaria Classic','mop-classic',8],
        'anniversary'=>['Classic Anniversary (TBC)','tbc',5],
        'classic_era'=>['Classic Era / Hardcore','classic',4],
        // Classic-based, realmless; icons fall back to the Classic data set.
        'forever'=>['WoW Forever','forever',4],
    ];

    public static function valid(mixed $flavor): bool { return is_string($flavor) && isset(self::ALL[$flavor]); }

    public static function check(mixed $flavor): string
    {
        if (!self::valid($flavor)) throw new DomainError('INVALID_GAME_VERSION');
        return $flavor;
    }

    public static function wowheadItem(string $flavor, int $item): string
    {
        $path=self::ALL[$flavor][1]??'mop-classic';
        return 'https://www.wowhead.com/'.($path===''?'':$path.'/').'item='.$item;
    }

    public static function tooltipEnv(string $flavor): int { return self::ALL[$flavor][2]??8; }

    public static function of(\PDO $db, int $guild): string
    {
        $s=$db->prepare('SELECT wow_flavor FROM guilds WHERE id=?');$s->execute([$guild]);
        $f=$s->fetchColumn();return self::valid($f)?$f:self::DEFAULT;
    }

    /** Game version recorded by the addon in a SavedVariables file (null for older addons). */
    public static function ofFile(string $savedVars): ?string
    {
        if (!preg_match('/\["gameFlavor"\]\s*=\s*"([a-z_]{3,20})"/', $savedVars, $m)) return null;
        return self::valid($m[1])?$m[1]:null;
    }

    /**
     * Guild admins may switch the game version only while the guild has no
     * stock yet; otherwise items of two versions would get mixed up.
     */
    public static function change(\PDO $db, int $guild, int $actor, mixed $flavor): string
    {
        $flavor=self::check($flavor);
        $r=$db->prepare("SELECT role FROM guild_memberships WHERE guild_id=? AND user_id=? AND status='active'");$r->execute([$guild,$actor]);
        if ($r->fetchColumn()!=='admin') throw new DomainError('FORBIDDEN');
        if (self::of($db,$guild)===$flavor) return $flavor;
        $s=$db->prepare('SELECT COUNT(*) FROM guild_stock WHERE guild_id=?');$s->execute([$guild]);
        if ((int)$s->fetchColumn()>0) throw new DomainError('GAME_VERSION_LOCKED');
        $db->prepare('UPDATE guilds SET wow_flavor=? WHERE id=?')->execute([$flavor,$guild]);
        $db->prepare("INSERT INTO audit_events (guild_id,actor_user_id,action,entity_type,entity_id,details_json) VALUES (?,?,'guild.game_version','guild',?,?)")
            ->execute([$guild,$actor,$guild,json_encode(['wow_flavor'=>$flavor],JSON_THROW_ON_ERROR)]);
        return $flavor;
    }
}
