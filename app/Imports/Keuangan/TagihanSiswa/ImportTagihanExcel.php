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
            $nisRaw = $rowData['nis'] ?? '';
            $nis = is_numeric($nisRaw) ? (string) (int) $nisRaw : trim((string) $nisRaw);
            $nominal = $rowData['nominal'] ?? null;
            $nominalBlank = $nominal === null || trim((string) $nominal) === '';

            if ($nis === '' && $nominalBlank) {
                continue;
            }

            $rowData['nis'] = $nis;
            $rowData['nama'] = trim((string) ($rowData['nama'] ?? ''));
            $rowData['unit'] = trim((string) ($rowData['unit'] ?? ''));
            $rowData['kelas'] = is_numeric($rowData['kelas'] ?? null)
                ? (string) (int) $rowData['kelas']
                : trim((string) ($rowData['kelas'] ?? ''));
            $rowData['kelompok'] = trim((string) ($rowData['kelompok'] ?? ''));
            $rowData['angkatan'] = trim((string) ($rowData['angkatan'] ?? ''));
            $rowData['gender'] = trim((string) ($rowData['gender'] ?? '')) ?: null;
            $rowData['alamat'] = trim((string) ($rowData['alamat'] ?? '')) ?: null;
            $rowData['ortu'] = trim((string) ($rowData['ortu'] ?? $rowData['genus'] ?? $rowData['ayah'] ?? '')) ?: null;
            $rowData['nodaftar'] = isset($rowData['nodaftar']) && trim((string) $rowData['nodaftar']) !== ''
                ? (is_numeric($rowData['nodaftar']) ? (string) (int) $rowData['nodaftar'] : trim((string) $rowData['nodaftar']))
                : null;
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
