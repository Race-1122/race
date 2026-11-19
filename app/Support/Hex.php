<?php

namespace App\Support;

/**
 * Safe hex helpers — never use ltrim($hex, '0x') (strips ALL leading zeros).
 */
final class Hex
{
    public static function stripPrefix(string $hex): string
    {
        $hex = strtolower(trim($hex));
        if (str_starts_with($hex, '0x')) {
            return substr($hex, 2);
        }

        return $hex;
    }

    public static function wordToDecimal(string $hex64): string
    {
        $hex = ltrim(self::stripPrefix($hex64), '0');
        if ($hex === '') {
            return '0';
        }

        if (! ctype_xdigit($hex)) {
            return '0';
        }

        if (function_exists('gmp_init')) {
            return gmp_strval(gmp_init($hex, 16), 10);
        }

        // No gmp: hexdec() returns a float ("1.0E+18") for big values and breaks bcmath.
        $dec = '0';
        $len = strlen($hex);
        for ($i = 0; $i < $len; $i++) {
            $dec = bcadd(bcmul($dec, '16', 0), (string) hexdec($hex[$i]), 0);
        }

        return $dec;
    }
}
