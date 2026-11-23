<?php

namespace App\Imports\Keuangan\TagihanSiswa;

use App\Models\scctcust;
use App\Support\SchoolScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class ImportTagihanExcel implements WithMultipleSheets, ToCollection, WithHeadingRow, SkipsEmptyRows
{
    public function __construct(
        private string $cacheKey = 'import_tagihan_excel',
        private ?string $sekolah = null
    ) {
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
            $nodaftar = $rowData['nodaftar'] ?? '';
            $nominal = $rowData['nominal'] ?? null;

            if ($nis === '' && $nodaftar === '' && $nominal === null) {
                continue;
            }

            $rowData['status'] = 1;
            $statusKet = [];

            $siswa = null;
            if ($nis === '' && $nodaftar === '') {
                $rowData['status'] = 0;
                $statusKet[] = 'NIS atau NODAF tidak boleh kosong';
            } else {
                if ($nis !== '') {
                    $siswaQuery = scctcust::where('NOCUST', $nis);
                    SchoolScope::apply($siswaQuery, 'scctcust', $this->sekolah);
                    $siswa = $siswaQuery->first();
                    if (!$siswa) {
                        $rowData['status'] = 0;
                        $statusKet[] = "NIS {$nis} tidak ditemukan";
                    }
                } else {
                    $siswaQuery = scctcust::where('NUM2ND', $nodaftar);
                    SchoolScope::apply($siswaQuery, 'scctcust', $this->sekolah);
                    $siswa = $siswaQuery->first();
                    if (!$siswa) {
                        $rowData['status'] = 0;
                        $statusKet[] = "NODAF {$nodaftar} tidak ditemukan";
                    }
                }

                if ($siswa && (int) ($siswa->STCUST ?? 0) === 0) {
                    $rowData['status'] = 0;
                    $statusKet[] = 'Siswa nonaktif (STCUST=0)';
                }
            }

            if ($siswa) {
                $rowData['custid'] = $siswa->CUSTID;
                $rowData['nama'] = ($siswa->NMCUST && $siswa->NMCUST !== '-') ? (string) $siswa->NMCUST : $rowData['nama'];
                $rowData['nis'] = ($siswa->NOCUST && $siswa->NOCUST !== '-') ? (string) $siswa->NOCUST : $rowData['nis'];
                $rowData['nodaftar'] = ($siswa->NUM2ND && $siswa->NUM2ND !== '-') ? (string) $siswa->NUM2ND : $rowData['nodaftar'];
                $rowData['unit'] = $rowData['unit'] ?: ($siswa->CODE02 ?? null);
                $rowData['kelas'] = $rowData['kelas'] ?: ($siswa->DESC02 ?? null);
                $rowData['kelompok'] = $rowData['kelompok'] ?: ($siswa->DESC03 ?? null);
                $rowData['angkatan'] = $rowData['angkatan'] ?: ($siswa->DESC04 ?? null);
            } else {
                $rowData['custid'] = null;
            }

            if ($nominal === null || (int) $nominal <= 0) {
                $rowData['status'] = 0;
                $statusKet[] = 'Nominal harus lebih dari 0';
            } else {
                $rowData['nominal'] = (int) $nominal;
            }

            $rowData['keterangan'] = $statusKet ? implode(', ', $statusKet) : null;
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
            $normalized = strtolower(trim((string) $key));
            $normalized = str_replace(['*', '-'], ['', '_'], $normalized);
            $normalized = preg_replace('/\s+/', '_', $normalized);
            $lookup[$normalized] = $value;
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
        $nodaftarRaw = $pick(['nodaftar', 'nodaf', 'no_daftar', 'no_pend', 'nopend', 'no_pendaftaran']);
        $nominalRaw = $pick(['nominal', 'jumlah', 'tagihan']);
        $kelasRaw = $pick(['kelas']);

        $nis = $nisRaw === null || trim((string) $nisRaw) === ''
            ? ''
            : (is_numeric($nisRaw) ? (string) (int) $nisRaw : trim((string) $nisRaw));
        $nodaftar = $nodaftarRaw === null || trim((string) $nodaftarRaw) === ''
            ? ''
            : (is_numeric($nodaftarRaw) ? (string) (int) $nodaftarRaw : trim((string) $nodaftarRaw));

        $nominal = null;
        if ($nominalRaw !== null && trim((string) $nominalRaw) !== '') {
            $cleaned = preg_replace('/[^\d]/', '', (string) $nominalRaw);
            if ($cleaned !== '') {
                $nominal = (int) $cleaned;
            }
        }

        return [
            'nis' => $nis,
            'nodaftar' => $nodaftar,
            'custid' => null,
            'nama' => trim((string) ($pick(['nama', 'nmcust', 'nama_siswa']) ?? '')),
            'unit' => trim((string) ($pick(['unit']) ?? '')),
            'kelas' => is_numeric($kelasRaw) ? (string) (int) $kelasRaw : trim((string) ($kelasRaw ?? '')),
            'kelompok' => trim((string) ($pick(['kelompok']) ?? '')),
            'angkatan' => trim((string) ($pick(['angkatan']) ?? '')),
            'gender' => trim((string) ($pick(['gender', 'jk', 'jenis_kelamin']) ?? '')) ?: null,
            'alamat' => trim((string) ($pick(['alamat']) ?? '')) ?: null,
            'ortu' => trim((string) ($pick(['ortu', 'genus', 'ayah', 'wali']) ?? '')) ?: null,
            'nominal' => $nominal,
        ];
    }

    public function headingRow(): int
    {
        return 1;
    }
}
