@php
    $pdfLogoPath = public_path(config('app.logo'));
    $pdfLogoMime = config('app.logo_mime');
    $pdfLogoB64 = is_file($pdfLogoPath) ? base64_encode(file_get_contents($pdfLogoPath)) : '';

    $pdfDompetPath = public_path(config('app.logo_dompet_dhuafa', 'logo-dompet-dhuafa.png'));
    $pdfDompetB64 = is_file($pdfDompetPath) ? base64_encode(file_get_contents($pdfDompetPath)) : '';

    $pdfEdunesiaPath = public_path(config('app.logo_great_edunesia', 'great-edunesia.png'));
    $pdfEdunesiaB64 = is_file($pdfEdunesiaPath) ? base64_encode(file_get_contents($pdfEdunesiaPath)) : '';
@endphp
<script>
    window.PDF_KOP_ASSETS = {
        schoolLogo: @json($pdfLogoB64 ? ('data:' . $pdfLogoMime . ';base64,' . $pdfLogoB64) : ''),
        dompetLogo: @json($pdfDompetB64 ? ('data:image/png;base64,' . $pdfDompetB64) : ''),
        edunesiaLogo: @json($pdfEdunesiaB64 ? ('data:image/png;base64,' . $pdfEdunesiaB64) : ''),
        namaInstansi: @json((string) config('app.nama_instansi')),
        namaSub1: @json((string) config('app.nama_sub_instansi_1')),
        namaSub2: @json((string) config('app.nama_sub_instansi_2')),
        akreditasi: @json((string) config('app.akreditasi')),
        alamat: @json((string) config('app.alamat')),
    };

    /**
     * Kop surat pdfmake: logo sekolah | nama+alamat | Dompet Dhuafa + Great Edunesia
     */
    window.buildPdfKopHeader = function (leftLogo) {
        const assets = window.PDF_KOP_ASSETS || {};
        const school = leftLogo || assets.schoolLogo || '';
        const stack = [];

        if (assets.namaSub1) {
            stack.push({ text: String(assets.namaSub1).toUpperCase(), style: 'headerSmall' });
        }
        if (assets.namaSub2) {
            stack.push({ text: String(assets.namaSub2).toUpperCase(), style: 'headerSmall' });
        }
        stack.push({
            text: String(assets.namaInstansi || '').toUpperCase(),
            style: 'headerBig',
            margin: [0, 0, 0, 2],
        });
        if (assets.akreditasi) {
            stack.push({ text: assets.akreditasi, style: 'headerSmall' });
        }
        if (assets.alamat) {
            stack.push({ text: assets.alamat, style: 'headerSmall', margin: [0, 2, 0, 0] });
        }

        const partnerLogos = [];
        if (assets.dompetLogo) {
            partnerLogos.push({
                image: assets.dompetLogo,
                width: 52,
                alignment: 'center',
                margin: [0, 0, 4, 0],
            });
        }
        if (assets.edunesiaLogo) {
            partnerLogos.push({
                image: assets.edunesiaLogo,
                width: 52,
                alignment: 'center',
                margin: [4, 0, 0, 0],
            });
        }

        return {
            alignment: 'center',
            table: {
                widths: [70, '*', partnerLogos.length ? 120 : 70],
                body: [[
                    school
                        ? { image: school, width: 58, alignment: 'center', margin: [0, 2, 0, 0] }
                        : { text: '', width: 58 },
                    {
                        stack: stack,
                        alignment: 'center',
                        margin: [4, 0, 4, 0],
                    },
                    partnerLogos.length
                        ? {
                            columns: partnerLogos,
                            columnGap: 4,
                            alignment: 'center',
                            margin: [0, 8, 0, 0],
                        }
                        : { text: '' },
                ]],
            },
            layout: 'noBorders',
            margin: [0, 0, 0, 4],
        };
    };
</script>
