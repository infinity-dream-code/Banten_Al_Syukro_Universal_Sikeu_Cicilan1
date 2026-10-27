<?php

namespace App\Imports\Keuangan\TagihanSiswa;

use App\Models\mst_tagihan;
use App\Models\scctcust;
use App\Support\PeriodeHelper;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class ImportTagihanLunasExcel implements WithMultipleSheets, ToCollection, WithHeadingRow, SkipsEmptyRows
{
    public const BANK_ALIASES = [
        '1140000' => '1140000',
        'cash' => '1140000',
        'manual cash' => '1140000',
        'tunai' => '1140000',
        '1140001' => '1140001',
        'bcas' => '1140001',
        'manual bcas' => '1140001',
        'bmi' => '1140001',
        'manual bmi' => '1140001',
        '1140002' => '1140002',
        'saldo' => '1140002',
        'manual saldo' => '1140002',
        '1140003' => '1140003',
        'transfer' => '1140003',
        'transfer bank lain' => '1140003',
    ];

    public function __construct(private string $cacheKey = 'import_tagihan_lunas_excel')
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
        $masterTagihan = mst_tagihan::orderBy('urut')->get();
        $processedData = [];

        foreach ($collection as $row) {
            if ($row->filter()->isEmpty()) {
                continue;
            }

            $rowData = $this->normalizeRow($row->toArray());
            if (
                $rowData['nis'] === ''
                && $rowData['nama_tagihan'] === ''
                && $rowData['nominal'] === null
            ) {
                continue;
            }

            $rowData['status'] = 1;
            $statusKet = [];

            $siswa = null;
            if ($rowData['nis'] === '') {
                $rowData['status'] = 0;
                $statusKet[] = 'NIS tidak boleh kosong';
            } else {
                $siswa = scctcust::where('NOCUST', $rowData['nis'])->first();
                if (!$siswa) {
                    $rowData['status'] = 0;
                    $statusKet[] = "NIS {$rowData['nis']} tidak ditemukan";
                } elseif ((int) ($siswa->STCUST ?? 0) === 0) {
                    $rowData['status'] = 0;
                    $statusKet[] = 'Siswa nonaktif (STCUST=0)';
                }
            }

            $rowData['nama'] = $siswa->NMCUST ?? $rowData['nama'];

            if ($rowData['nama_tagihan'] === '') {
                $rowData['status'] = 0;
                $statusKet[] = 'Nama tagihan tidak boleh kosong';
            } else {
                $matched = $this->matchTagihan($masterTagihan, $rowData['nama_tagihan']);
                if ($matched) {
                    $rowData['billnm'] = $matched->tagihan;
                    $rowData['is_installable'] = (int) ($matched->isINSTALLMENT ?? 0);
                } else {
                    $rowData['billnm'] = $rowData['nama_tagihan'];
                    $rowData['is_installable'] = 0;
                    $statusKet[] = "Nama tagihan \"{$rowData['nama_tagihan']}\" tidak ada di Master Tagihan";
                    $rowData['status'] = 0;
                }
            }

            $rowData['billac'] = PeriodeHelper::fromLabel($rowData['periode']);
            if (!$rowData['billac']) {
                $rowData['status'] = 0;
                $statusKet[] = 'Periode tidak valid (contoh: Juli 2026)';
            }

            if ($rowData['nominal'] === null || (int) $rowData['nominal'] <= 0) {
                $rowData['status'] = 0;
                $statusKet[] = 'Nominal harus lebih dari 0';
            } else {
                $rowData['nominal'] = (int) $rowData['nominal'];
            }

            $rowData['tanggal_bayar'] = $this->parseTanggal($rowData['tanggal_bayar_raw']);
            if (!$rowData['tanggal_bayar']) {
                $rowData['status'] = 0;
                $statusKet[] = 'Tanggal bayar tidak valid (contoh: 4/8/2026)';
            }

            $rowData['fidbank'] = $this->resolveBank($rowData['bank']);
            if (!$rowData['fidbank']) {
                $rowData['status'] = 0;
                $statusKet[] = 'Bank tidak dikenali (BCAS / Cash / Saldo / Transfer)';
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
        $nominalRaw = $pick(['nominal', 'jumlah']);
        $namaTagihan = trim((string) ($pick(['nama_tagihan', 'namatagihan', 'tagihan']) ?? ''));
        $periode = trim((string) ($pick(['periode']) ?? ''));
        $tanggalRaw = $pick(['tanggal_bayar', 'tgl_bayar', 'tanggalbayar']);
        $bank = trim((string) ($pick(['bank']) ?? ''));

        $nis = $nisRaw === null || trim((string) $nisRaw) === ''
            ? ''
            : (is_numeric($nisRaw) ? (string) (int) $nisRaw : trim((string) $nisRaw));

        $nominal = null;
        if ($nominalRaw !== null && trim((string) $nominalRaw) !== '') {
            $nominal = (int) preg_replace('/[^\d]/', '', (string) $nominalRaw);
        }

        return [
            'nis' => $nis,
            'nama' => null,
            'nama_tagihan' => $namaTagihan,
            'billnm' => $namaTagihan,
            'periode' => $periode,
            'billac' => null,
            'nominal' => $nominal,
            'tanggal_bayar_raw' => $tanggalRaw,
            'tanggal_bayar' => null,
            'bank' => $bank,
            'fidbank' => null,
            'is_installable' => 0,
        ];
    }

    private function matchTagihan(Collection $master, string $nama): ?mst_tagihan
    {
        $needle = strtoupper(trim($nama));
        foreach ($master as $item) {
            if (strtoupper(trim((string) $item->tagihan)) === $needle) {
                return $item;
            }
        }

        $matches = $master->filter(function ($item) use ($needle) {
            $hay = strtoupper(trim((string) $item->tagihan));
            return $hay !== '' && (str_contains($hay, $needle) || str_contains($needle, $hay));
        });

        return $matches->count() === 1 ? $matches->first() : null;
    }

    private function parseTanggal(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        if ($raw instanceof \DateTimeInterface) {
            return Carbon::parse($raw)->format('Y-m-d');
        }

        if (is_numeric($raw) && (float) $raw > 20000) {
            try {
                return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $raw))->format('Y-m-d');
            } catch (\Throwable $e) {
                return null;
            }
        }

        $text = trim((string) $raw);
        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d', 'd/m/y', 'd-m-y'] as $format) {
            try {
                $dt = Carbon::createFromFormat($format, $text);
                if ($dt !== false) {
                    return $dt->format('Y-m-d');
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        try {
            return Carbon::parse($text)->format('Y-m-d');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function resolveBank(string $bank): ?string
    {
        $key = strtolower(trim($bank));
        $key = preg_replace('/\s+/', ' ', $key);

        return self::BANK_ALIASES[$key] ?? null;
    }

    public function headingRow(): int
    {
        return 1;
    }
}
