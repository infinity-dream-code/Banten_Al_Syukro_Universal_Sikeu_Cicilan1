@php use Carbon\Carbon; @endphp
    <!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>@yield('title')</title>

    <style type="text/css">
        @page { margin: 30px; }

        * {
            font-family: Verdana, Arial, sans-serif;
        }

        table {
            font-size: small;
        }

        tfoot tr td {
            font-weight: bold;
            font-size: small;
        }

        .table-border {
            border-collapse: collapse;
        }

        .table-border td, th {
            border: 2px solid gray;
            padding: 8px;
        }

        .border-bottom {
            border-collapse: collapse;
            border-bottom: 0.3rem double #000;
            text-align: left;
            padding: 8px;
        }

        .border-top-0 {
            border-top: 0 !important
        }

        .border-bottom-0 {
            border-bottom: 0 !important
        }

        .border-left-0 {
            border-left: 0 !important
        }

        .border-right-0 {
            border-right: 0 !important
        }

        h1, h2, h3, h4, h5, h6, .h1, .h2, .h3, .h4, .h5, .h6 {
            margin-top: 0;
            margin-bottom: 0;
        }

        .double-hr-alt {
            margin: 10px 0;
        }

        .line {
            width: 100%;
            height: 2px;
            background: black;
        }

        .line.bold {
            height: 4px; /* Bolder line */
            margin-bottom: 2px;
        }

        .line.thin {
            height: 1px; /* Thinner line */
            background: gray;
        }

        .breakable {
            display: inline-block;
            max-width: 70ch; /* Limit to 20 characters */
            overflow-wrap: break-word; /* Break long words */
            word-break: break-word; /* Ensure text wraps nicely */
            white-space: normal;
        }

        .subheader{
            font-size: 10pt;
        }

        table.main-table {
            font-size: 10pt;
        }
        table.main-table td,
        table.main-table th {
            font-size: inherit;
        }

        .text-end {
            text-align: right !important;
        }
    </style>
    @yield('style')
</head>
<body>

@php
    $logoPath = public_path(config('app.logo'));
    $logoMime = config('app.logo_mime');
    $logoSchool = is_file($logoPath)
        ? 'data:' . $logoMime . ';base64,' . base64_encode(file_get_contents($logoPath))
        : null;

    $dompetPath = public_path(config('app.logo_dompet_dhuafa', 'logo-dompet-dhuafa.png'));
    $logoDompet = is_file($dompetPath)
        ? 'data:image/png;base64,' . base64_encode(file_get_contents($dompetPath))
        : null;

    $edunesiaPath = public_path(config('app.logo_great_edunesia', 'great-edunesia.png'));
    $logoEdunesia = is_file($edunesiaPath)
        ? 'data:image/png;base64,' . base64_encode(file_get_contents($edunesiaPath))
        : null;
@endphp
<table width="100%" class="">
    <tr>
        <td width="18%" style="text-align: center; vertical-align: middle;">
            @if($logoSchool)
                <img src="{{ $logoSchool }}" style="max-height: 4.5rem; display: block; margin: auto;" alt="logo"/>
            @endif
        </td>
        <td width="54%" style="vertical-align: middle;">
            <table style="width: 100%;">
                <tr>
                    <td align="center">
                        @if(config('app.nama_sub_instansi_1'))<h3>{{config('app.nama_sub_instansi_1')}}</h3>@endif
                        @if(config('app.nama_sub_instansi_2'))<h3>{{config('app.nama_sub_instansi_2')}}</h3>@endif
                        <h2 style="margin: 0;">{{config('app.nama_instansi')}}</h2>
                    </td>
                </tr>
                @if(config('app.akreditasi'))
                    <tr>
                        <td align="center">
                            <h3>{{config('app.akreditasi')}}</h3>
                        </td>
                    </tr>
                @endif
                <tr style="font-size: 10pt;">
                    <td align="center">
                        {{config('app.alamat')}}
                    </td>
                </tr>
            </table>
        </td>
        <td width="28%" style="text-align: center; vertical-align: middle;">
            <table width="100%">
                <tr>
                    @if($logoDompet)
                        <td align="center" style="padding: 0 4px;">
                            <img src="{{ $logoDompet }}" style="max-height: 2.4rem; max-width: 5.2rem;" alt="Dompet Dhuafa"/>
                        </td>
                    @endif
                    @if($logoEdunesia)
                        <td align="center" style="padding: 0 4px;">
                            <img src="{{ $logoEdunesia }}" style="max-height: 2.4rem; max-width: 5.2rem;" alt="Great Edunesia"/>
                        </td>
                    @endif
                </tr>
            </table>
        </td>
    </tr>
</table>
<div class="double-hr-alt">
    <div class="line bold"></div>
    <div class="line thin"></div>
</div>
@yield('content')
@hasSection('hide_default_footer')
@else
<table class="main-table" style="width: 100%; padding-top: 0.425rem">
    <tfoot>
    <tr>
        <td colspan="5"></td>
        <td style="color: #fff;">-</td>
        <td align="right">{{config('app.domisili')}}, {{Carbon::now()->isoFormat('dddd, D MMMM YYYY')}}</td>
    </tr>
    <tr>
        <td colspan="5" style="color: #fff;">TESTING</td>
        <td style="color: #fff;">TESTING</td>
        <td align="right">ADMIN</td>
    </tr>
    </tfoot>
</table>
@endif

</body>
</html>
