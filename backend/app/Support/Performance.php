<?php

namespace App\Support;

/**
 * Seller-performance scoring, one formula for counters and people so
 * grades are comparable. Three ingredients, each measured against the
 * company benchmark for the same period:
 *
 *   speed  — orders per active hour (how fast the queue moves)
 *   value  — revenue per active hour (how much money the hour brings)
 *   basket — items per order (how well the sale is built)
 *
 * score = 45% speed + 40% value + 15% basket, each capped at 1.5× the
 * benchmark so one crazy day can't hide a slow week. 0–100 → A/B/C/D.
 */
class Performance
{
    public static function score(
        float $ordersPerHour,
        float $revenuePerHour,
        float $itemsPerOrder,
        float $benchOph,
        float $benchRph,
        float $benchIpo
    ): array {
        $part = function (float $value, float $bench): float {
            if ($bench <= 0) {
                return $value > 0 ? 1.0 : 0.0;
            }

            return min($value / $bench, 1.5) / 1.5;
        };

        $score = (int) round(100 * (
            0.45 * $part($ordersPerHour, $benchOph)
            + 0.40 * $part($revenuePerHour, $benchRph)
            + 0.15 * $part($itemsPerOrder, $benchIpo)
        ));

        [$grade, $label] = match (true) {
            $score >= 80 => ['A', 'Excellent'],
            $score >= 60 => ['B', 'Good'],
            $score >= 40 => ['C', 'Average'],
            default => ['D', 'NeedsAttention'],
        };

        return ['score' => $score, 'grade' => $grade, 'label' => $label];
    }
}
