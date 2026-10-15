<?php

namespace App\Http\Controllers\Admin\Keuangan\TagihanSiswa;

use App\Models\mst_tagihan;
use App\Models\scctbill;
use App\Models\scctcust;
use App\Models\ValidationMessage;
use App\Support\SchoolScope;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class UploadTagihanLunasExcelController extends UploadTagihanExcelController
{
    public string $dataTitle = 'Buat Tagihan Lunas Excel';
    public string $cacheKey = 'import_tagihan_lunas_excel';

    public const BANK_CODES = ['1140000', '1140001', '1140002', '1140003'];

    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            if (Auth::check()) {
                $this->sekolah = Auth::user()->sekolah;
                $this->cacheKey = 'import_tagihan_lunas_excel_' . Auth::id();
            }

            return $next($request);
        });
    }

    public function index()
    {
        $data['title'] = $this->title;
        $data['mainTitle'] = $this->mainTitle;
        $data['dataTitle'] = $this->dataTitle;
        $data['columnsUrl'] = route('admin.keuangan.tagihan-siswa.upload-tagihan-lunas-excel.get-column');
        $data['datasUrl'] = route('admin.keuangan.tagihan-siswa.upload-tagihan-lunas-excel.get-data');

        $currentYear = (int) date('Y');
        $data['periode_tahun_list'] = range($currentYear - 2, $currentYear + 5);
        $data['periode_tahun_default'] = $currentYear;
        $data['periode_bulan_default'] = (int) date('m');
        $data['tagihan'] = mst_tagihan::orderBy('urut', 'asc')->get();

        $metodeBayar = (new scctbill())->metodeBayar;
        $data['bankList'] = collect(self::BANK_CODES)
            ->mapWithKeys(fn ($code) => [$code => $metodeBayar[$code] ?? $code])
            ->all();

        return view('admin.keuangan.tagihan_siswa.upload_tagihan_lunas_excel.index', $data);
    }

    public function validateExcel(Request $request)
    {
        $request->validate([
            'tagihan' => ['required'],
            'periode_tahun' => ['required', 'integer', 'digits:4', 'min:2000', 'max:2099'],
            'periode_bulan' => ['nullable', 'integer', 'min:1', 'max:12'],
            'tanggal_bayar' => ['required', 'date_format:Y-m-d'],
            'bank' => ['required', Rule::in(self::BANK_CODES)],
        ], ValidationMessage::messages(), ValidationMessage::attributes());

        $data = Cache::get($this->cacheKey);
        if (empty($data)) return response()->json(['message' => 'Silahkan import data tagihan terlebih dahulu'], 422);

        $bta = $request->filled('periode_bulan')
            ? sprintf('%04d%02d', (int) $request->periode_tahun, (int) $request->periode_bulan)
            : sprintf('%04d', (int) $request->periode_tahun);

        $tagihan = mst_tagihan::where('urut', $request->tagihan)->first();
        if (!$tagihan) return response()->json(['message' => 'Tagihan tidak ditemukan, silahkan muat ulang halaman!'], 422);

        $tanggalBayar = Carbon::createFromFormat('Y-m-d', $request->tanggal_bayar)
            ->setTimeFrom(Carbon::now())
            ->toDateTimeString();
        $bank = (string) $request->bank;

        try {
            DB::beginTransaction();
            $skippedInactive = [];
            $insertedCount = 0;
            foreach ($data as $item) {
                if ($item['status'] != 1) continue;
                $siswa = scctcust::where('NOCUST', $item['nis']);
                SchoolScope::apply($siswa, 'scctcust', $this->sekolah);
                $siswa = $siswa->first();
                if (!$siswa) {
                    DB::rollBack();
                    return response()->json(['message' => "siswa dengan nis: {$item['nis']} tidak ditemukan!"], 422);
                }
                if ((int) ($siswa->STCUST ?? 0) === 0) {
                    $skippedInactive[] = trim(($item['nis'] ?? '-') . ' - ' . ($siswa->NMCUST ?? 'Tanpa Nama'));
                    continue;
                }

                $tagihanSiswaTerbaru = scctbill::where('CUSTID', $siswa->CUSTID)
                    ->orderBy('FUrutan', 'DESC')
                    ->first();

                $urut = $tagihanSiswaTerbaru ? $tagihanSiswaTerbaru['FUrutan'] + 1 : 1;
                $billCD = date('Y') . '/i' . date('m') . '-' . ($urut + 1);
                $nominal = (int) $item['nominal'];

                scctbill::create([
                    'CUSTID' => $siswa->CUSTID,
                    'BILLAC' => $bta,
                    'BILLNM' => $tagihan->tagihan,
                    'BILLAM' => $nominal,
                    'BILLPAID' => $nominal,
                    'PAYMENTLEFT' => 0,
                    'PAIDST' => 1,
                    'PAIDDT' => $tanggalBayar,
                    'PAIDDT_ACTUAL' => now(),
                    'FIDBANK' => $bank,
                    'NOREFF' => 'TELLER',
                    'FUrutan' => $urut,
                    'FTGLTagihan' => now(),
                    'FSTSBolehBayar' => 1,
                    'BTA' => $bta,
                    'BILLCD' => $billCD,
                    'INSTALLMENT' => 1,
                    'isINSTALLABLE' => (int) ($tagihan->isINSTALLMENT ?? 0),
                ]);
                $insertedCount++;
            }

            Cache::forget($this->cacheKey);

            DB::commit();

            Log::info('Simpan tagihan lunas excel berhasil', [
                'user_id' => auth()->id(),
                'tagihan' => $tagihan->tagihan,
                'bta' => $bta,
                'bank' => $bank,
                'tanggal_bayar' => $tanggalBayar,
                'row_count' => $insertedCount,
            ]);

            $message = "Data tagihan lunas disimpan! Berhasil dibuat untuk {$insertedCount} siswa.";
            if (!empty($skippedInactive)) {
                $message .= '<hr>Tagihan tidak dibuat untuk siswa nonaktif (STCUST=0): ' . count($skippedInactive) . ' siswa.<br>' .
                    implode('<br>', $skippedInactive);
            }
            return response()->json(['message' => $message], 200);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Simpan tagihan lunas excel gagal', [
                'user_id' => auth()->id(),
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);

            return response()->json([
                'message' => 'Terjadi kesalahan saat menyimpan data.<hr>' . $e->getMessage(),
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    public function clear()
    {
        Cache::forget($this->cacheKey);

        return response()->json(['message' => 'Data import telah dibersihkan']);
    }
}
