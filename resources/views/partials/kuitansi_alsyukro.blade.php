<script>
    (function (window) {
        const BULAN = [
            'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
            'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
        ];

        function formatPeriodeLabel(periode) {
            const raw = (periode == null ? '' : String(periode)).trim();
            if (!raw) return '';
            if (/^\d{6}$/.test(raw)) {
                const month = parseInt(raw.substring(4, 6), 10);
                if (month >= 1 && month <= 12) {
                    return BULAN[month - 1] + ' ' + raw.substring(0, 4);
                }
            }
            return raw;
        }

        function formatMoneyPlain(amount) {
            const value = Number(amount) || 0;
            return value.toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            });
        }

        function formatTanggalShort(value) {
            if (!value || value === '0000-00-00 00:00:00') {
                return new Date().toLocaleDateString('en-GB', {
                    day: '2-digit', month: 'short', year: 'numeric'
                });
            }
            const parsed = new Date(value);
            if (Number.isNaN(parsed.getTime())) {
                return String(value);
            }
            return parsed.toLocaleDateString('en-GB', {
                day: '2-digit', month: 'short', year: 'numeric'
            });
        }

        function terbilang(angka) {
            const nilai = Math.floor(Math.abs(Number(angka) || 0));
            if (nilai === 0) return 'Nol Rupiah';

            const satuan = ['', 'Satu', 'Dua', 'Tiga', 'Empat', 'Lima', 'Enam', 'Tujuh', 'Delapan', 'Sembilan', 'Sepuluh', 'Sebelas'];

            function toWords(n) {
                n = Math.floor(n);
                if (n < 12) return satuan[n];
                if (n < 20) return satuan[n - 10] + ' Belas';
                if (n < 100) return satuan[Math.floor(n / 10)] + ' Puluh' + (n % 10 ? ' ' + satuan[n % 10] : '');
                if (n < 200) return 'Seratus' + (n % 100 ? ' ' + toWords(n % 100) : '');
                if (n < 1000) return satuan[Math.floor(n / 100)] + ' Ratus' + (n % 100 ? ' ' + toWords(n % 100) : '');
                if (n < 2000) return 'Seribu' + (n % 1000 ? ' ' + toWords(n % 1000) : '');
                if (n < 1000000) return toWords(Math.floor(n / 1000)) + ' Ribu' + (n % 1000 ? ' ' + toWords(n % 1000) : '');
                if (n < 1000000000) return toWords(Math.floor(n / 1000000)) + ' Juta' + (n % 1000000 ? ' ' + toWords(n % 1000000) : '');
                if (n < 1000000000000) return toWords(Math.floor(n / 1000000000)) + ' Miliar' + (n % 1000000000 ? ' ' + toWords(n % 1000000000) : '');
                return toWords(Math.floor(n / 1000000000000)) + ' Triliun' + (n % 1000000000000 ? ' ' + toWords(n % 1000000000000) : '');
            }

            return (toWords(nilai) + ' Rupiah').replace(/\s+/g, ' ').trim();
        }

        function deskripsiPembayaran(item) {
            const nama = String(item.BILLNM || item.billnm || '-').trim();
            const periode = formatPeriodeLabel(item.BILLAC || item.BTA || item.billac || item.bta || '');
            if (!periode) return nama;
            // "SPP Bulan Agustus 2026"
            if (/bulan/i.test(nama)) return nama + ' ' + periode;
            return nama + ' Bulan ' + periode;
        }

        function padFaktur(value, width) {
            const digits = String(value == null ? '' : value).replace(/\D/g, '');
            if (!digits) return '-';
            return digits.padStart(width || 8, '0');
        }

        /**
         * Body kuitansi gaya Al Syukro (BUKTI PEMBAYARAN UANG SEKOLAH).
         * options: { siswa, tagihans, bank, biayaLayanan, nomor, tanggal, tandaTangan, userName, formatMetode }
         */
        window.buildKuitansiAlSyukroBody = function (options) {
            options = options || {};
            const siswa = options.siswa || {};
            const tagihans = Array.isArray(options.tagihans) ? options.tagihans : [];
            const formatMetode = typeof options.formatMetode === 'function'
                ? options.formatMetode
                : function (v) { return v || '-'; };

            const namaSiswa = siswa.NMCUST || siswa.nmcust || '-';
            const kelas = String((siswa.DESC02 || '') + ' ' + (siswa.DESC03 || '')).trim() || '-';
            const unit = siswa.CODE02 || siswa.code02 || '-';
            const first = tagihans[0] || {};
            const paymentBank = options.bank || first.FIDBANK || '';
            const biayaLayanan = Number(options.biayaLayanan || 0);

            let totalNilai = 0;
            tagihans.forEach(function (item) {
                totalNilai += Number(
                    item.NOMINAL_BAYAR !== undefined && item.NOMINAL_BAYAR !== null
                        ? item.NOMINAL_BAYAR
                        : (item.BILLPAID || item.BILLAM || 0)
                );
            });

            const tanggalSumber = options.tanggal || first.PAIDDT || first.PAIDDT_ISO || new Date();
            const tanggalLabel = formatTanggalShort(tanggalSumber);
            const nomor = options.nomor
                || first.TRANSNO
                || first.BILL_TRANSNO
                || first.NOREFF
                || first.BILLCD
                || ('CR' + padFaktur(first.AA || siswa.CUSTID || '', 6));

            const hLineOnly = {
                hLineWidth: function (i, node) {
                    if (i === 0 || i === 1 || i === node.table.body.length) return 1;
                    return 0.4;
                },
                vLineWidth: function () { return 0; },
                paddingLeft: function () { return 4; },
                paddingRight: function () { return 4; },
                paddingTop: function () { return 4; },
                paddingBottom: function () { return 4; },
            };

            const infoTable = {
                table: {
                    widths: ['22%', '33%', '15%', '30%'],
                    body: [
                        [
                            { text: 'Telah Diterima Dari', bold: false },
                            { text: ': ' + namaSiswa },
                            { text: 'Nomor' },
                            { text: ': ' + nomor },
                        ],
                        [
                            { text: 'Kelas' },
                            { text: ': ' + kelas },
                            { text: 'Tanggal' },
                            { text: ': ' + tanggalLabel },
                        ],
                        [
                            { text: 'Unit' },
                            { text: ': ' + unit },
                            { text: '' },
                            { text: '' },
                        ],
                    ],
                },
                layout: 'noBorders',
                margin: [0, 4, 0, 8],
                fontSize: 10,
            };

            const tableBody = [[
                { text: 'Faktur No.', bold: true },
                { text: 'Untuk Pembayaran', bold: true },
                { text: 'Discount', bold: true, alignment: 'right' },
                { text: 'Nilai', bold: true, alignment: 'right' },
            ]];

            tagihans.forEach(function (item) {
                const nilai = Number(
                    item.NOMINAL_BAYAR !== undefined && item.NOMINAL_BAYAR !== null
                        ? item.NOMINAL_BAYAR
                        : (item.BILLPAID || item.BILLAM || 0)
                );
                tableBody.push([
                    { text: padFaktur(item.AA || item.BILLCD || '', 8) },
                    { text: deskripsiPembayaran(item) },
                    { text: formatMoneyPlain(0), alignment: 'right' },
                    { text: formatMoneyPlain(nilai), alignment: 'right' },
                ]);
            });

            if (!tagihans.length) {
                tableBody.push([
                    { text: '-' },
                    { text: '-' },
                    { text: formatMoneyPlain(0), alignment: 'right' },
                    { text: formatMoneyPlain(0), alignment: 'right' },
                ]);
            }

            const detailTable = {
                table: {
                    widths: ['18%', '*', '18%', '22%'],
                    body: tableBody,
                },
                layout: hLineOnly,
                margin: [0, 0, 0, 10],
                fontSize: 10,
            };

            const grandTotal = totalNilai + biayaLayanan;
            const tandaTangan = options.tandaTangan
                || (window.PDF_KOP_ASSETS && window.PDF_KOP_ASSETS.ttdLogo)
                || null;
            const userName = @json(config('app.nama_ttd_kuitansi', 'Cut Tia Andani, S.Ak.'));

            const footerBlock = {
                columns: [
                    {
                        width: '55%',
                        stack: [
                            { text: terbilang(grandTotal), italics: true, margin: [0, 0, 0, 16] },
                            tandaTangan
                                ? { image: tandaTangan, width: 90, margin: [0, 0, 0, 2] }
                                : { text: '\n\n\n', margin: [0, 0, 0, 2] },
                            {
                                text: userName,
                                decoration: 'underline',
                                margin: [0, 0, 0, 0],
                            },
                        ],
                    },
                    {
                        width: '45%',
                        table: {
                            widths: ['55%', '45%'],
                            body: [
                                [
                                    { text: 'Sub Total', alignment: 'left' },
                                    { text: 'Rp ' + formatMoneyPlain(totalNilai), alignment: 'right' },
                                ],
                                [
                                    { text: 'Biaya Lainnya', alignment: 'left' },
                                    { text: formatMoneyPlain(biayaLayanan), alignment: 'right' },
                                ],
                                [
                                    {
                                        text: 'Total',
                                        bold: true,
                                        alignment: 'left',
                                        border: [false, true, false, false],
                                        margin: [0, 4, 0, 0],
                                    },
                                    {
                                        text: formatMoneyPlain(grandTotal),
                                        bold: true,
                                        alignment: 'right',
                                        border: [false, true, false, false],
                                        margin: [0, 4, 0, 0],
                                    },
                                ],
                            ],
                        },
                        layout: {
                            hLineWidth: function (i) { return i === 2 ? 1 : 0; },
                            vLineWidth: function () { return 0; },
                            paddingLeft: function () { return 2; },
                            paddingRight: function () { return 2; },
                            paddingTop: function () { return 2; },
                            paddingBottom: function () { return 2; },
                        },
                        fontSize: 10,
                    },
                ],
                columnGap: 12,
                margin: [0, 4, 0, 0],
            };

            // unused but kept for future (metode label if needed)
            void formatMetode;
            void paymentBank;

            return [infoTable, detailTable, footerBlock];
        };

        window.KUITANSI_ALSYUKRO_TITLE = 'BUKTI PEMBAYARAN UANG SEKOLAH';
    })(window);
</script>
