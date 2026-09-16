<?php

namespace App\Http\Controllers\Admin\Keuangan\TagihanSiswa;

use App\Http\Controllers\Controller;
use App\Imports\Keuangan\TagihanSiswa\ImportTagihanExcel;
use App\Imports\Keuangan\TagihanSiswa\ImportTagihanPMBExcel;
use App\Models\mst_tagihan;
use App\Models\scctbill;
use App\Models\scctcust;
use App\Models\ValidationMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\HeadingRowImport;
use Maatwebsite\Excel\Validators\ValidationException;

class UploadTagihanPMBExcelController extends Controller
{
    public string $title = 'Keuangan';
    public string $mainTitle = 'Tagihan Siswa';
    public string $dataTitle = 'Upload Tagihan PMB Excel';
    public string $cacheKey = 'import_tagihan_pmb_excel';

    public function index()
    {
        $data['title'] = $this->title;
        $data['mainTitle'] = $this->mainTitle;
        $data['dataTitle'] = $this->dataTitle;
        $data['columnsUrl'] = route('admin.keuangan.tagihan-siswa.upload-tagihan-pmb-excel.get-column');
        $data['datasUrl'] = route('admin.keuangan.tagihan-siswa.upload-tagihan-pmb-excel.get-data');

        $currentYear = (int) date('Y');
        $data['periode_tahun_list'] = range($currentYear - 2, $currentYear + 5);
        $data['periode_tahun_default'] = $currentYear;
        $data['periode_bulan_default'] = (int) date('m');
        $data['tagihan'] = mst_tagihan::orderBy('urut', 'asc')->get();

        return view('admin.keuangan.tagihan_siswa.upload_tagihan_pmb_excel.index', $data);
    }

    public function getColumn()
    {
        return [
            ['data' => null, 'name' => 'no', 'className' => 'text-center', 'columnType' => 'row'],
            ['data' => 'NUM2ND', 'name' => 'No. Pendaftaran', 'searchable' => true, 'orderable' => true],
            ['data' => 'name', 'name' => 'NAMA', 'searchable' => true, 'orderable' => true],
            ['data' => 'status', 'name' => 'Status', 'searchable' => true, 'orderable' => true, 'columnType' => 'importstatus'],
            ['data' => 'keterangan', 'name' => 'Keterangan', 'searchable' => true, 'orderable' => true],
            ['data' => 'unit', 'name' => 'Unit', 'searchable' => true, 'orderable' => true],
            ['data' => 'kelas', 'name' => 'Kelas', 'searchable' => true, 'orderable' => true],
            ['data' => 'kelompok', 'name' => 'Kelompok', 'searchable' => true, 'orderable' => true],
            ['data' => 'nominal', 'name' => 'Nominal', 'searchable' => true, 'orderable' => true, 'columnType' => 'currency'],
        ];
    }

    public function getData(Request $request)
    {
        try {
            $draw = $request->get('draw');
        $start = $request->get('start');
        $rowperpage = $request->get('length');

        $columnName_arr = $request->get('columns') ?? [];
        $search_arr = $request->get('search') ?? [];

        $defaultColumn = 'scctcust.NUM2ND';
        $defaultOrder = 'asc';
        $columnSortOrder = $defaultOrder;
        $columnName = $defaultColumn;

        if ($request->has('order')) {
            $columnIndex_arr = $request->get('order') ?? [];
            $columnIndex = (int) ($columnIndex_arr[0]['column'] ?? 0);
            $columnSortOrder = $columnIndex_arr[0]['dir'] ?? $defaultOrder;
            $columnName = is_array($columnName_arr)
                ? ($columnName_arr[$columnIndex]['data'] ?? $defaultColumn)
                : $defaultColumn;
        }

        $searchValue = $search_arr['value'] ?? '';

        if (!$columnName || $columnName == 'no') {
            $columnName = $defaultColumn;
            $columnSortOrder = $defaultOrder;
        }

        $filters = [];
        $filterQuery = null;

        $cachedData = Cache::get($this->cacheKey, []);
        if (!is_array($cachedData)) {
            $cachedData = [];
        }

//        dd($cachedData);
        $nisList = collect($cachedData)->pluck('nodaftar')->toArray();
        $nisCount = count($cachedData);


        $whereAny = [
            'scctcust.NMCUST',
            'scctcust.NOCUST',
        ];

        $select = array_unique(array_merge($whereAny, [
            'scctcust.NUM2ND',
            'scctcust.CODE02',
            'scctcust.DESC02',
            'scctcust.DESC03',
            'scctcust.DESC04',

        ]));

        $records = collect($cachedData)->map(function ($item) use ($select) {
            $nodaftar = $item['nodaftar'] ?? null;
            $siswa = scctcust::select($select)->where('scctcust.NUM2ND', $nodaftar)->first();
            return [
                'NUM2ND' => $nodaftar,
                'name' => $siswa->NMCUST ?? ($item['nama'] ?? null),
                'ortu' => $item['ayah'] ?? null,
                'unit' => $siswa->CODE02 ?? null,
                'kelas' => $siswa->DESC02 ?? null,
                'kelompok' => $siswa->DESC03 ?? null,
                'nominal' => $item['nominal'] ?? null,
            ];
        });

        $response = array(
            'draw' => intval($draw),
            'recordsTotal' => $nisCount,
            'recordsFiltered' => $nisCount,
            'data' => $records,
            'nislist' => $nisList
        );
        return response()->json($response);
        } catch (\Throwable $e) {
            return response()->json([
                'draw' => (int) $request->get('draw'),
                'recordsTotal' => 0,
                'recordsFiltered' => 0,
                'data' => [],
            ]);
        }
    }


    public function store(Request $request)
    {
        $request->validate(
            [
                'fileImport' => ['required', 'mimes:xls,xlsx', 'max:1024']
            ],
            ValidationMessage::messages(),
            ValidationMessage::attributes()
        );

        $file = $request->fileImport;

        try {
            $headingsData = (new HeadingRowImport)->toArray($file);
            $requiredColumns = ['nodaftar', 'nominal'];
            if (empty($headingsData) || !isset($headingsData[0][0])) throw new \Exception ('Tidak dapat membaca judul kolom dari file. Pastikan file memiliki header yang sesuai.');
            $headings = $headingsData[0][0];
            $headings = array_map('strtolower', $headings);
            $missingColumns = [];
            foreach ($requiredColumns as $column) if (!in_array($column, $headings)) $missingColumns[] = $column;

            if (!empty($missingColumns)) {
                $formattedMissingColumns = strtoupper(str_replace('_', ' ', implode(', ', $missingColumns)));
                $formattedRequiredColumns = strtoupper(str_replace('_', ' ', implode(', ', $requiredColumns)));
                throw new \Exception("Kolom $formattedMissingColumns tidak ditemukan.<br><hr> pastikan kolom berikut ada dan terisi pada file import yang akan diproses: $formattedRequiredColumns.",);
            }

            DB::beginTransaction();
            Excel::import(new ImportTagihanPMBExcel(), $file);
            DB::commit();

            $data = Cache::get($this->cacheKey);
            return response()->json(['message' => 'Sukses, data tagihan telah diimport, silahkan periksa kembali', 'data' => $data], 200);
        } catch (ValidationException $e) {
            $errorMessages = $e->errors();
            $errorMessage = $errorMessages['error'][0] ?? 'Terjadi kesalahan saat melakukan import data.';
            return response()->json(['message' => $errorMessage, 'error' => $errorMessages], 422);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            return response()->json(['message' => "Gagal!<br> tidak dapat melakukan $this->mainTitle.<hr> $error", 'error' => $error], 422);
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
        if (empty($data))return response()->json(['message' => 'Silahkan import data tagihan terlebih dahulu'], 422);

        $bta = $request->filled('periode_bulan')
            ? sprintf('%04d%02d', (int) $request->periode_tahun, (int) $request->periode_bulan)
            : sprintf('%04d', (int) $request->periode_tahun);

        $tagihan = mst_tagihan::where('urut', $request->tagihan)->first();
        if (!$tagihan) return response()->json(['message' => 'Tagihan tidak ditemukan, silahkan muat ulang halaman!'], 422);

        try {
            DB::beginTransaction();
            foreach ($data as $item) {
                if ($item['status'] != 1) continue;
                $siswa = scctcust::where('NUM2ND', $item['nodaftar'])->first();
                if (!$siswa) return response()->json(['message' => "siswa dengan NODAFTAR: {$item['nodaftar']} tidak ditemukan!"], 422);

                $tagihanSiswaTerbaru = scctbill::where('CUSTID', $siswa->CUSTID)
                    ->orderBy('FUrutan', 'DESC')
                    ->first();

                $urut = $tagihanSiswaTerbaru ? $tagihanSiswaTerbaru['FUrutan'] + 1 : 1;

                scctbill::create([
                    'CUSTID' => $siswa->CUSTID,
                    'BILLAC' => $bta,
                    'BILLNM' => $tagihan->tagihan,
                    'BILLAM' => (int) $item['nominal'],
                    'BILLPAID' => 0,
                    'PAYMENTLEFT' => (int) $item['nominal'],
                    'PAIDST' => 0,
                    'FUrutan' => $urut,
                    'FTGLTagihan' => now(),
                    'FSTSBolehBayar' => 1,
                    'BTA' => $bta,
                    'BILLCD' => date('Y') . '/i' . date('m') . '-' . ($urut + 1),
                    'INSTALLMENT' => 0,
                    'isINSTALLABLE' => (int) ($tagihan->isINSTALLMENT ?? 0),
                ]);
            }

            Cache::forget($this->cacheKey);

            DB::commit();
            return response()->json(['message' => "Data Tagihan PMB disimpan!",], 200);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => "Terjadi kesalahan saat menyimpan data, silahkan muat ulang halaman!", 'error' => $e], 422);
        }
    }
}
