<?php

namespace App\Support;

class PeriodeHelper
{
    public const BULAN_INDO = [
        1 => 'Januari',
        2 => 'Februari',
        3 => 'Maret',
        4 => 'April',
        5 => 'Mei',
        6 => 'Juni',
        7 => 'Juli',
        8 => 'Agustus',
        9 => 'September',
        10 => 'Oktober',
        11 => 'November',
        12 => 'Desember',
    ];

    /**
     * Format BILLAC/BTA: 202609 → "September 2026".
     * Tahun saja (2026) tetap ditampilkan apa adanya.
     */
    public static function label(mixed $periode): string
    {
        $raw = trim((string) ($periode ?? ''));
        if ($raw === '') {
            return '-';
        }

        if (preg_match('/^\d{6}$/', $raw)) {
            $bulan = (int) substr($raw, 4, 2);
            $tahun = substr($raw, 0, 4);
            if (isset(self::BULAN_INDO[$bulan])) {
                return self::BULAN_INDO[$bulan] . ' ' . $tahun;
            }
        }

        return $raw;
    }
}
