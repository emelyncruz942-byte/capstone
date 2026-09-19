<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1a1a2e; background: #fff; }
        .header { background: #0a0a0f; color: #fff; padding: 20px 30px; margin-bottom: 24px; }
        .header h1 { font-size: 22px; font-weight: 900; letter-spacing: 2px; }
        .header h1 span { color: #00f2ff; }
        .header p { font-size: 10px; color: #94a3b8; margin-top: 4px; letter-spacing: 1px; text-transform: uppercase; }
        .content { padding: 0 30px 30px; }
        .report-title { font-size: 16px; font-weight: 700; color: #0a0a0f; margin-bottom: 16px; padding-bottom: 8px; border-bottom: 2px solid #00f2ff; text-transform: uppercase; letter-spacing: 1px; }
        .meta { font-size: 10px; color: #64748b; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        thead tr { background: #0a0a0f; color: #fff; }
        thead th { padding: 10px 12px; text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: 1px; }
        tbody tr:nth-child(even) { background: #f8fafc; }
        tbody tr:nth-child(odd)  { background: #fff; }
        tbody td { padding: 9px 12px; border-bottom: 1px solid #e2e8f0; font-size: 10px; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 9px; font-weight: 700; text-transform: uppercase; }
        .badge-student { background: #cffafe; color: #0e7490; }
        .badge-teacher { background: #ede9fe; color: #6d28d9; }
        .badge-admin   { background: #fee2e2; color: #991b1b; }
        .badge-pending { background: #ffedd5; color: #9a3412; }
        .summary-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 24px; }
        .summary-card { background: #f8fafc; border: 1px solid #e2e8f0; border-left: 4px solid #00f2ff; padding: 14px 16px; border-radius: 4px; }
        .summary-card .label { font-size: 9px; text-transform: uppercase; letter-spacing: 1px; color: #64748b; font-weight: 700; }
        .summary-card .value { font-size: 22px; font-weight: 900; color: #0a0a0f; margin-top: 4px; }
        .footer { margin-top: 30px; padding-top: 12px; border-top: 1px solid #e2e8f0; font-size: 9px; color: #94a3b8; text-align: center; }
        .accent { color: #00f2ff; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
    </style>
</head>
<body>
    <div class="header">
        <h1>MATH<span>VERSE</span></h1>
        <p>@yield('report-subtitle', 'Official Report')</p>
    </div>
    <div class="content">
        @yield('report-content')
        <div class="footer">
            MathVerse Academic Platform &nbsp;|&nbsp; @yield('report-name') &nbsp;|&nbsp; Generated: @yield('generated')
        </div>
    </div>
</body>
</html>