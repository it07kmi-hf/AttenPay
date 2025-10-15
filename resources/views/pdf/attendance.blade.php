<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <style>
    @page { margin: 24px; }
    body {
      font-family: DejaVu Sans, Arial, sans-serif;
      font-size: 11px;
      color: #111;
      background: #f8fafc;
    }

    h1 {
      font-size: 18px;
      margin: 0;
      color: #0f254a;
    }

    .subtitle {
      font-size: 11px;
      color: #5b6b80;
      margin-top: 3px;
    }

    .meta {
      font-size: 11px;
      color: #6b7280;
      margin-top: 6px;
    }

    .brand-wrap {
      width: 95%;
      margin: 0 auto 20px 10px; /* <== digeser sedikit ke kiri */
      background: #fff;
      border: 1px solid #dbeafe;
      border-radius: 8px;
      padding: 16px 18px;
      box-shadow: 0 2px 6px rgba(0,0,0,0.05);
    }

    .brand {
      width: 100%;
      border-bottom: 2px solid #dbeafe;
      padding-bottom: 10px;
      margin-bottom: 14px;
    }

    .brand td {
      vertical-align: middle;
    }

    .brand .side {
      width: 80px;
    }

    .brand .center {
      text-align: center;
    }

    .logo {
      width: 56px;
      height: 56px;
      object-fit: contain;
    }

    table {
      width: 100%;
      border-collapse: collapse;
      border-radius: 8px;
      overflow: hidden;
      box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    }

    th, td {
      border: 1px solid #dbeafe;
      padding: 8px 10px;
    }

    th {
      background: linear-gradient(to bottom, #e9f2ff, #dbeafe);
      text-align: left;
      font-weight: 700;
      color: #0f254a;
      font-size: 11.5px;
    }

    td {
      background: #ffffff;
    }

    tr:nth-child(even) td {
      background: #f9fbff;
    }

    tfoot th, tfoot td {
      background: #eef6ff;
      font-weight: 700;
      border-top: 2px solid #93c5fd;
    }

    .right { text-align: right; }

    .chip {
      display:inline-block;
      padding:2px 6px;
      border-radius:999px;
      font-size:10px;
      font-weight:700;
    }

    .chip-ok { background:#dcfce7; color:#166534; }
    .chip-dim { background:#f1f5f9; color:#334155; }

    /* Footer */
    .footer {
      margin-top: 12px;
      text-align: center;
      font-size: 10px;
      color: #6b7280;
    }

    /* Page footer text for DomPDF */
    .page-number {
      font-size: 9px;
      color: #6b7280;
      text-align: right;
    }
  </style>
</head>
<body>
@php
  $HOURLY_RATE = 28298;
  $calcDailyTotal = function ($work) use ($HOURLY_RATE) {
      $h = max(0, (float)($work ?? 0));
      $billable = min($h, 7);
      return (int) round($billable * $HOURLY_RATE);
  };
  $fmtRupiah = fn($n) => 'Rp ' . number_format((float)$n, 0, ',', '.');
  $fmtDate = fn($d) => $d ? substr((string)$d, 0, 10) : '-';
  $fmtTime = fn($t) => $t && strlen($t) >= 5 ? substr((string)$t, 0, 5) : ($t ?: '-');

  $logoPathFs = public_path('img/logo-kmi.png');
  $logoExists = file_exists($logoPathFs);

  $sumWork = $sumDaily = $sumOTHours = $sumOT1 = $sumOT2 = $sumOTTotal = 0;
  foreach ($rows as $r) {
    $work  = (float)($r->real_work_hour ?? 0);
    $otH   = (float)($r->overtime_hours ?? 0);
    $ot1   = (float)($r->overtime_first_amount ?? 0);
    $ot2   = (float)($r->overtime_second_amount ?? 0);
    $otTot = (float)($r->overtime_total_amount ?? 0);
    $sumWork += $work;
    $sumDaily += $calcDailyTotal($work);
    $sumOTHours += $otH;
    $sumOT1 += $ot1;
    $sumOT2 += $ot2;
    $sumOTTotal += $otTot;
  }
@endphp

<div class="brand-wrap">
  <table class="brand" cellspacing="0" cellpadding="0">
    <tr>
      <td class="side">
        @if($logoExists)
          <img src="{{ $logoPathFs }}" alt="KMI Logo" class="logo">
        @else
          <div style="width:56px;height:56px;border:1px solid #e5e7eb;border-radius:8px;display:flex;align-items:center;justify-content:center;font-weight:bold;background:#f9fafb;">
            KMI
          </div>
        @endif
      </td>
      <td class="center">
        <h1>Attendance Payroll — PT Kayu Mebel Indonesia</h1>
        <div class="subtitle">Employee Attendance & Overtime Recap</div>
        <div class="meta">
          Period: <b>{{ $from }}</b> → <b>{{ $to }}</b> • Generated: {{ now()->format('Y-m-d H:i') }}
        </div>
      </td>
      <td class="side"></td>
    </tr>
  </table>

  <table>
    <thead>
      <tr>
        <th>Date</th>
        <th>Employee ID</th>
        <th>Name</th>
        <th>Clock In</th>
        <th>Clock Out</th>
        <th class="right">Work Hour</th>
        <th class="right">Base Salary</th>
        <th class="right">OT Hours</th>
        <th class="right">OT 1 (1.5×)</th>
        <th class="right">OT 2 (2×)</th>
        <th class="right">OT Total</th>
      </tr>
    </thead>
    <tbody>
      @foreach ($rows as $r)
        @php
          $work  = (float)($r->real_work_hour ?? 0);
          $otH   = (float)($r->overtime_hours ?? 0);
          $ot1   = (float)($r->overtime_first_amount ?? 0);
          $ot2   = (float)($r->overtime_second_amount ?? 0);
          $otTot = (float)($r->overtime_total_amount ?? 0);
          $daily = $calcDailyTotal($work);
        @endphp
        <tr>
          <td>{{ $fmtDate($r->schedule_date) }}</td>
          <td>{{ $r->employee_id }}</td>
          <td>{{ $r->full_name }}</td>
          <td>{{ $fmtTime($r->clock_in) }}</td>
          <td>{{ $fmtTime($r->clock_out) }}</td>
          <td class="right">
            @if($work >= 7)
              <span class="chip chip-ok">{{ number_format($work, 2, ',', '.') }} h</span>
            @else
              <span class="chip chip-dim">{{ number_format($work, 2, ',', '.') }} h</span>
            @endif
          </td>
          <td class="right">{{ $fmtRupiah($daily) }}</td>
          <td class="right">{{ number_format($otH, 0, ',', '.') }}</td>
          <td class="right">{{ $fmtRupiah($ot1) }}</td>
          <td class="right">{{ $fmtRupiah($ot2) }}</td>
          <td class="right">{{ $fmtRupiah($otTot) }}</td>
        </tr>
      @endforeach
    </tbody>

    <tfoot>
      <tr>
        <th colspan="5" class="right">Grand Total</th>
        <th class="right">{{ number_format($sumWork, 2, ',', '.') }}</th>
        <th class="right">{{ $fmtRupiah($sumDaily) }}</th>
        <th class="right">{{ number_format($sumOTHours, 0, ',', '.') }}</th>
        <th class="right">{{ $fmtRupiah($sumOT1) }}</th>
        <th class="right">{{ $fmtRupiah($sumOT2) }}</th>
        <th class="right">{{ $fmtRupiah($sumOTTotal) }}</th>
      </tr>
    </tfoot>
  </table>

  <div class="footer">
    <p><b>PT Kayu Mebel Indonesia</b> — Automated Payroll System</p>
    <p>Generated on {{ now()->format('Y-m-d H:i') }}</p>
  </div>
</div>

<script type="text/php">
if (isset($pdf)) {
  $pdf->page_text(520, 810, "Page {PAGE_NUM}/{PAGE_COUNT}", null, 8, [0.4,0.4,0.4]);
}
</script>
</body>
</html>
