@extends('layouts.export.kop_file')
@section('title', 'Bukti Pembayaran')
@section('hide_default_footer', true)
@section('content')
    @php
        use App\Support\PeriodeHelper;

        $nis = $siswa->NOCUST ?? null;
        $hasNis = $nis && $nis !== '-';
        $ortu = $siswa->GENUS ?? '-';
        $total = collect($tagihans ?? [])->sum(function ($t) {
            return (int) ($t->NOMINAL_BAYAR ?? $t->BILLPAID ?? $t->BILLAM ?? 0);
        });
        $tanggal = collect($tagihans ?? [])->pluck('PAIDDT')->filter()->first() ?? now();
        $nomor = collect($tagihans ?? [])->pluck('TRANSNO')->filter()->first()
            ?? collect($tagihans ?? [])->pluck('BILLCD')->filter()->first()
            ?? '-';

        $terbilangPdf = function ($nilai) use (&$terbilangPdf) {
            $nilai = (int) abs($nilai);
            $satuan = ['', 'Satu', 'Dua', 'Tiga', 'Empat', 'Lima', 'Enam', 'Tujuh', 'Delapan', 'Sembilan', 'Sepuluh', 'Sebelas'];
            if ($nilai < 12) return $satuan[$nilai];
            if ($nilai < 20) return $satuan[$nilai - 10] . ' Belas';
            if ($nilai < 100) return $satuan[intval($nilai / 10)] . ' Puluh' . ($nilai % 10 ? ' ' . $terbilangPdf($nilai % 10) : '');
            if ($nilai < 200) return 'Seratus' . ($nilai % 100 ? ' ' . $terbilangPdf($nilai % 100) : '');
            if ($nilai < 1000) return $satuan[intval($nilai / 100)] . ' Ratus' . ($nilai % 100 ? ' ' . $terbilangPdf($nilai % 100) : '');
            if ($nilai < 2000) return 'Seribu' . ($nilai % 1000 ? ' ' . $terbilangPdf($nilai % 1000) : '');
            if ($nilai < 1000000) return $terbilangPdf(intval($nilai / 1000)) . ' Ribu' . ($nilai % 1000 ? ' ' . $terbilangPdf($nilai % 1000) : '');
            if ($nilai < 1000000000) return $terbilangPdf(intval($nilai / 1000000)) . ' Juta' . ($nilai % 1000000 ? ' ' . $terbilangPdf($nilai % 1000000) : '');
            return (string) $nilai;
        };
    @endphp
    <table width="100%">
        <tr>
            <td colspan="4" align="center">
                <h4 style="text-decoration: underline; margin: 8px 0;">BUKTI PEMBAYARAN</h4>
            </td>
        </tr>
    </table>
    <table width="100%" class="main-table">
        <tr>
            <td class="border-right-0">Telah Diterima Dari</td>
            <td class="border-left-0">: <strong>{{ $siswa->NMCUST ?? '-' }}</strong></td>
            <td class="border-right-0">Nomor</td>
            <td class="border-left-0">: <strong>{{ $nomor }}</strong></td>
        </tr>
        <tr>
            <td class="border-right-0">Kelas</td>
            <td class="border-left-0">: <strong>{{ trim(($siswa->DESC02 ?? '') . ' ' . ($siswa->DESC03 ?? '')) }}</strong></td>
            <td class="border-right-0">Tanggal</td>
            <td class="border-left-0">: <strong>{{ \Carbon\Carbon::parse($tanggal)->format('d M Y') }}</strong></td>
        </tr>
        <tr>
            <td class="border-right-0">Unit</td>
            <td class="border-left-0">: <strong>{{ $siswa->CODE02 ?? '-' }}</strong></td>
            <td class="border-right-0"></td>
            <td class="border-left-0"></td>
        </tr>
    </table>
    <table width="100%" class="table-border main-table" style="margin-top: 12px; border-left: 0; border-right: 0;">
        <thead>
        <tr>
            <th align="left">Faktur No.</th>
            <th align="left">Untuk Pembayaran</th>
            <th align="right">Discount</th>
            <th align="right">Nilai</th>
        </tr>
        </thead>
        <tbody>
        @foreach($tagihans as $tagihan)
            @php
                $nilai = (int) ($tagihan->NOMINAL_BAYAR ?? $tagihan->BILLPAID ?? $tagihan->BILLAM ?? 0);
                $rawPeriode = trim((string) ($tagihan->BILLAC ?? $tagihan->BTA ?? ''));
                $periode = PeriodeHelper::label($rawPeriode);
                $isMonthly = (bool) preg_match('/^\d{6}$/', $rawPeriode)
                    && isset(PeriodeHelper::BULAN_INDO[(int) substr($rawPeriode, 4, 2)]);
                $nama = trim((string) ($tagihan->BILLNM ?? '-'));
                $desc = $nama;
                if ($isMonthly && $periode !== '' && $periode !== '-') {
                    $desc = $nama . (stripos($nama, 'bulan') !== false ? ' ' : ' Bulan ') . $periode;
                }
            @endphp
            <tr>
                <td>{{ str_pad(preg_replace('/\D/', '', (string) ($tagihan->AA ?? $tagihan->BILLCD ?? '')), 8, '0', STR_PAD_LEFT) }}</td>
                <td>{{ $desc }}</td>
                <td align="right">0.00</td>
                <td align="right">{{ number_format($nilai, 2, '.', ',') }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    <table width="100%" class="main-table" style="margin-top: 12px;">
        <tr>
            <td width="55%" valign="top">
                <em>{{ $total > 0 ? $terbilangPdf($total) . ' Rupiah' : 'Nol Rupiah' }}</em>
                <br>
                @php
                    $ttdPath = public_path(config('app.ttd_kuitansi', 'ttd.png'));
                    $ttdMime = strtolower(pathinfo($ttdPath, PATHINFO_EXTENSION)) === 'png' ? 'image/png' : 'image/jpeg';
                @endphp
                @if(is_file($ttdPath))
                    <img src="data:{{ $ttdMime }};base64,{{ base64_encode(file_get_contents($ttdPath)) }}"
                         alt="ttd" style="max-height: 70px; margin: 8px 0;">
                    <br>
                @else
                    <br><br><br>
                @endif
                <u>{{ config('app.nama_ttd_kuitansi', 'Cut Tia Andani, S.Ak.') }}</u>
            </td>
            <td width="45%" valign="top">
                <table width="100%">
                    <tr>
                        <td>Sub Total</td>
                        <td align="right">Rp {{ number_format($total, 2, '.', ',') }}</td>
                    </tr>
                    <tr>
                        <td>Biaya Lainnya</td>
                        <td align="right">0.00</td>
                    </tr>
                    <tr>
                        <td><strong>Total</strong></td>
                        <td align="right"><strong>{{ number_format($total, 2, '.', ',') }}</strong></td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
@endsection
