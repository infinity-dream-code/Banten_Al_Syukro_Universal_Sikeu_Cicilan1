<?php

namespace App\Http\Controllers\Admin\Keuangan\TagihanSiswa;

use App\Imports\Keuangan\TagihanSiswa\ImportTagihanLunasExcel;
use App\Models\scctbill;
use App\Models\scctcust;
use App\Models\ValidationMessage;
use App\Support\ImportedLunasTranSync;
use App\Support\PeriodeHelper;
use App\Support\SchoolScope;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\HeadingRowImport;
use Maatwebsite\Excel\Validators\ValidationException;

class UploadTagihanLunasExcelController extends UploadTagihanExcelController
{
    public string $dataTitle = 'Buat Tagihan Lunas Excel';
    public string $cacheKey = 'import_tagihan_lunas_excel';

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

        return view('admin.keuangan.tagihan_siswa.upload_tagihan_lunas_excel.index', $data);
    }

    public function getColumn()
    {
        return [
            ['data' => null, 'name' => 'no', 'className' => 'text-center', 'columnType' => 'row'],
            ['data' => 'nis', 'name' => 'NIS', 'searchable' => true, 'orderable' => true],
            ['data' => 'name', 'name' => 'Nama Siswa', 'searchable' => true, 'orderable' => true],
            ['data' => 'nama_tagihan', 'name' => 'Nama Tagihan', 'searchable' => true, 'orderable' => true],
            ['data' => 'periode', 'name' => 'Periode', 'searchable' => true, 'orderable' => true],
            ['data' => 'nominal', 'name' => 'Nominal', 'searchable' => true, 'orderable' => true, 'columnType' => 'currency'],
            ['data' => 'tanggal_bayar', 'name' => 'Tanggal Bayar', 'searchable' => true, 'orderable' => true],
            ['data' => 'bank', 'name' => 'Bank', 'searchable' => true, 'orderable' => true],
            ['data' => 'status', 'name' => 'Status', 'searchable' => true, 'orderable' => true, 'columnType' => 'importstatus'],
            ['data' => 'keterangan', 'name' => 'Keterangan', 'searchable' => true, 'orderable' => true],
        ];
    }

    public function getData(Request $request)
    {
        $draw = $request->get('draw');
        $cachedData = Cache::get($this->cacheKey, []);
        if (!is_array($cachedData)) {
            $cachedData = [];
        }

        $metodeBayar = (new scctbill())->metodeBayar;
        $records = collect($cachedData)->map(function ($item) use ($metodeBayar) {
            $fid = (string) ($item['fidbank'] ?? '');
            $bankLabel = $metodeBayar[$fid] ?? ($item['bank'] ?? '-');

            return [
                'nis' => $item['nis'] ?? null,
                'name' => $item['nama'] ?? null,
                'nama_tagihan' => $item['billnm'] ?? $item['nama_tagihan'] ?? '-',
                'periode' => PeriodeHelper::label($item['billac'] ?? $item['periode'] ?? ''),
                'nominal' => $item['nominal'] ?? null,
                'tanggal_bayar' => $item['tanggal_bayar'] ?? '-',
                'bank' => $bankLabel,
                'status' => $item['status'] ?? 0,
                'keterangan' => $item['keterangan'] ?? null,
            ];
        });

        return response()->json([
            'draw' => intval($draw),
            'recordsTotal' => count($cachedData),
            'recordsFiltered' => count($cachedData),
            'data' => $records,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate(
            [
                'fileImport' => [
                    'required',
                    'file',
                    'mimes:xls,xlsx',
                    'mimetypes:application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/octet-stream',
                    'max:1024',
                ],
            ],
            ValidationMessage::messages(),
            ValidationMessage::attributes()
        );

        $file = $request->file('fileImport');

        try {
            $headingsData = (new HeadingRowImport)->toArray($file);
            $requiredColumns = ['nis', 'nama_tagihan', 'periode', 'nominal', 'tanggal_bayar', 'bank'];
            if (empty($headingsData) || !isset($headingsData[0][0])) {
                throw new \Exception('Tidak dapat membaca judul kolom dari file. Pastikan file memiliki header yang sesuai.');
            }

            $headings = array_map(static function ($heading) {
                $normalized = strtolower(trim((string) $heading));
                $normalized = str_replace(['*', '-'], ['', '_'], $normalized);

                return preg_replace('/\s+/', '_', $normalized);
            }, $headingsData[0][0]);

            $aliases = [
                'nis' => ['nis'],
                'nama_tagihan' => ['nama_tagihan', 'namatagihan', 'tagihan'],
                'periode' => ['periode'],
                'nominal' => ['nominal', 'jumlah'],
                'tanggal_bayar' => ['tanggal_bayar', 'tgl_bayar', 'tanggalbayar'],
                'bank' => ['bank'],
            ];

            $missingColumns = [];
            foreach ($requiredColumns as $column) {
                $found = false;
                foreach ($aliases[$column] as $alias) {
                    if (in_array($alias, $headings, true)) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $missingColumns[] = strtoupper(str_replace('_', ' ', $column));
                }
            }

            if (!empty($missingColumns)) {
                throw new \Exception(
                    'Kolom ' . implode(', ', $missingColumns) . ' tidak ditemukan.<br><hr>' .
                    'Pastikan kolom berikut ada: <strong>NIS, NAMA TAGIHAN, PERIODE, NOMINAL, TANGGAL BAYAR, BANK</strong>.'
                );
            }

            Excel::import(new ImportTagihanLunasExcel($this->cacheKey), $file);

            $data = Cache::get($this->cacheKey, []);
            if (empty($data)) {
                throw new \Exception('File berhasil dibaca, tetapi tidak ada baris data yang dapat diproses.');
            }

            $invalidCount = collect($data)->where('status', 0)->count();
            $message = 'Sukses, data tagihan lunas telah diimport, silahkan periksa kembali';
            if ($invalidCount > 0) {
                $message .= " ({$invalidCount} baris perlu diperbaiki, lihat kolom Keterangan)";
            }

            Log::info('Upload tagihan lunas excel berhasil', [
                'user_id' => auth()->id(),
                'file_name' => $file->getClientOriginalName(),
                'row_count' => count($data),
            ]);

            return response()->json(['message' => $message, 'data' => $data], 200);
        } catch (ValidationException $e) {
            $errorMessages = $e->errors();
            $errorMessage = $errorMessages['error'][0] ?? 'Terjadi kesalahan saat melakukan import data.';

            return response()->json(['message' => $errorMessage, 'error' => $errorMessages], 422);
        } catch (\Throwable $e) {
            Log::error('Upload tagihan lunas excel gagal', [
                'user_id' => auth()->id(),
                'file_name' => $file?->getClientOriginalName(),
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);

            return response()->json([
                'message' => "Gagal!<br> tidak dapat melakukan {$this->dataTitle}.<hr> {$e->getMessage()}",
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    public function validateExcel(Request $request)
    {
        $data = Cache::get($this->cacheKey);
        if (empty($data)) {
            return response()->json(['message' => 'Silahkan import data tagihan terlebih dahulu'], 422);
        }

        $validRows = collect($data)->where('status', 1)->values();
        if ($validRows->isEmpty()) {
            return response()->json(['message' => 'Tidak ada baris valid yang dapat disimpan. Perbaiki kolom Keterangan.'], 422);
        }

        try {
            DB::beginTransaction();
            DB::connection('DATA_MYSQL')->beginTransaction();
            $insertedCount = 0;

            foreach ($validRows as $item) {
                $siswa = scctcust::where('NOCUST', $item['nis']);
                SchoolScope::apply($siswa, 'scctcust', $this->sekolah);
                $siswa = $siswa->first();
                if (!$siswa) {
                    DB::connection('DATA_MYSQL')->rollBack();
                    DB::rollBack();
                    return response()->json(['message' => "siswa dengan nis: {$item['nis']} tidak ditemukan!"], 422);
                }
                if ((int) ($siswa->STCUST ?? 0) === 0) {
                    continue;
                }

                $tanggalBayar = Carbon::createFromFormat('Y-m-d', $item['tanggal_bayar'])
                    ->setTimeFrom(Carbon::now())
                    ->toDateTimeString();
                $nominal = (int) $item['nominal'];
                $bta = (string) $item['billac'];

                $tagihanSiswaTerbaru = scctbill::where('CUSTID', $siswa->CUSTID)
                    ->orderBy('FUrutan', 'DESC')
                    ->first();

                $urut = $tagihanSiswaTerbaru ? $tagihanSiswaTerbaru['FUrutan'] + 1 : 1;
                $billCD = date('Y') . '/i' . date('m') . '-' . ($urut + 1);

                $aa = DB::connection('DATA_MYSQL')->table('scctbill')->insertGetId([
                    'CUSTID' => $siswa->CUSTID,
                    'BILLAC' => $bta,
                    'BILLNM' => $item['billnm'] ?? $item['nama_tagihan'],
                    'BILLAM' => $nominal,
                    'BILLPAID' => $nominal,
                    'PAYMENTLEFT' => 0,
                    'PAIDST' => 1,
                    'PAIDDT' => $tanggalBayar,
                    'PAIDDT_ACTUAL' => now(),
                    'FIDBANK' => '1140001',
                    'NOREFF' => 'TELLER',
                    'FUrutan' => $urut,
                    'FTGLTagihan' => now(),
                    'FSTSBolehBayar' => 1,
                    'BTA' => $bta,
                    'BILLCD' => $billCD,
                    'INSTALLMENT' => 1,
                    'isINSTALLABLE' => (int) ($item['is_installable'] ?? 0),
                ], 'AA');

                $bill = scctbill::find($aa);
                if ($bill) {
                    ImportedLunasTranSync::writeForBill($bill);
                }
                $insertedCount++;
            }

            Cache::forget($this->cacheKey);
            DB::connection('DATA_MYSQL')->commit();
            DB::commit();

            Log::info('Simpan tagihan lunas excel berhasil', [
                'user_id' => auth()->id(),
                'row_count' => $insertedCount,
            ]);

            return response()->json([
                'message' => "Data tagihan lunas disimpan! Berhasil dibuat untuk {$insertedCount} baris.",
            ], 200);
        } catch (\Throwable $e) {
            DB::connection('DATA_MYSQL')->rollBack();
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
