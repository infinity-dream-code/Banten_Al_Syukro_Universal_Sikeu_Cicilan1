<?php

namespace App\Support;

class WhatsappTagihan
{
    /**
     * @return list<string>
     */
    public static function templates(): array
    {
        $instansi = (string) config('app.nama_instansi', 'PERGURUAN ISLAM AL SYUKRO UNIVERSAL');

        return [
            "Assalamualaikum Warahmatullahi wabarakatuh\n"
            . "Kami dari keuangan {$instansi}\n"
            . "Memberitahukan kepada wali siswa atas nama {nama_anak}\n"
            . "Masih ada kekurangan pembayaran sebesar {jumlah_tagihan}, dengan rincian : {rincian}"
            . "Mohon untuk segera ditunaikan, atas perhatiannya kami sampaikan\n"
            . "Jazaakumullah khairan katsiran.\n\n"
            . "*_pesan otomatis dari {$instansi}_*\n"
            . "*_Silahkan menghubungi bagian keuangan jika ada kesalahan tagihan atau nama siswa_*",

            "Assalamualaikum Warahmatullahi wabarakatuh\n"
            . "Dengan hormat, kami dari {$instansi} menyampaikan bahwa ananda {nama_anak} masih memiliki tagihan sebesar {jumlah_tagihan} dengan rincian.\n"
            . "Rincian :{rincian}"
            . "Terima kasih atas perhatian dan kerjasamanya. Wassalam.\n\n"
            . "*_pesan otomatis dari {$instansi}_*\n"
            . "*_Silahkan menghubungi bagian keuangan jika ada kesalahan tagihan atau nama siswa_*",

            "Assalamualaikum Wr Wb,\n"
            . "Kami ingin menginformasikan kepada Bapak/Ibu, orang tua ananda {nama_anak}, untuk tagihan sebesar {jumlah_tagihan}.\n"
            . "Dengan Rincian :{rincian}\n"
            . "Demikian pesan dari kami. Wassalam.\n\n"
            . "*_pesan otomatis dari {$instansi}_*\n"
            . "*_Silahkan menghubungi bagian keuangan jika ada kesalahan tagihan atau nama siswa_*",
        ];
    }

    public static function pickTemplate(): string
    {
        $templates = self::templates();

        return $templates[array_rand($templates)];
    }

    /**
     * @return array{total: int, terbayar: int, sisa: int}
     */
    public static function amounts(mixed $billAm, mixed $billPaid, mixed $paymentLeft, mixed $paidSt = 0): array
    {
        $total = max(0, (int) ($billAm ?? 0));
        if ($paymentLeft === null || $paymentLeft === '') {
            $sisa = (int) $paidSt === 1 ? 0 : max(0, $total - (int) ($billPaid ?? 0));
        } else {
            $sisa = max(0, (int) $paymentLeft);
        }

        return [
            'total' => $total,
            'terbayar' => max(0, $total - $sisa),
            'sisa' => $sisa,
        ];
    }

    public static function formatPhone(?string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $raw);
        if ($digits === null || $digits === '') {
            return null;
        }

        if (str_starts_with($digits, '0')) {
            $digits = '62' . substr($digits, 1);
        } elseif (str_starts_with($digits, '8')) {
            $digits = '62' . $digits;
        }

        if (str_starts_with($digits, '620')) {
            $digits = '62' . substr($digits, 3);
        }

        if (!str_starts_with($digits, '62') || strlen($digits) < 10) {
            return null;
        }

        return $digits;
    }

    public static function formatPhoneGateway(?string $raw): ?string
    {
        $digits = self::formatPhone($raw);

        return $digits === null ? null : '+' . $digits;
    }

    public static function formatRupiah(mixed $value): string
    {
        return number_format((float) $value, 0, ',', '.');
    }

    /**
     * @param  iterable<mixed>  $bills
     */
    public static function rincian(iterable $bills): string
    {
        $lines = [];

        foreach ($bills as $bill) {
            $nama = trim((string) self::billValue($bill, 'BILLNM', '-'));
            $periode = trim((string) self::billValue($bill, 'BILLAC', ''));
            $amounts = self::amounts(
                self::billValue($bill, 'BILLAM', 0),
                self::billValue($bill, 'BILLPAID', 0),
                self::billValue($bill, 'PAYMENTLEFT', null),
                self::billValue($bill, 'PAIDST', 0)
            );
            $label = $periode !== '' ? $nama . ' (' . PeriodeHelper::label($periode) . ')' : $nama;
            $lines[] = '- ' . $label . ': Rp *' . self::formatRupiah($amounts['sisa']) . '*';
        }

        return $lines === [] ? '-' : "\n" . implode("\n", $lines) . "\n";
    }

    public static function applyTemplate(string $template, array $vars): string
    {
        $repl = [];
        foreach ($vars as $key => $value) {
            $repl['{' . $key . '}'] = (string) ($value ?? '-');
        }

        return strtr($template, $repl);
    }

    private static function billValue(mixed $bill, string $key, mixed $default = null): mixed
    {
        if (is_array($bill)) {
            return $bill[$key] ?? $default;
        }

        if (is_object($bill)) {
            return $bill->{$key} ?? $default;
        }

        return $default;
    }
}
