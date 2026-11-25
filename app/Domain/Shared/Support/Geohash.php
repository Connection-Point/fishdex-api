<?php

namespace App\Domain\Shared\Support;

/**
 * Minimal geohash encoder. FishDex stores only a 5-char geohash (~5 km cell)
 * in queryable form; precise coordinates are encrypted (decision D-12).
 */
final class Geohash
{
    private const BASE32 = '0123456789bcdefghjkmnpqrstuvwxyz';

    public static function encode(float $lat, float $lng, int $precision = 5): string
    {
        $latRange = [-90.0, 90.0];
        $lngRange = [-180.0, 180.0];
        $hash = '';
        $bit = 0;
        $ch = 0;
        $even = true;

        while (strlen($hash) < $precision) {
            if ($even) {
                $mid = ($lngRange[0] + $lngRange[1]) / 2;
                if ($lng >= $mid) {
                    $ch |= 1 << (4 - $bit);
                    $lngRange[0] = $mid;
                } else {
                    $lngRange[1] = $mid;
                }
            } else {
                $mid = ($latRange[0] + $latRange[1]) / 2;
                if ($lat >= $mid) {
                    $ch |= 1 << (4 - $bit);
                    $latRange[0] = $mid;
                } else {
                    $latRange[1] = $mid;
                }
            }
            $even = ! $even;

            if ($bit < 4) {
                $bit++;
            } else {
                $hash .= self::BASE32[$ch];
                $bit = 0;
                $ch = 0;
            }
        }

        return $hash;
    }
}
