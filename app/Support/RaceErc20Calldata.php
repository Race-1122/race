<?php

namespace App\Support;

/**
 * Build ERC-20 transfer(address,uint256) calldata for Multisig submitTransaction.
 */
class RaceErc20Calldata
{
    public static function transfer(string $to, string $amountWei): string
    {
        $to = strtolower(trim($to));
        if (! preg_match('/^0x[a-f0-9]{40}$/', $to)) {
            throw new \InvalidArgumentException('Invalid recipient address.');
        }

        $wei = preg_replace('/[^0-9]/', '', $amountWei) ?? '0';
        if ($wei === '' || bccomp($wei, '0', 0) <= 0) {
            throw new \InvalidArgumentException('Invalid RACE wei amount.');
        }

        $selector = 'a9059cbb'; // transfer(address,uint256)
        $addr = str_pad(substr($to, 2), 64, '0', STR_PAD_LEFT);
        $amt = str_pad(self::decToHexNoPrefix($wei), 64, '0', STR_PAD_LEFT);

        return '0x'.$selector.$addr.$amt;
    }

    /**
     * Human RACE (18 decimals) → wei string.
     */
    public static function raceToWei(string $raceAmount): string
    {
        $raceAmount = number_format((float) $raceAmount, 8, '.', '');
        [$whole, $frac] = array_pad(explode('.', $raceAmount, 2), 2, '0');
        $whole = preg_replace('/\D/', '', $whole) ?: '0';
        $frac = str_pad(substr(preg_replace('/\D/', '', $frac) ?: '0', 0, 18), 18, '0');

        return bcadd(bcmul($whole, bcpow('10', '18', 0), 0), $frac, 0);
    }

    private static function decToHexNoPrefix(string $dec): string
    {
        if (function_exists('gmp_strval') && function_exists('gmp_init')) {
            return gmp_strval(gmp_init($dec, 10), 16);
        }

        $hex = '';
        $n = $dec;
        if ($n === '0') {
            return '0';
        }
        while (bccomp($n, '0', 0) > 0) {
            $rem = (int) bcmod($n, '16');
            $hex = dechex($rem).$hex;
            $n = bcdiv($n, '16', 0);
        }

        return $hex === '' ? '0' : $hex;
    }
}
