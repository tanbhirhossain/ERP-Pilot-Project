<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<style>
    @page { margin: 16mm 13mm 16mm 13mm; }
    * { font-family: 'DejaVu Sans', sans-serif; font-size: 9pt; color: #1a2433; }
    .letterhead { width: 100%; border-collapse: collapse; }
    .company-name { font-size: 16pt; font-weight: bold; color: #1e3a5f; letter-spacing: 0.5px; }
    .company-sub { font-size: 7.5pt; color: #5a6b80; margin-top: 3px; line-height: 1.5; }
    .doc-title { font-size: 13pt; font-weight: bold; color: #1e3a5f; text-transform: uppercase; }
    .doc-no { font-size: 10pt; font-weight: bold; margin-top: 2px; }
    .doc-status { display: inline-block; margin-top: 3px; font-size: 7pt; font-weight: bold; letter-spacing: 1px;
                  border: 1px solid #1e3a5f; color: #1e3a5f; padding: 1px 7px; border-radius: 8px; }
    .rule { border-top: 2.5px solid #1e3a5f; margin: 8px 0 10px 0; }
    .meta { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
    .meta td { padding: 2.5px 5px; font-size: 8.5pt; vertical-align: top; }
    .meta .lbl { color: #5a6b80; width: 16%; }
    .meta .val { font-weight: bold; width: 34%; }
    table.items { width: 100%; border-collapse: collapse; margin-top: 4px; }
    table.items th { background: #1e3a5f; color: #ffffff; font-size: 7.5pt; text-transform: uppercase;
                     letter-spacing: 0.4px; padding: 5px 4px; border: 1px solid #1e3a5f; text-align: left; }
    table.items td { padding: 4.5px 4px; border: 1px solid #b9c4d0; font-size: 8.5pt; }
    table.items tr:nth-child(even) td { background: #f3f6f9; }
    .r { text-align: right; }
    .c { text-align: center; }
    .totals { width: 46%; border-collapse: collapse; margin-top: 8px; margin-left: 54%; }
    .totals td { padding: 3px 5px; font-size: 8.5pt; border-bottom: 1px solid #d7dee6; }
    .totals .grand td { font-size: 10pt; font-weight: bold; border-top: 2px solid #1e3a5f; border-bottom: none; color: #1e3a5f; }
    .notes { margin-top: 10px; font-size: 8pt; color: #5a6b80; }
    .signatures { width: 100%; margin-top: 42px; border-collapse: collapse; }
    .signatures td { text-align: center; vertical-align: bottom; }
    .sign-box { display: inline-block; width: 150px; border-top: 1px solid #33475e; padding-top: 4px; font-size: 8pt; color: #33475e; }
    .footer { width: 100%; margin-top: 18px; border-top: 1px solid #d7dee6; padding-top: 6px; border-collapse: collapse; }
    .footer td { font-size: 7pt; color: #8593a5; vertical-align: middle; }
</style>
</head>
<body>
    <table class="letterhead">
        <tr>
            <td width="58%">
                <div class="company-name">{{ $company['name'] }}</div>
                <div class="company-sub">
                    {{ $company['address'] }}<br>
                    BIN: {{ $company['bin'] }} &nbsp;·&nbsp; {{ $company['phone'] }} &nbsp;·&nbsp; {{ $company['email'] }}
                </div>
            </td>
            <td class="r">
                <div class="doc-title">{{ $title }}</div>
                <div class="doc-no">{{ $docNo }}</div>
                @if(!empty($status))<div class="doc-status">{{ strtoupper(str_replace('_', ' ', $status)) }}</div>@endif
            </td>
        </tr>
    </table>
    <div class="rule"></div>

    <table class="meta">
        @foreach(array_chunk($meta, 2, true) as $pair)
            <tr>
                @foreach($pair as $label => $value)
                    <td class="lbl">{{ $label }}</td><td class="val">{{ $value }}</td>
                @endforeach
                @if(count($pair) === 1)<td class="lbl"></td><td class="val"></td>@endif
            </tr>
        @endforeach
    </table>

    @yield('body')

    <table class="signatures">
        <tr>
            @foreach($signatures as $sig)
                <td><div class="sign-box">{{ $sig }}</div></td>
            @endforeach
        </tr>
    </table>

    <table class="footer">
        <tr>
            <td width="60"><img src="data:image/png;base64,{{ $qr }}" width="52" height="52" alt="QR"></td>
            <td>
                Generated {{ $generatedAt }} by {{ $generatedBy }} · {{ $company['name'] }}<br>
                System-generated document — scan the QR to verify the reference. No signature is required for electronic copies issued through the ERP.
            </td>
        </tr>
    </table>
</body>
</html>
