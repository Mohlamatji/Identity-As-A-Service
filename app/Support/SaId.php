<?php

namespace App\Support;

/**
 * South African ID number validation. Cheap, deterministic, and runs before
 * any paid bureau call: 13 digits, Luhn checksum, a real YYMMDD date, and
 * (optionally) agreement with the date of birth the user declared.
 * A mismatch between the ID's embedded DOB and the declared DOB is a classic
 * synthetic-identity signal.
 */
final class SaId
{
    /** @return array{valid:bool, reason:?string, dob:?string, gender:?string, citizen:?bool} */
    public static function inspect(string $id, ?string $declaredDob = null): array
    {
        $id = preg_replace('/\s+/', '', $id);
        $fail = static fn (string $why) => ['valid' => false, 'reason' => $why, 'dob' => null, 'gender' => null, 'citizen' => null];

        if (!preg_match('/^\d{13}$/', $id)) {
            return $fail('ID number must be exactly 13 digits.');
        }
        if (!self::luhn($id)) {
            return $fail('ID number failed its checksum digit.');
        }

        $yy = (int) substr($id, 0, 2);
        $mm = (int) substr($id, 2, 2);
        $dd = (int) substr($id, 4, 2);
        // YY is ambiguous across centuries: assume the most recent past date.
        $year = $yy + ($yy > (int) date('y') ? 1900 : 2000);
        if (!checkdate($mm, $dd, $year)) {
            return $fail('ID number does not contain a valid date of birth.');
        }
        $dob = sprintf('%04d-%02d-%02d', $year, $mm, $dd);

        if ($declaredDob !== null && $declaredDob !== '') {
            $declared = date('Y-m-d', strtotime($declaredDob) ?: 0);
            if ($declared !== $dob) {
                return $fail('Declared date of birth does not match the date embedded in the ID number.');
            }
        }

        return [
            'valid' => true,
            'reason' => null,
            'dob' => $dob,
            'gender' => ((int) substr($id, 6, 4)) >= 5000 ? 'male' : 'female',
            'citizen' => $id[10] === '0',
        ];
    }

    private static function luhn(string $digits): bool
    {
        $sum = 0;
        $len = strlen($digits);
        for ($i = 0; $i < $len; $i++) {
            $d = (int) $digits[$len - 1 - $i];
            if ($i % 2 === 1) {
                $d *= 2;
                if ($d > 9) {
                    $d -= 9;
                }
            }
            $sum += $d;
        }
        return $sum % 10 === 0;
    }
}
