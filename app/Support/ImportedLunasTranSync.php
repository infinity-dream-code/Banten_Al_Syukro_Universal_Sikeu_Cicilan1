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

        $transno = $transno ?: self::nextTransno();
        $payload = [
            'CUSTID' => $bill->CUSTID,
            'METODE' => 'FROM TELLER',
            'TRXDATE' => $bill->PAIDDT ?: now(),
            'NOREFF' => 'TELLER',
            // 1140000 = Manual Cash: tidak dihitung sebagai debet saldo VA
            'FIDBANK' => '1140000',
            'DEBET' => $nominal,
            'KREDIT' => 0,
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

    /** Import lunas historis tidak boleh mengurangi saldo VA. */
    public static function neutralizeImportedSaldoImpact(): int
    {
        return sccttran::query()
            ->whereRaw('UPPER(TRIM(COALESCE(NOREFF, ""))) = ?', ['TELLER'])
            ->whereRaw('UPPER(TRIM(COALESCE(METODE, ""))) = ?', ['FROM TELLER'])
            ->where(function ($q) {
                $q->whereNull('FIDBANK')
                    ->orWhere('FIDBANK', '')
                    ->orWhereNotIn('FIDBANK', ['1140000', '1140002']);
            })
            ->update(['FIDBANK' => '1140000']);
    }

    private static function nextTransno(): string
    {
        try {
            $table = DB::table('ref_invoicedate');
            $row = $table->where('idx_static', 1)->first();
            if (!$row) {
                $table->insert([
                    'last_date' => date('Y-m-d'),
                    'last_number' => 1,
                    'idx_static' => 1,
                ]);
                $lastNumber = 1;
            } else {
                $lastNumber = (int) ($row->last_number ?? 0) + 1;
                $update = ['last_number' => $lastNumber];
                if (!Carbon::parse($row->last_date)->isSameDay(Carbon::now())) {
                    $update['last_date'] = date('Y-m-d');
                    $lastNumber = 1;
                    $update['last_number'] = 1;
                }
                $table->where('idx_static', 1)->update($update);
            }

            return 'BMI' . date('ymd') . str_pad((string) $lastNumber, 6, '0', STR_PAD_LEFT);
        } catch (\Throwable $e) {
            return 'BMI' . date('ymdHis') . substr((string) mt_rand(1000, 9999), 0, 4);
        }
    }
}
