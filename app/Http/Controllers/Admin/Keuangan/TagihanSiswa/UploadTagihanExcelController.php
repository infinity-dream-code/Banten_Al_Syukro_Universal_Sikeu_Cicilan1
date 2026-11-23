<?php

namespace App\Http\Controllers\Admin\Keuangan\TagihanSiswa;

use App\Http\Controllers\Controller;
use App\Imports\Keuangan\TagihanSiswa\ImportTagihanExcel;
use App\Models\mst_tagihan;
use App\Models\scctbill;
use App\Models\scctcust;
use App\Models\ValidationMessage;
use App\Support\SchoolScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\HeadingRowImport;
use Maatwebsite\Excel\Validators\ValidationException;

class UploadTagihanExcelController extends Controller
{
    public string $title = 'Keuangan';
    public string $mainTitle = 'Tagihan Siswa';
    public string $dataTitle = 'Buat Tagihan Excel';
    public string $cacheKey = 'import_tagihan_excel';

    public ?string $sekolah = null;

    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            if (Auth::check()) {
                $this->sekolah = Auth::user()->sekolah;
                $this->cacheKey = 'import_tagihan_excel_' . Auth::id();
            }

            return $next($request);
        });
    }

    public function index()
    {
        Cache::forget('import_tagihan_excel');
        $data['title'] = $this->title;
        $data['mainTitle'] = $this->mainTitle;
        $data['dataTitle'] = $this->dataTitle;
        $data['columnsUrl'] = route('admin.keuangan.tagihan-siswa.upload-tagihan-excel.get-column');
        $data['datasUrl'] = route('admin.keuangan.tagihan-siswa.upload-tagihan-excel.get-data');

        $currentYear = (int) date('Y');
        $data['periode_tahun_list'] = range($currentYear - 2, $currentYear + 5);
        $data['periode_tahun_default'] = $currentYear;
        $data['periode_bulan_default'] = (int) date('m');
        $data['tagihan'] = mst_tagihan::orderBy('urut', 'asc')->get();

        return view('admin.keuangan.tagihan_siswa.upload_tagihan_excel.index', $data);
    }

    public function getColumn()
    {
        return [
            ['data' => null, 'name' => 'no', 'className' => 'text-center', 'columnType' => 'row'],
            ['data' => 'nis', 'name' => 'NIS', 'searchable' => true, 'orderable' => true],
            ['data' => 'nodaftar', 'name' => 'NODAF', 'searchable' => true, 'orderable' => true],
            ['data' => 'name', 'name' => 'Nama Siswa', 'searchable' => true, 'orderable' => true],
            ['data' => 'status', 'name' => 'Status', 'searchable' => true, 'orderable' => true, 'columnType' => 'importstatus'],
            ['data' => 'keterangan', 'name' => 'Keterangan', 'searchable' => true, 'orderable' => true],
            ['data' => 'unit', 'name' => 'Unit', 'searchable' => true, 'orderable' => true],
            ['data' => 'kelas', 'name' => 'Kelas', 'searchable' => true, 'orderable' => true],
            ['data' => 'kelompok', 'name' => 'Kelompok', 'searchable' => true, 'orderable' => true],
            ['data' => 'angkatan', 'name' => 'Angkatan', 'searchable' => true, 'orderable' => true],
            ['data' => 'gender', 'name' => 'Jenis Kelamin', 'searchable' => true, 'orderable' => true],
            ['data' => 'ortu', 'name' => 'Ortu / Wali', 'searchable' => true, 'orderable' => true],
            ['data' => 'alamat', 'name' => 'Alamat', 'searchable' => true, 'orderable' => true],
            ['data' => 'nominal', 'name' => 'Nominal', 'searchable' => true, 'orderable' => true, 'columnType' => 'currency'],
        ];
    }

    public function getData(Request $request)
    {
        try {
            return $this->buildImportedTagihanData($request);
        } catch (\Throwable $e) {
            return response()->json([
                'draw' => (int) $request->get('draw'),
                'recordsTotal' => 0,
                'recordsFiltered' => 0,
                'data' => [],
            ]);
        }
    }

    private function buildImportedTagihanData(Request $request)
    {
        $draw = $request->get('draw');
        $start = $request->get('start');
        $rowperpage = $request->get('length');

        $columnName_arr = $request->get('columns') ?? [];
        $search_arr = $request->get('search') ?? [];

        $defaultColumn = 'scctcust.nocust';
        $defaultOrder = 'asc';

        $columnSortOrder = $defaultOrder;
        $columnName = $defaultColumn;

        if ($request->has('order')) {
            $order = $request->get('order');
            $columnIndex = (int) ($order[0]['column'] ?? 0);
            $columnSortOrder = $order[0]['dir'] ?? $defaultOrder;
            $requestedColumn = is_array($columnName_arr)
                ? ($columnName_arr[$columnIndex]['data'] ?? null)
                : null;

            if ($requestedColumn && $requestedColumn !== 'no') {
                $columnName = 'scctcust.' . $requestedColumn;
            }
        }

        $cachedData = Cache::get($this->cacheKey, []);
        if (!is_array($cachedData)) {
            $cachedData = [];
        }

        $nisCount = count($cachedData);

        $whereAny = [
            'scctcust.NMCUST',
            'scctcust.NOCUST',
        ];

        $select = array_unique(array_merge($whereAny, [
            'scctcust.CUSTID',
            'scctcust.NUM2ND',
            'scctcust.CODE02',
            'scctcust.DESC02',
            'scctcust.DESC03',
            'scctcust.DESC04',
            'scctcust.STCUST',
        ]));

        $records = collect($cachedData)->map(function ($item) use ($select) {
            $custId = trim((string) ($item['custid'] ?? ''));
            $nis = trim((string) ($item['nis'] ?? ''));
            $nodaftar = trim((string) ($item['nodaftar'] ?? ''));

            $siswa = null;
            $siswaQuery = scctcust::select($select);
            SchoolScope::apply($siswaQuery, 'scctcust', $this->sekolah);

            if ($custId !== '') {
                $siswa = (clone $siswaQuery)->where('scctcust.CUSTID', $custId)->first();
            } elseif ($nis !== '') {
                $siswa = (clone $siswaQuery)->where('scctcust.NOCUST', $nis)->first();
            } elseif ($nodaftar !== '') {
                $siswa = (clone $siswaQuery)->where('scctcust.NUM2ND', $nodaftar)->first();
            }

            return [
                'nis' => $nis !== '' ? $nis : (($siswa && $siswa->NOCUST && $siswa->NOCUST !== '-') ? (string) $siswa->NOCUST : null),
                'nodaftar' => $nodaftar !== '' ? $nodaftar : (($siswa && $siswa->NUM2ND && $siswa->NUM2ND !== '-') ? (string) $siswa->NUM2ND : null),
                'name' => $item['nama'] ?? ($siswa->NMCUST ?? null),
                'ortu' => $item['ortu'] ?? $item['ayah'] ?? $item['genus'] ?? null,
                'alamat' => $item['alamat'] ?? null,
                'gender' => $item['gender'] ?? null,
                'unit' => $item['unit'] ?? ($siswa->CODE02 ?? null),
                'kelas' => $item['kelas'] ?? ($siswa->DESC02 ?? null),
                'kelompok' => $item['kelompok'] ?? ($siswa->DESC03 ?? null),
                'angkatan' => $item['angkatan'] ?? ($siswa->DESC04 ?? null),
                'nominal' => $item['nominal'] ?? null,
                'status' => $item['status'] ?? 0,
                'keterangan' => $item['keterangan'] ?? null,
            ];
        });

        $response = [
            'draw' => intval($draw),
            'recordsTotal' => $nisCount,
            'recordsFiltered' => $nisCount,
            'data' => $records,
        ];

        return response()->json($response);
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
                'nodaftar' => ['nodaftar', 'nodaf', 'no_daftar', 'no_pend', 'nopend', 'no_pendaftaran'],
                'nominal' => ['nominal', 'jumlah', 'tagihan'],
            ];

            $hasNis = false;
            $hasNodaftar = false;
            foreach ($aliases['nis'] as $alias) {
                if (in_array($alias, $headings, true)) {
                    $hasNis = true;
                    break;
                }
            }
            foreach ($aliases['nodaftar'] as $alias) {
                if (in_array($alias, $headings, true)) {
                    $hasNodaftar = true;
                    break;
                }
            }

            $hasNominal = false;
            foreach ($aliases['nominal'] as $alias) {
                if (in_array($alias, $headings, true)) {
                    $hasNominal = true;
                    break;
                }
            }

            $missingColumns = [];
            if (!$hasNis && !$hasNodaftar) {
                $missingColumns[] = 'NIS / NODAF';
            }
            if (!$hasNominal) {
                $missingColumns[] = 'NOMINAL';
            }

            if (!empty($missingColumns)) {
                throw new \Exception(
                    'Kolom ' . implode(', ', $missingColumns) . ' tidak ditemukan.<br><hr>' .
                    'Pastikan kolom berikut ada: <strong>NIS atau NODAF, NOMINAL</strong>.'
                );
            }

            DB::beginTransaction();
            Excel::import(new ImportTagihanExcel($this->cacheKey, $this->sekolah), $file);
            DB::commit();

            $data = Cache::get($this->cacheKey, []);
            if (empty($data)) {
                throw new \Exception('File berhasil dibaca, tetapi tidak ada baris data yang dapat diproses. Pastikan file berisi NIS atau NODAF dan Nominal.');
            }

            $invalidCount = collect($data)->where('status', 0)->count();
            $message = 'Sukses, data tagihan telah diimport, silahkan periksa kembali';
            if ($invalidCount > 0) {
                $message .= " ({$invalidCount} baris perlu diperbaiki, lihat kolom Keterangan)";
            }

            Log::info('Upload tagihan excel berhasil', [
                'user_id' => auth()->id(),
                'file_name' => $file->getClientOriginalName(),
                'row_count' => count($data),
            ]);

            return response()->json(['message' => $message, 'data' => $data], 200);
        } catch (ValidationException $e) {
            DB::rollBack();
            $errorMessages = $e->errors();
            $errorMessage = $errorMessages['error'][0] ?? 'Terjadi kesalahan saat melakukan import data.';

            Log::warning('Upload tagihan excel gagal validasi excel', [
                'user_id' => auth()->id(),
                'file_name' => $file?->getClientOriginalName(),
                'errors' => $errorMessages,
            ]);

            return response()->json(['message' => $errorMessage, 'error' => $errorMessages], 422);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Upload tagihan excel gagal', [
                'user_id' => auth()->id(),
                'file_name' => $file?->getClientOriginalName(),
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);

            $error = $e->getMessage();

            return response()->json([
                'message' => "Gagal!<br> tidak dapat melakukan {$this->mainTitle}.<hr> {$error}",
                'error' => $error,
            ], 422);
        }
    }

    public function validateExcel(Request $request)
    {
        $request->validate([
            'tagihan' => ['required'],
            'periode_tahun' => ['required', 'integer', 'digits:4', 'min:2000', 'max:2099'],
            'periode_bulan' => ['nullable', 'integer', 'min:1', 'max:12'],
        ], ValidationMessage::messages(), ValidationMessage::attributes());

        $data = Cache::get($this->cacheKey);
        if (empty($data)) {
            return response()->json(['message' => 'Silahkan import data tagihan terlebih dahulu'], 422);
        }

        $validRows = collect($data)->where('status', 1)->values();
        if ($validRows->isEmpty()) {
            return response()->json(['message' => 'Tidak ada baris valid yang dapat disimpan. Perbaiki kolom Keterangan.'], 422);
        }

        $bta = $request->filled('periode_bulan')
            ? sprintf('%04d%02d', (int) $request->periode_tahun, (int) $request->periode_bulan)
            : sprintf('%04d', (int) $request->periode_tahun);

        $tagihan = mst_tagihan::where('urut', $request->tagihan)->first();
        if (!$tagihan) {
            return response()->json(['message' => 'Tagihan tidak ditemukan, silahkan muat ulang halaman!'], 422);
        }

        try {
            DB::beginTransaction();
            $skippedInactive = [];
            $insertedCount = 0;

            foreach ($validRows as $item) {
                $siswaQuery = scctcust::query();
                SchoolScope::apply($siswaQuery, 'scctcust', $this->sekolah);

                $custId = trim((string) ($item['custid'] ?? ''));
                $nis = trim((string) ($item['nis'] ?? ''));
                $nodaftar = trim((string) ($item['nodaftar'] ?? ''));

                if ($custId !== '') {
                    $siswaQuery->where('CUSTID', $custId);
                } elseif ($nis !== '') {
                    $siswaQuery->where('NOCUST', $nis);
                } elseif ($nodaftar !== '') {
                    $siswaQuery->where('NUM2ND', $nodaftar);
                } else {
                    DB::rollBack();
                    return response()->json(['message' => 'Baris tanpa NIS/NODAF tidak dapat disimpan.'], 422);
                }

                $siswa = $siswaQuery->first();
                if (!$siswa) {
                    DB::rollBack();
                    $label = $nis !== '' ? "NIS: {$nis}" : ($nodaftar !== '' ? "NODAF: {$nodaftar}" : "CUSTID: {$custId}");
                    return response()->json(['message' => "Siswa dengan {$label} tidak ditemukan!"], 422);
                }

                if ((int) ($siswa->STCUST ?? 0) === 0) {
                    $skippedInactive[] = trim(($nis ?: $nodaftar) . ' - ' . ($siswa->NMCUST ?? 'Tanpa Nama'));
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
                    'BILLPAID' => 0,
                    'PAYMENTLEFT' => $nominal,
                    'PAIDST' => 0,
                    'FUrutan' => $urut,
                    'FTGLTagihan' => now(),
                    'FSTSBolehBayar' => 1,
                    'BTA' => $bta,
                    'BILLCD' => $billCD,
                    'INSTALLMENT' => 0,
                    'isINSTALLABLE' => (int) ($tagihan->isINSTALLMENT ?? 0),
                ]);
                $insertedCount++;
            }

            Cache::forget($this->cacheKey);
            Cache::forget('import_tagihan_excel');

            DB::commit();
            $message = "Data tagihan disimpan! Berhasil dibuat untuk {$insertedCount} siswa.";
            if (!empty($skippedInactive)) {
                $message .= '<hr>Tagihan tidak dibuat untuk siswa nonaktif (STCUST=0): ' . count($skippedInactive) . ' siswa.<br>' .
                    implode('<br>', $skippedInactive);
            }
            return response()->json(['message' => $message], 200);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Simpan tagihan excel gagal', [
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
        Cache::forget('import_tagihan_excel');

        return response()->json(['message' => 'Data import telah dibersihkan']);
    }
}
