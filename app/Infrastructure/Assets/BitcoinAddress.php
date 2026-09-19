<?php

namespace App\Infrastructure\Assets;

use Brick\Math\BigInteger;

final class BitcoinAddress
{
    public static function valid(string $address): bool
    {
        if (preg_match('/^[13][1-9A-HJ-NP-Za-km-z]{25,34}$/D', $address)) {
            $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
            $n = BigInteger::of(0);
            foreach (str_split($address) as $char) {
                $n = $n->multipliedBy(58)->plus(strpos($alphabet, $char));
            }
            $hex = $n->toBase(16);
            $raw = str_repeat("\0", strspn($address, '1')).hex2bin(str_pad($hex, strlen($hex) + strlen($hex) % 2, '0', STR_PAD_LEFT));

            return strlen($raw) === 25 && in_array(ord($raw[0]), [0, 5], true)
                && hash_equals(substr(hash('sha256', hash('sha256', substr($raw, 0, 21), true), true), 0, 4), substr($raw, 21));
        }
        if (strlen($address) > 90 || (strtolower($address) !== $address && strtoupper($address) !== $address)) {
            return false;
        }
        $address = strtolower($address);
        if (! str_starts_with($address, 'bc1')) {
            return false;
        }
        $alphabet = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';
        $data = [];
        foreach (str_split(substr($address, 3)) as $char) {
            $value = strpos($alphabet, $char);
            if ($value === false) {
                return false;
            }
            $data[] = $value;
        }
        if (count($data) < 7 || $data[0] > 16) {
            return false;
        }
        $check = 1;
        foreach (array_merge([3, 3, 0, 2, 3], $data) as $value) {
            $top = $check >> 25;
            $check = (($check & 0x1FFFFFF) << 5) ^ $value;
            foreach ([0x3B6A57B2, 0x26508E6D, 0x1EA119FA, 0x3D4233DD, 0x2A1462B3] as $i => $generator) {
                if (($top >> $i) & 1) {
                    $check ^= $generator;
                }
            }
        }
        if ($check !== ($data[0] === 0 ? 1 : 0x2BC830A3)) {
            return false;
        }
        $bits = 0;
        $accumulator = 0;
        $length = 0;
        foreach (array_slice($data, 1, -6) as $value) {
            $accumulator = (($accumulator << 5) | $value) & 0xFFF;
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $length++;
            }
        }

        return $bits < 5 && (($accumulator << (8 - $bits)) & 255) === 0
            && $length >= 2 && $length <= 40 && ($data[0] !== 0 || in_array($length, [20, 32], true));
    }
}
