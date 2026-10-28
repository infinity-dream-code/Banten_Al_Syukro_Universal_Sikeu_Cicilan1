<?php

namespace App\Support;

use App\Models\scctbill;
use App\Models\sccttran;
use Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TagihanPaymentReversal
{
    private const SALDO_FIDBANK = '1140002';

    /** Batalkan pembayaran terakhir. Cash/bank lain tidak boleh masuk saldo. */
    public function reverseLastPayment(scctbill $tagihan, Request $request): void
    {
        if (!$this->hasBillPayments($tagihan)) {
            return;
        }

        $fidBank = preg_replace('/\D/', '', (string) ($tagihan->FIDBANK ?? ''));

        if ($fidBank === self::SALDO_FIDBANK) {
            $this->clearLastPaymentKredit($tagihan);
            $this->callCancelPaymentSaldo(
                (string) $tagihan->CUSTID,
                (string) $tagihan->AA,
                (string) ($tagihan->BILLCD ?? ''),
                $this->resolveCyberKeyUserId(),
                Str::limit((string) ($request->ip() ?? ''), 250, '')
            );
        } else {
            $this->reverseTellerWithoutSaldo($tagihan);
        }

        $this->markPaymentReversed($tagihan);
        $tagihan->refresh();
    }

    /** Hapus tagihan: batalkan semua pembayaran dari yang terakhir. */
    public function reverseAllPayments(scctbill $tagihan, Request $request): void
    {
        $guard = 0;

        while ($this->hasBillPayments($tagihan) && $guard < 50) {
            $this->reverseLastPayment($tagihan, $request);
            $tagihan->refresh();
            $guard++;
        }
    }

    public function deleteUnpaidTagihan(scctbill $tagihan, Request $request): void
    {
        if ($this->hasBillPayments($tagihan)) {
            $this->reverseAllPayments($tagihan, $request);
            $tagihan->refresh();
        }

        $tagihan->update([
            'FSTSBolehBayar' => 0,
        ]);
    }

    public function hasBillPayments(scctbill $tagihan): bool
    {
        if ((int) ($tagihan->BILLPAID ?? 0) > 0) {
            return true;
        }

        return $this->paymentTransactionQuery($tagihan)->exists();
    }

    private function reverseTellerWithoutSaldo(scctbill $tagihan): void
    {
        $billAm = (int) ($tagihan->BILLAM ?? 0);

        $this->clearLastPaymentKredit($tagihan);

        $tagihan->update([
            'PAIDST' => 0,
            'PAIDDT' => null,
            'PAIDDT_ACTUAL' => null,
            'BILLPAID' => 0,
            'PAYMENTLEFT' => $billAm,
            'INSTALLMENT' => 0,
        ]);
    }

    private function paymentTransactionQuery(scctbill $tagihan)
    {
        return sccttran::query()
            ->where('BILLID', $tagihan->AA)
            ->where('CUSTID', $tagihan->CUSTID)
            ->whereRaw('UPPER(TRIM(COALESCE(METODE, ""))) = ?', ['FROM TELLER'])
            ->where('DEBET', '>', 0)
            ->where(function ($q) {
                $q->whereNull('isreversal')
                    ->orWhere('isreversal', 0)
                    ->orWhere('isreversal', '0');
            });
    }

    private function clearLastPaymentKredit(scctbill $tagihan): void
    {
        $lastInstallment = (int) $this->paymentTransactionQuery($tagihan)->max('INSTALLMENT');

        if ($lastInstallment <= 0) {
            return;
        }

        sccttran::query()
            ->where('BILLID', $tagihan->AA)
            ->where('CUSTID', $tagihan->CUSTID)
            ->where('INSTALLMENT', $lastInstallment)
            ->whereRaw('UPPER(TRIM(COALESCE(METODE, ""))) = ?', ['FROM TELLER'])
            ->where('KREDIT', '>', 0)
            ->update(['KREDIT' => 0]);
    }

    private function markPaymentReversed(scctbill $tagihan): void
    {
        try {
            sccttran::query()
                ->where('BILLID', $tagihan->AA)
                ->where('CUSTID', $tagihan->CUSTID)
                ->where(function ($q) {
                    $q->whereRaw('UPPER(TRIM(COALESCE(METODE, ""))) = ?', ['FROM TELLER'])
                        ->orWhereRaw('UPPER(TRIM(COALESCE(METODE, ""))) IN (?, ?)', ['REVERSAL', 'JURNAL SALDO']);
                })
                ->update(['isreversal' => 1]);
        } catch (\Throwable $e) {
            if (stripos($e->getMessage(), 'isreversal') === false
                && stripos($e->getMessage(), 'Unknown column') === false) {
                throw $e;
            }
        }
    }

    private function callCancelPaymentSaldo(string $custId, string $aa, string $billCd, string $userId, string $hostname): void
    {
        Log::info('tagihan-payment.cancel.call_procedure', [
            'procedure' => 'CancelPaymentSaldo',
            'custid' => $custId,
            'aa' => $aa,
            'billcd' => $billCd,
            'users' => $userId,
            'hostname' => $hostname,
        ]);

        $pdo = DB::connection('DATA_MYSQL')->getPdo();
        $stmt = $pdo->prepare('CALL CancelPaymentSaldo(?, ?, ?, ?, ?)');
        $stmt->execute([$custId, $aa, $billCd, $userId, $hostname]);

        do {
            $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } while ($stmt->nextRowset());
    }

    private function resolveCyberKeyUserId(): string
    {
        $user = Auth::user();

        if ($user === null) {
            return '';
        }

        return (string) ($user->urut ?? Auth::id() ?? '');
    }
}
