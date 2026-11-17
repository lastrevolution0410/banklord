<?php
declare(strict_types=1);

namespace Banklord;

use PDO;

/**
 * Public read-only demo: a separate guild with sample data and a demo account.
 * Visitors get a session as that account; the API refuses every change.
 * Switched on by an admin under System (or DEMO=on in the configuration).
 */
final class Demo {
    private const ITEMS = [
        // id, German name, English name, quantity, type de/en, subtype de/en, icon
        [72988, 'Windwollstoff', 'Windwool Cloth', 200, 'Handwerkswaren', 'Trade Goods', 'Stoff', 'Cloth', 'inv_fabric_windwool'],
        [72092, 'Geisterhaftes Eisenerz', 'Ghost Iron Ore', 85, 'Handwerkswaren', 'Trade Goods', 'Metall & Stein', 'Metal & Stone', 'inv_ore_ghostiron'],
        [74249, 'Geisterstaub', 'Spirit Dust', 140, 'Handwerkswaren', 'Trade Goods', 'Verzauberkunst', 'Enchanting', 'inv_misc_dust_05'],
        [76061, 'Geist der Harmonie', 'Spirit of Harmony', 9, 'Handwerkswaren', 'Trade Goods', 'Elementar', 'Elemental', 'spell_shaman_spiritlink'],
        [89112, 'Partikel der Harmonie', 'Mote of Harmony', 55, 'Handwerkswaren', 'Trade Goods', 'Elementar', 'Elemental', 'inv_misc_powder_thorium'],
        [76084, 'Fläschchen der Frühlingsblüte', 'Flask of Spring Blossoms', 30, 'Verbrauchbar', 'Consumable', 'Fläschchen', 'Flask', 'inv_alchemy_endlessflask_04'],
        [76085, 'Fläschchen der Wärme', 'Flask of the Warm Sun', 18, 'Verbrauchbar', 'Consumable', 'Fläschchen', 'Flask', 'inv_alchemy_endlessflask_06'],
        [76093, 'Trank der Jadeschlange', 'Potion of the Jade Serpent', 40, 'Verbrauchbar', 'Consumable', 'Trank', 'Potion', 'trade_alchemy_potione3'],
        [74650, 'Mogu-Fischeintopf', 'Mogu Fish Stew', 60, 'Verbrauchbar', 'Consumable', 'Essen & Trinken', 'Food & Drink', 'inv_misc_food_cooked_mogufishstew'],
        [76131, 'Primordialer Rubin', 'Primordial Ruby', 12, 'Edelstein', 'Gem', 'Rot', 'Red', 'inv_misc_gem_x4_rare_uncut_red'],
        [72163, 'Juwel des Kaisers', 'Imperial Amethyst', 7, 'Edelstein', 'Gem', 'Violett', 'Purple', 'inv_misc_gem_x4_rare_uncut_purple'],
        [72120, 'Exotisches Leder', 'Exotic Leather', 48, 'Handwerkswaren', 'Trade Goods', 'Leder', 'Leather', 'inv_misc_leatherexotic'],
    ];

    public function __construct(private readonly PDO $db, private readonly string $root) {}

    private function file(): string { return $this->root.'/private/demo.json'; }

    private function state(): array {
        $raw = @file_get_contents($this->file());
        return is_string($raw) ? (json_decode($raw, true) ?: []) : [];
    }

    private function save(array $st): void {
        @mkdir(dirname($this->file()), 0700, true);
        file_put_contents($this->file(), json_encode($st), LOCK_EX);
    }

    public function enabled(): bool {
        return strtolower((string)getenv('DEMO')) === 'on' || ($this->state()['enabled'] ?? false) === true;
    }

    public function setEnabled(bool $on): void {
        $st = $this->state();
        $st['enabled'] = $on;
        $this->save($st);
        if ($on) $this->ensureSeeded();
    }

    public function guildId(): ?int { $g = $this->state()['guild'] ?? null; return is_int($g) ? $g : null; }

    /** Starts a read-only demo session and returns the demo user id. */
    public function start(): int {
        if (!$this->enabled()) throw new DomainError('DEMO_DISABLED');
        return $this->ensureSeeded();
    }

    private function ensureSeeded(): int {
        $st = $this->state();
        if (isset($st['user'], $st['guild'])) {
            $s = $this->db->prepare("SELECT 1 FROM guild_memberships WHERE guild_id=? AND user_id=? AND status='active'");
            $s->execute([$st['guild'], $st['user']]);
            if ($s->fetchColumn()) return (int)$st['user'];
        }
        $db = $this->db;
        $db->prepare("INSERT INTO guilds (name,realm,region,wow_flavor) VALUES ('Demo','Everlook','EU','mop_classic')")->execute();
        $g = (int)$db->lastInsertId();
        $users = [];
        foreach ([['Demo', 'admin'], ['Aria', 'member'], ['Borin', 'bank_manager'], ['Kaelis', 'member']] as [$name, $role]) {
            // Unusable password: demo accounts can only be entered through the demo button.
            $db->prepare('INSERT INTO users (email,password_hash,display_name) VALUES (?,?,?)')
                ->execute(['demo-'.bin2hex(random_bytes(6)).'@demo.invalid', password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), $name]);
            $users[$name] = (int)$db->lastInsertId();
            $db->prepare('INSERT INTO guild_memberships (guild_id,user_id,role) VALUES (?,?,?)')->execute([$g, $users[$name], $role]);
        }
        $community = new Community($db);
        foreach (['Aria' => 'Ariandel', 'Borin' => 'Borinbank', 'Kaelis' => 'Kaelis'] as $user => $char) {
            try { $community->addCharacter($g, $users[$user], $char, 'Everlook'); } catch (\Throwable $e) {}
        }
        $db->prepare("INSERT INTO stock_snapshots (guild_id,source,source_key,observed_at) VALUES (?,'banklord-savedvariables-v2',?,?)")
            ->execute([$g, 'window-v1-demo-'.bin2hex(random_bytes(4)), gmdate('Y-m-d H:i:s', time() - 300)]);
        $sid = (int)$db->lastInsertId();
        $line = $db->prepare('INSERT INTO snapshot_source_lines (snapshot_id,tab_no,slot_no,wow_item_id,variant_key,item_link,item_name,quantity) VALUES (?,?,?,?,?,?,?,?)');
        $meta = $db->prepare("INSERT INTO item_client_metadata (guild_id,wow_item_id,variant_hash,variant_key,client_flavor,client_version,client_build,locale,localized_name,quality,item_type,item_sub_type,icon_name,completeness,captured_at) VALUES (?,?,?,?,'mop_classic','5.5.4','0',?,?,1,?,?,?,'basic',UTC_TIMESTAMP())");
        foreach (self::ITEMS as $i => [$id, $de, $en, $qty, $typeDe, $typeEn, $subDe, $subEn, $icon]) {
            $key = "item:$id:0:0:0:0:0:0:0:0";
            $line->execute([$sid, intdiv($i, 6) + 1, $i % 6 + 1, $id, $key, 'l', $de, $qty]);
            try {
                $meta->execute([$g, $id, hash('sha256', $key), $key, 'deDE', $de, $typeDe, $subDe, $icon]);
                $meta->execute([$g, $id, hash('sha256', $key), $key, 'enUS', $en, $typeEn, $subEn, $icon]);
            } catch (\Throwable $e) {}
        }
        (new StockReconciliation($db))->confirm($g, $users['Demo'], $sid);
        $stock = function (int $id) use ($db, $g): int {
            $s = $db->prepare('SELECT gs.id FROM guild_stock gs JOIN items i ON i.id=gs.item_id WHERE gs.guild_id=? AND i.wow_item_id=?');
            $s->execute([$g, $id]);
            return (int)$s->fetchColumn();
        };
        $orders = new Orders($db);
        $orders->create($g, $users['Aria'], 'demo-'.bin2hex(random_bytes(8)), [['stock_id' => $stock(76084), 'quantity' => 5], ['stock_id' => $stock(74650), 'quantity' => 20]], 'Raid Donnerstag / raid on Thursday');
        $approved = $orders->create($g, $users['Kaelis'], 'demo-'.bin2hex(random_bytes(8)), [['stock_id' => $stock(72988), 'quantity' => 40]], '');
        $orders->create($g, $users['Demo'], 'demo-'.bin2hex(random_bytes(8)), [['stock_id' => $stock(76093), 'quantity' => 10]], '');
        $s = $db->prepare('SELECT id,requested_qty FROM order_lines WHERE order_id=?');
        $s->execute([$approved]);
        $decisions = [];
        foreach ($s->fetchAll() as $r) $decisions[(int)$r['id']] = (int)$r['requested_qty'];
        try { $orders->decide($g, $users['Borin'], $approved, $decisions); } catch (\Throwable $e) {}
        $this->save(['enabled' => $this->state()['enabled'] ?? false, 'guild' => $g, 'user' => $users['Demo']]);
        return $users['Demo'];
    }
}
