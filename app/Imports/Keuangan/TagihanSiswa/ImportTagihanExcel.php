<?php

namespace App\Imports\Keuangan\TagihanSiswa;

use App\Models\scctcust;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class ImportTagihanExcel implements WithMultipleSheets, ToCollection, WithHeadingRow, SkipsEmptyRows
{
    public function __construct(private string $cacheKey = 'import_tagihan_excel')
    {
    }

    public function sheets(): array
    {
        return [
            0 => $this,
        ];
    }

    public function collection(Collection $collection): void
    {
        $processedData = [];

        foreach ($collection as $row) {
            if ($row->filter()->isEmpty()) {
                continue;
            }

            $rowData = $this->normalizeRow($row->toArray());
            $nis = $rowData['nis'] ?? '';
            $nominal = $rowData['nominal'] ?? null;
            $nominalBlank = $nominal === null || trim((string) $nominal) === '';

            if ($nis === '' && $nominalBlank) {
                continue;
            }

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

    private function normalizeRow(array $rowData): array
    {
        $lookup = [];
        foreach ($rowData as $key => $value) {
            $lookup[strtolower(trim((string) $key))] = $value;
        }

        $pick = function (array $aliases) use ($lookup) {
            foreach ($aliases as $alias) {
                if (array_key_exists($alias, $lookup)) {
                    return $lookup[$alias];
                }
            }
            return null;
        };

        $nisRaw = $pick(['nis']);
        $nodaftarRaw = $pick(['nodaftar', 'no_daftar', 'no_pend', 'nopend']);
        $kelasRaw = $pick(['kelas']);
        $nominalRaw = $pick(['nominal', 'jumlah', 'tagihan']);

        $nis = $nisRaw === null || trim((string) $nisRaw) === ''
            ? ''
            : (is_numeric($nisRaw) ? (string) (int) $nisRaw : trim((string) $nisRaw));
        $nodaftar = $nodaftarRaw === null || trim((string) $nodaftarRaw) === ''
            ? null
            : (is_numeric($nodaftarRaw) ? (string) (int) $nodaftarRaw : trim((string) $nodaftarRaw));

        return [
            'nis' => $nis,
            'nodaftar' => $nodaftar,
            'nama' => trim((string) ($pick(['nama', 'nmcust']) ?? '')),
            'unit' => trim((string) ($pick(['unit']) ?? '')),
            'kelas' => is_numeric($kelasRaw) ? (string) (int) $kelasRaw : trim((string) ($kelasRaw ?? '')),
            'kelompok' => trim((string) ($pick(['kelompok']) ?? '')),
            'angkatan' => trim((string) ($pick(['angkatan']) ?? '')),
            'gender' => trim((string) ($pick(['gender', 'jk', 'jenis_kelamin']) ?? '')) ?: null,
            'alamat' => trim((string) ($pick(['alamat']) ?? '')) ?: null,
            'ortu' => trim((string) ($pick(['ortu', 'genus', 'ayah', 'wali']) ?? '')) ?: null,
            'nominal' => $nominalRaw,
        ];
    }

    public function headingRow(): int
    {
        return 1;
    }
}
