<?php

namespace App\Http\Controllers\Admin\Keuangan\TagihanSiswa;

use App\Http\Controllers\Controller;
use App\Models\mst_kelas;
use App\Models\mst_tagihan;
use App\Models\mst_thn_aka;
use App\Models\scctbill;
use App\Models\scctbill_detail;
use App\Models\scctcust;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class ExportTagihanController extends Controller
{
    private string $title;
    private string $mainTitle;
    private string $dataTitle;

    public function __construct()
    {
        $this->title = 'Keuangan';
        $this->mainTitle = 'Tagihan Siswa';
        $this->dataTitle = 'Export Tagihan';
    }

    public function index()
    {
        $data['title'] = $this->title;
        $data['mainTitle'] = $this->mainTitle;
        $data['dataTitle'] = $this->dataTitle;
        $data['columnsUrl'] = route('admin.keuangan.tagihan-siswa.export-tagihan.get-column');
        $data['datasUrl'] = route('admin.keuangan.tagihan-siswa.export-tagihan.get-data');

        $data['thn_aka'] = mst_thn_aka::orderBy('thn_aka', 'desc')->get();
//        dd($data['thn_aka']);
        $data['kelas'] = mst_kelas::orderByRaw("CASE WHEN kelas REGEXP '^[0-9]+$' THEN 0 ELSE 1 END, kelas")->get();
        $data['tagihan'] = mst_tagihan::orderBy('urut', 'asc')->get();

        return view('admin.keuangan.tagihan_siswa.export_tagihan.index_new', $data);
    }

    public function getColumn()
    {
        return [
            ['data' => 'item_id', 'name' => 'no', 'columnType' => 'row'],
            ['data' => 'NOCUST', 'name' => 'NIS', 'searchable' => true, 'orderable' => true],
            ['data' => 'NMCUST', 'name' => 'NAMA', 'searchable' => true, 'orderable' => true],
            ['data' => 'BILLNM', 'name' => 'Nama Tagihan', 'searchable' => true, 'orderable' => true],
            ['data' => 'BILLAM', 'name' => 'Jumlah', 'searchable' => true, 'orderable' => true, 'columnType' => 'currency', 'className' => 'text-end'],
            ['data' => 'BTA', 'name' => 'Tahun AKA', 'searchable' => true, 'orderable' => true],
            ['data' => 'FUrutan', 'name' => 'Urutan', 'searchable' => true, 'orderable' => true],
        ];
    }

    public function getData(Request $request)
    {
        $draw = (int)$request->get('draw', 1);
        $start = (int)$request->get('start', 0);
        $rowperpage = (int)$request->get('length', 10);

        $columnName = "scctcust.CUSTID";
        $columnSortOrder = "asc";

        $order = $request->get('order', []);
        if (!empty($order)) {
            $columnIndex = (int)($order[0]['column'] ?? -1);
            $columns = $request->get('columns', []);

            if ($columnIndex >= 0 && isset($columns[$columnIndex])) {
                $columnData = $columns[$columnIndex]['data'] ?? '';
                if (!in_array($columnData, ['no', 'item_id'], true) && $columnData !== '') {
                    $columnName = $columnData;
                    $columnSortOrder = strtolower($order[0]['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
                }
            }
        }

        $searchValue = $request->input('search.value', '');

        $filters = [];
        $filterQuery = null;

        $filter = $request->input('filter');
        if ($filter) {
            foreach ($filter as $key => $val) {
                if (is_array($val) || (strtolower((string) $val) != 'all' && $val !== null && $val !== '')) {
                    $colName = match ($key) {
                        'tanggal-pembuatan' => 'scctbill.FTGLTagihan',
                        'tahun_akademik' => 'scctbill.BTA',
                        'post' => 'scctbill.BILLNM',
                        'kelas' => 'scctcust.DESC02',
                        'siswa' => 'scctcust.nmcust',
                        default => null
                    };
                    if ($key == 'tanggal-pembuatan') {
                        if (preg_match('/^\d{2}-\d{2}-\d{4} [-\/~] \d{2}-\d{2}-\d{4}$/', $val)) {
                            $val = preg_replace('/[-\/~]/', '-', $val);

                            list($startDate, $endDate) = explode(' - ', $val);
                            $startDate = Carbon::createFromFormat('d-m-Y', $startDate)->startOfDay();
                            $endDate = Carbon::createFromFormat('d-m-Y', $endDate)->endOfDay();
                            if ($startDate && $endDate) {
                                ($colName) && $filters[] = [$colName, $startDate, $endDate, 'whereBetween'];
                            }
                        }
                    } elseif ($key == 'siswa') {
                        $val = is_numeric($val) ? $val : '%' . $val . '%';
                        $colName = is_numeric($val) ? 'scctcust.nocust' : $colName;
                        ($colName) && $filters[] = [$colName, 'like', $val];
                    } elseif ($key == 'kelas') {
                        $filters[] = ['_kelas_multi', '=', $val];
                    } else {
                        ($colName) && $filters[] = [$colName, '=', $val];
                    }
                }
            };

            if (!empty($filters)) {
                $filterQuery = function ($query) use ($filters) {
                    foreach ($filters as $filter) {
                        if (($filter[0] ?? null) === '_kelas_multi') {
                            \App\Support\KelasFilter::apply($query, $filter[2] ?? []);
                            continue;
                        }
                        if (count($filter) === 3) {
                            $query->where($filter[0], $filter[1], $filter[2]);
                        } elseif (count($filter) === 4) {
                            if ($filter[3] == 'whereBetween') {
                                $query->whereBetween($filter[0], [$filter[1], $filter[2]]);
                            } else {
                                $query->{$filter[3]}($filter[0], $filter[1], $filter[2]);
                            }
                        }
                    }
                };
            }
        }

        $whereAny = [
            'scctcust.NMCUST',
            'scctcust.NOCUST',
        ];

        $select = array_unique(array_merge($whereAny, [
            'scctbill.AA',
            'scctbill.BILLNM',
            'scctbill.BILLAM',
            'scctbill.PAIDST',
            'scctbill.PAIDDT',
            'scctbill.BTA',
            'scctbill.FIDBANK',
            'scctbill.FUrutan',
            'scctcust.CODE02',
            'scctcust.DESC02',
            'scctcust.NUM2ND',
            'scctbill.CUSTID',

        ]));

        $query = scctbill::leftJoin('scctcust', 'scctcust.CUSTID', 'scctbill.CUSTID')
            ->where('scctbill.PAIDST', 0)
            ->where('scctbill.FSTSBolehBayar', 1)
            ->when(!blank($searchValue), function ($query) use ($whereAny, $searchValue) {
                $query->where(function ($q) use ($whereAny, $searchValue) {
                    $sanitizeSearch = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $searchValue);
                    foreach ($whereAny as $column) {
                        $q->orWhere($column, 'like', '%' .$sanitizeSearch . '%');
                    }
                });
            })
            ->where(function ($query) use ($filterQuery) {
                if ($filterQuery) {
                    $filterQuery($query);
                }
            });

        $totalRecords = Cache::remember('total_penerimaan_count', 600, function () {
            return scctbill::select('count(*) as allcount')
                ->where('scctbill.PAIDST', 0)
                ->where('scctbill.FSTSBolehBayar', 1)
                ->count();
        });

        $totalRecordswithFilter = (clone $query)->count();

        $records = (clone $query)
            ->select($select)
            ->orderBy($columnName, $columnSortOrder)
            ->orderBy('scctcust.NOCUST', 'asc')
            ->orderBy('scctbill.FUrutan', 'asc')
            ->where(function ($query) use ($filterQuery) {
                if ($filterQuery) {
                    $filterQuery($query);
                }
            })
            ->skip($start)
            ->take($rowperpage)
            ->get()
            ->map(function ($item, $index) {
                $item->item_id = $item['AA'];
                $item->CUSTID = $item['CUSTID'];
                $item->NOVA = ($item->NOCUST && $item->NOCUST != '-') ? scctcust::showVA($item->NOCUST) : null;
                if (!$item->NOCUST || $item->NOCUST == '-') $item->NOCUST = null;
                if (!$item->NUM2ND || $item->NUM2ND == '-') $item->NUM2ND = null;
                $item->print = true;
                $item->naik = true;
                $item->turun = true;
                $item->delete = true;
                return $item;
            })->toArray();
        $response = array(
            "draw" => intval($draw),
            "recordsTotal" => $totalRecords ?? 0,
            "recordsFiltered" => $totalRecordswithFilter ?? 0,
            "data" => $records ?? [],
        );
        return response()->json($response);
    }

    public function getSiswa(Request $request)
    {
        $kelas = $request->kelas != 'all' ? $request->kelas ?? null : null;
        $thn_aka = $request->angkatan != 'all' ? $request->angkatan ?? null : null;
//        $thn_aka = null;

        $nis = null;
        $nama = null;
        if (isset($request->cari_siswa) && $request->cari_siswa) {
            is_numeric($request->cari_siswa) ? $nis = '%' . $request->cari_siswa . '%' : $nama = '%' . $request->cari_siswa . '%';
        }
        $siswa = [];
        $kelas = mst_kelas::where('id', '=', $kelas)->first();

        $whereAny = [
            'scctcust.NMCUST as nama',
            'scctcust.NOCUST as nis',
        ];

        $select = array_unique(array_merge($whereAny, [
            'scctcust.CUSTID',
            'scctcust.NUM2ND as nomor_pendaftaran',
            'scctcust.CODE02',
            'scctcust.DESC02 as kelas',
            'scctcust.DESC03 as jenjang',
            'scctcust.DESC04 as angkatan',
        ]));

        if ($request->siswa_only == true) {
            $siswa = scctcust::when($nis, function ($query, $nis) {
                return $query->orWhere('scctcust.NOCUST', 'like', $nis)
                    ->orWhere('scctcust.NUM2ND', 'like', $nis);
            })
                ->select($select)
                ->orderBy('scctcust.NOCUST', 'asc')
                ->get()
                ->toArray();
        } else if ($kelas) {
            $siswa = scctcust::when($kelas, function ($query, $kelas) {
                return $query->where('scctcust.CODE02', '=', $kelas->unit)
                    ->where('scctcust.DESC03', '=', $kelas->kelas)
                    ->where('scctcust.DESC02', '=', $kelas->jenjang);
            })
                ->when($thn_aka, function ($query, $thn_aka) {
                    return $query->where('scctcust.DESC04', '=', $thn_aka);
                })
                ->when($nis, function ($query, $nis) {
                    return $query->where('scctcust.NOCUST', 'like', $nis);
                })
                ->when($nama, function ($query, $nama) {
                    return $query->where('scctcust.NMCUST', 'like', $nama);
                })
                ->select($select)
                ->orderBy('scctcust.NOCUST', 'asc')
                ->get()
                ->toArray();
        }

        $response = array(
            "data" => $siswa,
        );

        return response()->json($response);
    }

    public function print(Request $request)
    {
        if (!$request['item_id']) return response()->json(['message' => 'tagihan tidak ditemukan!']);
        $request['draw'] = 2;
        $request['start'] = 0;
        $request['length'] = "poll";

        $data['tagihan'] = scctbill::where('AA', $request['item_id'])->first();
        if (!$data['tagihan']) return response()->json(['message' => 'tagihan tidak ditemukan!']);
        $data['siswa'] = scctcust::where('CUSTID', $data['tagihan']->CUSTID)->first();
        if (!$data['siswa']) return response()->json(['message' => 'siswa tidak ditemukan! 3']);
        $data['tagihans'] = scctbill_detail::select(['u_akun.NamaAkun','scctbill_detail.BILLAM'])
            ->join('u_akun', 'u_akun.KodeAkun', '=', 'scctbill_detail.KodePost')
            ->where('scctbill_detail.BILLCD', $data['tagihan']->BILLCD)
            ->where('scctbill_detail.CUSTID', $data['tagihan']->CUSTID)
            ->get();

        try {
            $pdf = Pdf::loadView('cetak.export_tagihan.export', $data);
            return $pdf->download('kartu-siswa.pdf');
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Tagihan Tidak Ditemukan', 'error' => $e], 422);
        }
    }

}
