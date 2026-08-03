<?php

namespace App\Support;

use App\Models\Customer;

/**
 * THE loyalty rules. A customer earns a badge — and with it a gift — once
 * their lifetime spend passes a threshold; the first one is at 50,000 AFN,
 * which is the level the owner asked for. Every screen that shows a badge
 * reads it from here, so the shop and the reports can never disagree.
 */
class Loyalty
{
    /** Thresholds in AFN of lifetime spend, ascending. */
    public const TIERS = [
        ['key' => 'bronze', 'label' => 'Bronze', 'from' => 50_000, 'color' => '#B4762B', 'gift' => 'A gift hamper'],
        ['key' => 'silver', 'label' => 'Silver', 'from' => 100_000, 'color' => '#8A94A6', 'gift' => 'A gift hamper + 5% off'],
        ['key' => 'gold', 'label' => 'Gold', 'from' => 200_000, 'color' => '#C8862D', 'gift' => 'A gift hamper + 8% off'],
        ['key' => 'platinum', 'label' => 'Platinum', 'from' => 500_000, 'color' => '#123A66', 'gift' => 'A gift hamper + 12% off'],
    ];

    /**
     * The badge a spend level has earned, or null below the first threshold.
     *
     * @return array{key:string,label:string,from:int,color:string,gift:string}|null
     */
    public static function tierFor(float $spend): ?array
    {
        $earned = null;
        foreach (self::TIERS as $tier) {
            if ($spend >= $tier['from']) {
                $earned = $tier;
            }
        }

        return $earned;
    }

    /** The next badge up, or null when the top one is already held. */
    public static function nextTierFor(float $spend): ?array
    {
        foreach (self::TIERS as $tier) {
            if ($spend < $tier['from']) {
                return $tier;
            }
        }

        return null;
    }

    /**
     * The loyalty block every customer payload carries.
     *
     * @return array<string, mixed>
     */
    public static function summary(Customer $customer): array
    {
        $spend = (float) $customer->total_spent;
        $tier = self::tierFor($spend);
        $next = self::nextTierFor($spend);

        return [
            'spend' => round($spend, 2),
            'tier' => $tier ? $tier['key'] : null,
            'tier_label' => $tier ? $tier['label'] : null,
            'tier_color' => $tier ? $tier['color'] : null,
            // Passing the first threshold is what earns a gift.
            'gift_eligible' => $tier !== null,
            'gift' => $tier ? $tier['gift'] : null,
            'next_tier' => $next ? $next['label'] : null,
            'next_tier_from' => $next ? $next['from'] : null,
            'to_next_tier' => $next ? round(max(0, $next['from'] - $spend), 2) : 0,
            // How far through the current band they are, for the progress ring.
            'progress' => self::progress($spend),
            'points' => (int) $customer->loyalty_points,
        ];
    }

    /** 0–100 through the band between the held badge and the next one. */
    private static function progress(float $spend): int
    {
        $floor = self::tierFor($spend)['from'] ?? 0;
        $next = self::nextTierFor($spend);
        if (! $next) {
            return 100;
        }
        $span = $next['from'] - $floor;

        return $span <= 0 ? 100 : (int) round(min(100, max(0, (($spend - $floor) / $span) * 100)));
    }
}
