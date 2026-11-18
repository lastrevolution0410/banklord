<?php
declare(strict_types=1);
namespace Banklord;

/** Validates a complete *local* MoP 5.5.4 addon scan for staging only.
 *  Never makes physical stock authoritative or changes reservations.
 */
final class ScanImport
{
    private const MAX_TABS = 16;
    private const MAX_SLOTS = 98;
    private const MAX_SCANS = 3;

    public static function fromSavedVariables(string $text, string $guild, string $realm, ?int $now = null): array
    {
        $db = (new SavedVariablesParser($text))->parse();
        if (($db['schemaVersion'] ?? null) !== 2) self::bad('IMPORT_SCHEMA');
        $scans = $db['fullScans'] ?? null;
        if (!is_array($scans) || count($scans) < 1 || count($scans) > self::MAX_SCANS) self::bad('IMPORT_SCANS_INVALID');
        $entries = [];
        foreach ($scans as $number => $scan) {
            if (!is_int($number) || $number < 1 || !is_array($scan)) self::bad('IMPORT_SCAN_RECORD_INVALID');
            $entries[] = $scan;
        }
        // Never silently promote an older scan when the newest was partial/failed.
        $latest = end($entries);
        if (!is_array($latest)) self::bad('IMPORT_SCAN_MISSING');
        return self::normalize($latest, $guild, $realm, $now ?? time());
    }

    private static function bad(string $reason): never { throw new \InvalidArgumentException($reason); }
    private static function positiveInt(mixed $value, int $min, int $max, string $name): int
    {
        if (!is_int($value) || $value < $min || $value > $max) self::bad($name);
        return $value;
    }
    private static function label(mixed $value, int $maxBytes, string $name): string
    {
        if (!is_string($value) || $value === '' || strlen($value) > $maxBytes || !preg_match('//u', $value)) self::bad($name);
        return $value;
    }

    public static function normalize(array $scan, string $guild, string $realm, int $now): array
    {
        if (($scan['formatVersion'] ?? null) !== 2 || ($scan['source'] ?? null) !== 'mop-classic-guildbank') self::bad('IMPORT_FORMAT');
        if (($scan['guild'] ?? null) !== $guild || ($scan['realm'] ?? null) !== $realm) self::bad('IMPORT_GUILD_REALM_MISMATCH');
        if (($scan['mode'] ?? null) !== 'all' || ($scan['quality'] ?? null) !== 'unverified') self::bad('IMPORT_COMPLETE_FULL_SCAN_REQUIRED');
        if (($scan['serverConfirmed'] ?? null) !== false) self::bad('IMPORT_INVALID_CONFIRMATION');
        $timestamp = self::positiveInt($scan['capturedAt'] ?? null, 1, PHP_INT_MAX, 'IMPORT_TIMESTAMP');
        if ($timestamp > $now + 300 || $timestamp < $now - 30*86400) self::bad('IMPORT_TIMESTAMP_OUT_OF_RANGE');
        $total = self::positiveInt($scan['totalTabs'] ?? null, 1, self::MAX_TABS, 'IMPORT_TAB_COUNT');
        $eligible = self::positiveInt($scan['eligibleTabs'] ?? null, 1, $total, 'IMPORT_ELIGIBLE_COUNT');
        $scanned = self::positiveInt($scan['scannedTabs'] ?? null, 1, $total, 'IMPORT_SCANNED_COUNT');
        if ($total !== $eligible || $scanned !== $total || $scan['skippedTabs'] !== 0 || $scan['failedTabs'] !== 0) {
            self::bad('IMPORT_INCOMPLETE_COVERAGE');
        }
        if (!is_array($scan['tabs'] ?? null) || count($scan['tabs']) !== $total || !is_array($scan['errors'] ?? null) || count($scan['errors']) !== 0) {
            self::bad('IMPORT_TAB_DATA_INCOMPLETE');
        }
        $lines = []; $byKey = []; $quantity = 0; $occupied = 0;
        $firstTabCapturedAt = $timestamp;
        // Newer addon versions record the START of their multi-tab read.
        // This tightens conflict detection; never move the observation forward.
        if (isset($scan['scanStartedAt'])) {
            $started = self::positiveInt($scan['scanStartedAt'], 1, PHP_INT_MAX, 'IMPORT_SCAN_START_INVALID');
            if ($started > $timestamp || $started < $timestamp-3600) self::bad('IMPORT_SCAN_START_RANGE');
            $firstTabCapturedAt = $started;
        }
        for ($tabNo = 1; $tabNo <= $total; $tabNo++) {
            $tab = $scan['tabs'][$tabNo] ?? null;
            if (!is_array($tab) || ($tab['status'] ?? null) !== 'captured' || ($tab['tab'] ?? null) !== $tabNo) self::bad('IMPORT_TAB_INVALID');
            self::label($tab['tabName'] ?? null, 120, 'IMPORT_TAB_LABEL_INVALID');
            if (($tab['quality'] ?? null) !== 'unverified' || ($tab['expectedSlots'] ?? null) !== self::MAX_SLOTS) self::bad('IMPORT_TAB_QUALITY_INVALID');
            $captured = self::positiveInt($tab['capturedAt'] ?? null, 1, PHP_INT_MAX, 'IMPORT_TAB_TIMESTAMP');
            if ($captured > $timestamp + 300 || $captured < $timestamp - 3600) self::bad('IMPORT_TAB_TIMESTAMP_INVALID');
            // The scan is a multi-minute observation, not an atomic snapshot.
            // A physical booking after the *first* recorded tab must prevent
            // reconciliation, even if it happened before the final tab.
            $firstTabCapturedAt = min($firstTabCapturedAt, $captured);
            $entries = $tab['items'] ?? null;
            if (!is_array($entries) || count($entries) > self::MAX_SLOTS) self::bad('IMPORT_TAB_ITEMS_INVALID');
            $usedSlots = [];
            foreach ($entries as $entry) {
                if (!is_array($entry)) self::bad('IMPORT_ITEM_INVALID');
                $slot = self::positiveInt($entry['slot'] ?? null, 1, self::MAX_SLOTS, 'IMPORT_SLOT_INVALID');
                if (isset($usedSlots[$slot])) self::bad('IMPORT_DUPLICATE_SLOT');
                $usedSlots[$slot] = true;
                $itemId = self::positiveInt($entry['itemID'] ?? null, 1, 4294967295, 'IMPORT_ITEM_ID_INVALID');
                $count = self::positiveInt($entry['count'] ?? null, 1, 1000000, 'IMPORT_QUANTITY_INVALID');
                $link = self::label($entry['itemLink'] ?? null, 600, 'IMPORT_ITEM_LINK_INVALID');
                $key = self::label($entry['itemKey'] ?? null, 180, 'IMPORT_ITEM_KEY_INVALID');
                if (!preg_match('/\|H(item:[^|]{1,175})\|h/', $link, $m)) self::bad('IMPORT_LINK_FORMAT_INVALID');
                if ($m[1] !== $key || !preg_match('/^item:([0-9]+)(?::|$)/D', $key, $found) || (int)$found[1] !== $itemId) {
                    self::bad('IMPORT_ITEM_IDENTITY_CONFLICT');
                }
                preg_match('/\|h\[([^\]]{1,180})\]\|h/u', $link, $name);
                $itemName = isset($name[1]) ? $name[1] : 'Item #'.$itemId;
                $lines[] = ['tab_no'=>$tabNo, 'slot_no'=>$slot, 'wow_item_id'=>$itemId,
                    'variant_key'=>$key, 'item_link'=>$link, 'item_name'=>$itemName, 'quantity'=>$count];
                $occupied++; $quantity += $count;
                $byKey[$key] = ($byKey[$key] ?? 0) + $count;
            }
        }
        if (($scan['occupiedSlots'] ?? null) !== $occupied || ($scan['totalItemCount'] ?? null) !== $quantity ||
            !is_array($scan['items'] ?? null) || count($scan['items']) !== count($byKey)) {
            self::bad('IMPORT_TOTALS_MISMATCH');
        }
        foreach ($scan['items'] as $aggregate) {
            if (!is_array($aggregate) || !is_string($aggregate['itemKey'] ?? null) ||
                !isset($byKey[$aggregate['itemKey']]) || $aggregate['count'] !== $byKey[$aggregate['itemKey']]) {
                self::bad('IMPORT_AGGREGATE_MISMATCH');
            }
            unset($byKey[$aggregate['itemKey']]);
        }
        if ($byKey !== []) self::bad('IMPORT_AGGREGATE_INCOMPLETE');
        usort($lines, static fn($a, $b) => [$a['tab_no'], $a['slot_no']] <=> [$b['tab_no'], $b['slot_no']]);
        // Content-specific idempotency. Includes guild and timestamp but not the uploading user.
        $fingerprint = hash('sha256', json_encode(['format'=>'banklord-sv2', 'guild'=>$guild, 'realm'=>$realm,
            'timestamp'=>$timestamp, 'tabs'=>$total, 'lines'=>$lines], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        return [
            'source_key'=>'window-v1-'.$fingerprint, 'observed_at'=>$firstTabCapturedAt, 'guild'=>$guild, 'realm'=>$realm,
            'total_tabs'=>$total, 'occupied_slots'=>$occupied, 'item_variants'=>count($scan['items']),
            'total_quantity'=>$quantity, 'lines'=>$lines,
            'metadata'=>ItemMetadata::fromScan($scan,$lines),
            'source'=>'banklord-savedvariables-v2', 'quality'=>'unverified', 'status'=>'pending',
            // Older addons do not record the game version; the server then trusts the guild's setting.
            'flavor'=>GameFlavor::valid($scan['flavor'] ?? null) ? $scan['flavor'] : null,
        ];
    }
}
