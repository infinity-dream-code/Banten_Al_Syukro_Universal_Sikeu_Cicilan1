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

    /**
     * Parse label Excel ("Juli 2026", "202607", "2026") menjadi kode BILLAC.
     */
    public static function fromLabel(mixed $periode): ?string
    {
        $raw = trim((string) ($periode ?? ''));
        if ($raw === '') {
            return null;
        }

        if (preg_match('/^\d{6}$/', $raw)) {
            $bulan = (int) substr($raw, 4, 2);
            return ($bulan >= 1 && $bulan <= 12) ? $raw : null;
        }

        if (preg_match('/^\d{4}$/', $raw)) {
            return $raw;
        }

        if (preg_match('/^(\d{4})[\/\-](\d{1,2})$/', $raw, $m)) {
            $bulan = (int) $m[2];
            return ($bulan >= 1 && $bulan <= 12) ? sprintf('%04d%02d', (int) $m[1], $bulan) : null;
        }

        $normalized = preg_replace('/\s+/', ' ', strtolower($raw));
        $bulanMap = [];
        foreach (self::BULAN_INDO as $num => $nama) {
            $bulanMap[strtolower($nama)] = $num;
        }

        if (preg_match('/^([a-z]+)\s+(\d{4})$/i', $normalized, $m)) {
            $bulan = $bulanMap[$m[1]] ?? null;
            if ($bulan) {
                return sprintf('%04d%02d', (int) $m[2], $bulan);
            }
        }

        return null;
    }
}
