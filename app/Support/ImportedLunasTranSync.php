<?php

namespace App\Support;

use App\Models\scctbill;
use App\Models\sccttran;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ImportedLunasTranSync
{
    /**
     * Data Tagihan Lunas membaca sccttran, bukan scctbill.
     * Import excel lunas wajib punya baris FROM TELLER di sccttran.
     */
    public static function writeForBill(scctbill $bill, ?string $transno = null): void
    {
        if (blank($bill->AA) || blank($bill->CUSTID)) {
            return;
        }

        $exists = sccttran::query()
            ->where('BILLID', $bill->AA)
            ->where('CUSTID', $bill->CUSTID)
            ->exists();
        if ($exists) {
            return;
        }

        $nominal = (int) ($bill->BILLPAID ?: $bill->BILLAM);
        if ($nominal <= 0) {
            return;
        }

        $transno = $transno ?: self::nextTransno($bill->PAIDDT);

        $payload = [
            'CUSTID' => $bill->CUSTID,
            'METODE' => 'FROM TELLER',
            'TRXDATE' => $bill->PAIDDT ?: now(),
            'NOREFF' => 'TELLER',
            // Import lunas: Manual BCAS, DEBET=KREDIT supaya web tagihan tidak memotong saldo VA
            'FIDBANK' => '1140001',
            'DEBET' => $nominal,
            'KREDIT' => $nominal,
            'BILLID' => $bill->AA,
            'BILLTARGET' => $bill->BILLNM,
            'INSTALLMENT' => (int) ($bill->INSTALLMENT ?: 1),
            'TRANSNO' => $transno,
        ];

        sccttran::create($payload);

        if (blank($bill->TRANSNO)) {
            $bill->TRANSNO = $transno;
            $bill->save();
        }
    }

    /** Tagihan lunas import (NOREFF=TELLER) yang belum punya sccttran. */
    public static function syncMissing(): int
    {
        self::neutralizeImportedSaldoImpact();

        $bills = scctbill::query()
            ->whereIn('PAIDST', [1, '1'])
            ->whereRaw('UPPER(TRIM(COALESCE(NOREFF, ""))) = ?', ['TELLER'])
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('sccttran')
                    ->whereColumn('sccttran.BILLID', 'scctbill.AA')
                    ->whereColumn('sccttran.CUSTID', 'scctbill.CUSTID');
            })
            ->get();

        $count = 0;
        foreach ($bills as $bill) {
            try {
                self::writeForBill($bill);
                $count++;
            } catch (\Throwable $e) {
                Log::warning('Gagal sync sccttran tagihan lunas import', [
                    'aa' => $bill->AA,
                    'custid' => $bill->CUSTID,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $count;
    }

    /** Import lunas historis tidak boleh mengurangi saldo VA di web tagihan. */
    public static function neutralizeImportedSaldoImpact(): int
    {
        $fidUpdated = sccttran::query()
            ->whereRaw('UPPER(TRIM(COALESCE(NOREFF, ""))) = ?', ['TELLER'])
            ->whereRaw('UPPER(TRIM(COALESCE(METODE, ""))) = ?', ['FROM TELLER'])
            ->where(function ($q) {
                $q->whereNull('FIDBANK')
                    ->orWhere('FIDBANK', '')
                    ->orWhereNotIn('FIDBANK', ['1140000', '1140001', '1140002']);
            })
            ->update(['FIDBANK' => '1140001']);

        $importTeller = sccttran::query()
            ->whereRaw('UPPER(TRIM(COALESCE(NOREFF, ""))) = ?', ['TELLER'])
            ->whereRaw('UPPER(TRIM(COALESCE(METODE, ""))) = ?', ['FROM TELLER'])
            ->where('DEBET', '>', 0)
            ->where(function ($q) {
                $q->whereNull('KREDIT')
                    ->orWhere('KREDIT', 0)
                    ->orWhere('KREDIT', '0');
            })
            ->where(function ($q) {
                $q->whereNull('isreversal')
                    ->orWhere('isreversal', 0)
                    ->orWhere('isreversal', '0');
            });

        try {
            $kreditUpdated = $importTeller->update(['KREDIT' => DB::raw('DEBET')]);
        } catch (\Throwable $e) {
            if (stripos($e->getMessage(), 'isreversal') === false
                && stripos($e->getMessage(), 'Unknown column') === false) {
                throw $e;
            }

            $kreditUpdated = sccttran::query()
                ->whereRaw('UPPER(TRIM(COALESCE(NOREFF, ""))) = ?', ['TELLER'])
                ->whereRaw('UPPER(TRIM(COALESCE(METODE, ""))) = ?', ['FROM TELLER'])
                ->where('DEBET', '>', 0)
                ->where(function ($q) {
                    $q->whereNull('KREDIT')
                        ->orWhere('KREDIT', 0)
                        ->orWhere('KREDIT', '0');
                })
                ->update(['KREDIT' => DB::raw('DEBET')]);
        }

        return (int) $fidUpdated + (int) $kreditUpdated;
    }

    /** Contoh: BCAS26/102000003 (tahun 2 digit / day-of-year 3 digit + urutan 6 digit). */
    private static array $transnoSeq = [];

    private static function nextTransno(mixed $paidAt = null): string
    {
        try {
            $date = $paidAt ? Carbon::parse($paidAt) : Carbon::now();
        } catch (\Throwable $e) {
            $date = Carbon::now();
        }

        $prefix = 'BCAS' . $date->format('y') . '/' . str_pad((string) $date->dayOfYear, 3, '0', STR_PAD_LEFT);

        if (!isset(self::$transnoSeq[$prefix])) {
            $last = sccttran::query()
                ->where('TRANSNO', 'like', $prefix . '%')
                ->orderByDesc('TRANSNO')
                ->value('TRANSNO');

            $seq = 0;
            if ($last && preg_match('/(\d{6})$/', (string) $last, $m)) {
                $seq = (int) $m[1];
            }
            self::$transnoSeq[$prefix] = $seq;
        }

        self::$transnoSeq[$prefix]++;

        return $prefix . str_pad((string) self::$transnoSeq[$prefix], 6, '0', STR_PAD_LEFT);
    }
}
