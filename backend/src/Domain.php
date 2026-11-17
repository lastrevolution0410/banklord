<?php
declare(strict_types=1);

namespace Banklord;

final class Domain
{
    /** No quantity is coerced from floats, negatives, null or invalid strings. */
    public static function quantity(mixed $value): int
    {
        if (!is_int($value) || $value < 1 || $value > 1000000) {
            throw new DomainError('INVALID_QUANTITY');
        }
        return $value;
    }

    public static function availability(int $physical, int $reserved): int
    {
        if ($physical < 0 || $reserved < 0 || $reserved > $physical) {
            throw new DomainError('INVALID_STOCK_STATE');
        }
        return $physical - $reserved;
    }

    public static function reserve(int $physical, int $reserved, int $want): int
    {
        self::quantity($want);
        if ($want > self::availability($physical, $reserved)) {
            throw new DomainError('INSUFFICIENT_STOCK');
        }
        return $reserved + $want;
    }

    /** After handover: both physical AND reserved counts decrease by the fulfilled quantity. */
    public static function fulfill(int $physical, int $reserved, int $want): array
    {
        self::quantity($want);
        self::availability($physical, $reserved);
        if ($want > $reserved || $want > $physical) {
            throw new DomainError('INVALID_FULFILLMENT');
        }
        return ['physical' => $physical - $want, 'reserved' => $reserved - $want];
    }

    public static function roleCan(string $role, string $action): bool
    {
        return match ($action) {
            'order.submit', 'stock.view', 'order.own.view' => in_array($role, ['member','officer','bank_manager','admin'], true),
            'order.approve' => in_array($role, ['officer','bank_manager','admin'], true),
            'order.fulfill' => in_array($role, ['bank_manager','admin'], true),
            'guild.manage' => $role === 'admin',
            default => false,
        };
    }

    /** State after processing all order lines; pending line prohibits final state. */
    public static function orderState(array $lines): string
    {
        $anyPending = false; $anyApproved = false; $anyRejected = false; $anyFulfilled = false; $allDone = true; $shortApproved = false;
        foreach ($lines as $line) {
            if ($line['decision'] === 'pending') $anyPending = true;
            if ($line['decision'] === 'rejected') $anyRejected = true;
            if ((int)$line['approved_qty'] < (int)$line['requested_qty']) $shortApproved = true;
            if ((int)$line['approved_qty'] > 0) $anyApproved = true;
            if ((int)$line['fulfilled_qty'] > 0) $anyFulfilled = true;
            if ((int)$line['approved_qty'] > (int)$line['fulfilled_qty']) $allDone = false;
        }
        if ($anyPending) return 'submitted';
        if (!$anyApproved) return 'rejected';
        if ($allDone) return 'fulfilled';
        if ($anyFulfilled) return 'partially_fulfilled';
        return ($anyRejected || $shortApproved) ? 'partial' : 'reserved';
    }
}
class DomainError extends \RuntimeException {}
