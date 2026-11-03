<?php

declare(strict_types=1);

namespace MyInvoice\Service\Databox;

/**
 * ISDS box identifier: 7 characters, 24 letters (without o, l) + 8 digits (without 0, 1), the 7th character
 * is a Luhn mod 32 check digit. Algorithm: WS_manipulace_s_datovymi_zpravami.pdf 3.8.1, chapter 1.3.4.
 */
final class DataboxId
{
    private const ALPHABET = 'abcdefghijkmnpqrstuvwxyz23456789';

    public static function isValid(string $id): bool
    {
        if (strlen($id) !== 7) {
            return false;
        }
        $id = strtolower($id);
        $sum = 0;
        for ($i = 0; $i < 6; $i++) {
            $n = strpos(self::ALPHABET, $id[$i]);
            if ($n === false) {
                return false;
            }
            if ($i % 2 === 1) {
                $n *= 2;
            }
            $sum += intdiv($n, 32) + $n % 32;
        }
        $check = $sum % 32;
        if ($check > 0) {
            $check = 32 - $check;
        }
        return $id[6] === self::ALPHABET[$check];
    }

    public static function normalize(string $id): string
    {
        return strtolower(trim($id));
    }
}
