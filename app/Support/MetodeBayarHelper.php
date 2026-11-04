<?php

namespace App\Support;

use App\Models\scctbill;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

class MetodeBayarHelper
{
    public const ANDROID_FIDBANK = '6';
    public const SALDO_FIDBANK = '1140002';
    /** Transfer Bank Lain / Lain-lain (nama bank bebas disimpan di NOREFF). */
    public const LAIN_FIDBANK = '1140003';
    public const LAIN_BANK_VALUE = 'lain';

    /** NOREFF sistem — bukan nama bank bebas. */
    public static function reservedNoreffs(): array
    {
        return ['MOBILE', 'MNL', 'TELLER', 'REVERSAL', 'JURNAL SALDO'];
    }

    public static function isMobileNoreff(?string $noreff): bool
    {
        return strcasecmp(trim((string) $noreff), 'Mobile') === 0;
    }

    public static function isMnlNoreff(?string $noreff): bool
    {
        return strcasecmp(trim((string) $noreff), 'MNL') === 0;
    }

    public static function isCustomBankNoreff(?string $noreff): bool
    {
        $n = strtoupper(trim((string) $noreff));
        if ($n === '') {
            return false;
        }

        return !in_array($n, self::reservedNoreffs(), true);
    }

    /**
     * Bank form Manual Pembayaran → FIDBANK untuk BuilderPaymentCash.
     * "lain" disimpan sebagai 1140003; nama bebas di NOREFF.
     */
    public static function resolvePaymentFidBank(string $bank): string
    {
        $bank = trim($bank);
        if ($bank === '' || strcasecmp($bank, self::LAIN_BANK_VALUE) === 0) {
            return self::LAIN_FIDBANK;
        }

        return $bank;
    }

    public static function customBankFilterValue(string $name): string
    {
        return 'custom:' . trim($name);
    }

    public static function parseCustomBankFilter(?string $bank): ?string
    {
        $bank = trim((string) $bank);
        if ($bank === '' || strcasecmp($bank, self::LAIN_BANK_VALUE) === 0) {
            return null;
        }
        if (str_starts_with($bank, 'custom:')) {
            $name = trim(substr($bank, 7));
            return $name !== '' ? $name : null;
        }

        return null;
    }

    /**
     * Aturan tampilan metode (sumber utama: scctbill):
     * - scctbill.NOREFF = Mobile → ANDROID
     * - 1140003 + NOREFF bebas (BSI, dll) → nama bebas
     * - selain itu pakai scctbill.FIDBANK
     */
    public static function resolveDisplayFidBank(?string $fidBank, ?string $billNoreff = null): string
    {
        if (self::isMobileNoreff($billNoreff)) {
            return self::ANDROID_FIDBANK;
        }

        if ((string) $fidBank === self::LAIN_FIDBANK && self::isCustomBankNoreff($billNoreff)) {
            return trim((string) $billNoreff);
        }

        return (string) ($fidBank ?? '');
    }

    public static function resolveDisplayLabel(?string $fidBank, ?string $billNoreff = null, ?array $metodeBayarMap = null): string
    {
        if (self::isMobileNoreff($billNoreff)) {
            return 'ANDROID';
        }

        if ((string) $fidBank === self::LAIN_FIDBANK && self::isCustomBankNoreff($billNoreff)) {
            return trim((string) $billNoreff);
        }

        $map = $metodeBayarMap ?? (new scctbill())->metodeBayar;
        $fid = (string) ($fidBank ?? '');

        return $map[$fid] ?? ($fid !== '' ? $fid : '-');
    }

    /**
     * Filter Bank = ANDROID: hanya scctbill.NOREFF Mobile ATAU FIDBANK 6.
     *
     * @param  Builder|QueryBuilder  $query
     * @return Builder|QueryBuilder
     */
    public static function applyAndroidBankFilter($query, string $tranAlias = 'sccttran', string $billAlias = 'scctbill')
    {
        return $query->where(function ($q) use ($billAlias) {
            $q->where("{$billAlias}.FIDBANK", self::ANDROID_FIDBANK)
                ->orWhereRaw('UPPER(TRIM(COALESCE(' . $billAlias . '.NOREFF, ""))) = ?', ['MOBILE']);
        });
    }

    /**
     * Filter Bank selain ANDROID: jangan ikutkan baris scctbill.NOREFF Mobile.
     *
     * @param  Builder|QueryBuilder  $query
     * @return Builder|QueryBuilder
     */
    public static function excludeMobileNoreff($query, string $tranAlias = 'sccttran', string $billAlias = 'scctbill')
    {
        return $query->whereRaw(
            'UPPER(TRIM(COALESCE(' . $billAlias . '.NOREFF, ""))) <> ?',
            ['MOBILE']
        );
    }

    /**
     * Filter berdasarkan pilihan bank dropdown.
     * - 6 / ANDROID
     * - lain → 1140003 + NOREFF nama bebas
     * - custom:BSI → 1140003 + NOREFF = BSI
     * - 1140003 → Transfer Bank Lain generik (tanpa nama bebas)
     * - kode lain → FIDBANK biasa
     *
     * @param  Builder|QueryBuilder  $query
     * @return Builder|QueryBuilder
     */
    public static function applyBankFilter($query, ?string $bank, string $fidColumn, string $tranAlias = 'sccttran', string $billAlias = 'scctbill')
    {
        $bank = trim((string) $bank);
        if ($bank === '' || strtolower($bank) === 'all') {
            return $query;
        }

        if ($bank === self::ANDROID_FIDBANK) {
            return self::applyAndroidBankFilter($query, $tranAlias, $billAlias);
        }

        $customName = self::parseCustomBankFilter($bank);
        if ($customName !== null) {
            $query->where("{$billAlias}.FIDBANK", self::LAIN_FIDBANK)
                ->whereRaw('UPPER(TRIM(COALESCE(' . $billAlias . '.NOREFF, ""))) = ?', [strtoupper($customName)]);

            return self::excludeMobileNoreff($query, $tranAlias, $billAlias);
        }

        if (strcasecmp($bank, self::LAIN_BANK_VALUE) === 0) {
            $reserved = self::reservedNoreffs();
            $placeholders = implode(', ', array_fill(0, count($reserved), '?'));
            $query->where("{$billAlias}.FIDBANK", self::LAIN_FIDBANK)
                ->whereRaw(
                    'UPPER(TRIM(COALESCE(' . $billAlias . '.NOREFF, ""))) NOT IN (' . $placeholders . ')',
                    $reserved
                )
                ->whereRaw('TRIM(COALESCE(' . $billAlias . '.NOREFF, "")) <> ""');

            return self::excludeMobileNoreff($query, $tranAlias, $billAlias);
        }

        if ($bank === self::LAIN_FIDBANK) {
            $reserved = self::reservedNoreffs();
            $placeholders = implode(', ', array_fill(0, count($reserved), '?'));
            $query->where($fidColumn, self::LAIN_FIDBANK)
                ->where(function ($q) use ($billAlias, $placeholders, $reserved) {
                    $q->whereRaw('TRIM(COALESCE(' . $billAlias . '.NOREFF, "")) = ""')
                        ->orWhereRaw(
                            'UPPER(TRIM(COALESCE(' . $billAlias . '.NOREFF, ""))) IN (' . $placeholders . ')',
                            $reserved
                        );
                });

            return self::excludeMobileNoreff($query, $tranAlias, $billAlias);
        }

        $query->where($fidColumn, $bank);

        return self::excludeMobileNoreff($query, $tranAlias, $billAlias);
    }

    /** Opsi filter bank + nama bank bebas yang sudah pernah dipakai. */
    public static function buildBankFilterOptions(array $base = []): array
    {
        $options = $base;
        $options[self::LAIN_BANK_VALUE] = 'Lain-lain';

        try {
            $customNames = scctbill::query()
                ->where('FIDBANK', self::LAIN_FIDBANK)
                ->whereRaw('TRIM(COALESCE(NOREFF, "")) <> ""')
                ->whereRaw(
                    'UPPER(TRIM(COALESCE(NOREFF, ""))) NOT IN (' . implode(', ', array_fill(0, count(self::reservedNoreffs()), '?')) . ')',
                    self::reservedNoreffs()
                )
                ->distinct()
                ->orderBy('NOREFF')
                ->pluck('NOREFF');

            foreach ($customNames as $name) {
                $label = trim((string) $name);
                if ($label === '') {
                    continue;
                }
                $options[self::customBankFilterValue($label)] = $label;
            }
        } catch (\Throwable $e) {
            // DB belum siap / kolom beda — biarkan opsi dasar saja
        }

        return $options;
    }
}
