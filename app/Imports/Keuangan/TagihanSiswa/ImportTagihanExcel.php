<?php

namespace App\Imports\Keuangan\TagihanSiswa;

use App\Models\scctcust;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class ImportTagihanExcel implements ToCollection, WithHeadingRow
{
    public function __construct(private string $cacheKey = 'import_tagihan_excel')
    {
    }

    public function collection(Collection $collection): void
    {
        $processedData = [];

        foreach ($collection as $row) {
            if ($row->filter()->isEmpty()) {
                continue;
            }

            $rowData = $row->toArray();
            $nis = trim((string) ($rowData['nis'] ?? ''));
            $nominal = $rowData['nominal'] ?? null;
            $nominalBlank = $nominal === null || trim((string) $nominal) === '';

            if ($nis === '' && $nominalBlank) {
                continue;
            }

            $rowData['nis'] = $nis;
            $rowData['status'] = 1;
            $status_ket = null;

            if ($nis === '') {
                $rowData['status'] = 0;
                $status_ket = 'NIS tidak boleh kosong';
            } else {
                $checkData = scctcust::where('NOCUST', $nis)->first();
                if (!$checkData) {
                    $rowData['status'] = 0;
                    $status_ket = "NIS {$nis} tidak ditemukan";
                }
            }

            if ($nominalBlank) {
                $rowData['status'] = 0;
                if (!empty($status_ket)) {
                    $status_ket .= ', ';
                }
                $status_ket .= 'Nominal tidak boleh kosong';
            }

            $rowData['keterangan'] = $status_ket;
            $processedData[] = $rowData;
        }

        Cache::forget($this->cacheKey);
        if (!empty($processedData)) {
            Cache::put($this->cacheKey, $processedData, now()->addMinutes(60));
        }
    }

    public function headingRow(): int
    {
        return 1;
    }
}
