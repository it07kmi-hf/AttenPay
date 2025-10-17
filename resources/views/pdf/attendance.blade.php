<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <style>
    @page { margin: 24px; }
    body {
      font-family: DejaVu Sans, Arial, sans-serif;
      font-size: 10px;
      color: #111;
      background: #f8fafc;
    }

    h1 { font-size: 17px; margin: 0; color: #0f254a; }
    .subtitle { font-size: 10px; color: #5b6b80; margin-top: 3px; }
    .meta { font-size: 10px; color: #6b7280; margin-top: 6px; }

    .brand-wrap{
      width:95%; margin:0 auto 16px 10px;
      background:#fff;
      border:1.2px solid #7aa2ff;
      border-radius:8px;
      padding:14px 16px;
    }
    .brand{ width:100%; border-bottom:2px solid #7aa2ff; padding-bottom:8px; margin-bottom:12px; }
    .brand td{ vertical-align:middle; }
    .brand .side{ width:78px; }
    .brand .center{ text-align:center; }
    .logo{ width:54px; height:54px; object-fit:contain; }

    /* ===== TABEL ===== */
    table{
      width:100%;
      border-collapse:collapse;
      table-layout:auto;
      border:1.2px solid #7aa2ff;
    }
    th,td{
      border:1.1px solid #7aa2ff;
      padding:6px 8px;
      text-align:left;
      white-space:nowrap;
      line-height:1.22;
      vertical-align:middle;
      font-size:10px;
    }

    thead th{
      background:#d7e9ff;
      color:#0f254a;
      font-weight:700;
      border-bottom:2px solid #7aa2ff;
    }
    tfoot th, tfoot td{
      background:#d7e9ff;
      font-weight:700;
      border-top:2px solid #7aa2ff;
    }

    tbody tr:nth-child(even) td { background:#f4f8ff; }
    tbody tr:nth-child(odd)  td { background:#ffffff; }

    .chip{
      display:inline-block; padding:1px 5px; border-radius:999px;
      font-size:9px; font-weight:700;
    }
    .chip-ok  { background:#dcfce7; color:#166534; }
    .chip-dim { background:#f1f5f9; color:#334155; }

    .footer{ margin-top:10px; text-align:center; font-size:9.5px; color:#6b7280; }
  </style>
</head>
<body>
@php
  // ===== Helper & Fallback =====
  $FALLBACK_RATE = 28153;

  // Bersihkan branch_name: ambil bagian sebelum " – ...", " — ...", atau "- ..."
  $cleanBranchName = function ($name) {
      if (empty($name)) return 'PT Kayu Mebel Indonesia (KMI)';
      $parts = preg_split('/\s[–—-]\s/u', (string)$name);
      $base  = trim($parts[0] ?? '');
      return $base !== '' ? $base : 'PT Kayu Mebel Indonesia (KMI)';
  };

  // Ambil brand title dari baris pertama jika ada; kalau tidak, fallback
  $firstRow   = (isset($rows) && count($rows)) ? (is_array($rows) ? $rows[0] : $rows->first()) : null;
  $brandTitle = $cleanBranchName($firstRow->branch_name ?? 'PT Kayu Mebel Indonesia (KMI)');

  $safeNum = function ($v, $def = 0) {
      $n = is_null($v) ? $def : (float)$v;
      return is_finite($n) ? $n : $def;
  };

  // === Deteksi hadir: harus ada jam kerja > 0 + clock in & out terisi ===
  $isPresent = function ($row) use ($safeNum) {
      $cin   = trim((string)($row->clock_in  ?? ''));
      $cout  = trim((string)($row->clock_out ?? ''));
      $hours = max(0, $safeNum($row->real_work_hour, 0));
      return ($cin !== '' && $cout !== '' && $hours > 0);
  };

  // Rate/jam dipakai HANYA jika hadir (kalau tidak → 0; tidak pakai fallback)
  $hourlyRate = function ($row) use ($safeNum, $isPresent) {
      if (!$isPresent($row)) return 0;
      return (int) max(0, $safeNum($row->hourly_rate_used, 0));
  };

  // Jam billable: 0 jika tidak hadir; kalau hadir pakai daily_billable_hours atau min(7, real_work_hour)
  $billableHours = function ($row) use ($safeNum, $isPresent) {
      if (!$isPresent($row)) return 0;
      if (!is_null($row->daily_billable_hours)) {
          $b = max(0, $safeNum($row->daily_billable_hours, 0));
      } else {
          $b = min(7, max(0, $safeNum($row->real_work_hour, 0)));
      }
      return round($b, 2);
  };

  // OT per pecahan (0 kalau tidak hadir)
  $otHours = fn($row) => $isPresent($row) ? (int)max(0, (float)($row->overtime_hours ?? 0)) : 0;
  $otFirst = fn($row) => $isPresent($row) ? (int)max(0, (float)($row->overtime_first_amount ?? 0)) : 0;
  $otSecond= fn($row) => $isPresent($row) ? (int)max(0, (float)($row->overtime_second_amount ?? 0)) : 0;
  $otTotal = fn($row) => $isPresent($row) ? (int)max(0, (float)($row->overtime_total_amount ?? 0)) : 0;

  // Basic Salary = billable * hourlyRate (0 kalau tidak hadir)
  $basicSalary = function ($row) use ($hourlyRate, $billableHours) {
      return (int) round($billableHours($row) * $hourlyRate($row));
  };

  // Total harian:
  // - Jika daily_total_amount numeric → gunakan apa adanya (legacy-safe)
  // - Jika NULL → hadir: base + OT; tidak hadir: 0
  $dailyFinal = function ($row) use ($basicSalary, $otTotal, $isPresent) {
      if (!is_null($row->daily_total_amount) && is_numeric($row->daily_total_amount)) {
          return (int)$row->daily_total_amount;
      }
      return $isPresent($row) ? ($basicSalary($row) + $otTotal($row)) : 0;
  };

  $fmtRupiah = fn($n) => 'Rp ' . number_format((float)$n, 0, ',', '.');
  $fmtDate   = fn($d) => $d ? substr((string)$d, 0, 10) : '-';
  $fmtTime   = fn($t) => $t && strlen($t) >= 5 ? substr((string)$t, 0, 5) : ($t ?: '-');

  $logoPathFs = public_path('img/logo-kmi.png');
  $logoExists = file_exists($logoPathFs);

  $sumWork = $sumBase = $sumOTHours = $sumOT1 = $sumOT2 = $sumOTTotal = $sumTotal = 0;
@endphp

<div class="brand-wrap">
  <table class="brand" cellspacing="0" cellpadding="0">
    <tr>
      <td class="side">
        @if($logoExists)
          <img src="{{ $logoPathFs }}" alt="KMI Logo" class="logo">
        @else
          <div style="width:54px;height:54px;border:1px solid #e5e7eb;border-radius:8px;display:flex;align-items:center;justify-content:center;font-weight:bold;background:#f9fafb;">KMI</div>
        @endif
      </td>
      <td class="center">
        <h1>Attendance Payroll — {{ $brandTitle }}</h1>
        <div class="subtitle">Employee Attendance &amp; Overtime Recap</div>
        <div class="meta">
          Period: <b>{{ $from }}</b> → <b>{{ $to }}</b>
          • Generated: {{ now()->format('Y-m-d H:i') }}
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
        <th>In</th>
        <th>Out</th>
        <th>Work Hours</th>
        <th>Basic Salary</th>
        <th>OT Hours</th>
        <th>OT 1 (1.5×)</th>
        <th>OT 2 (2×)</th>
        <th>OT Total</th>
        <th>Total Salary</th>
      </tr>
    </thead>
    <tbody>
      @foreach ($rows as $r)
        @php
          $present = $isPresent($r);

          $work   = $present ? max(0, $safeNum($r->real_work_hour, 0)) : 0;
          $otH    = $otHours($r);
          $ot1    = $otFirst($r);
          $ot2    = $otSecond($r);
          $otTot  = $otTotal($r);

          $base   = $basicSalary($r);
          $totSal = $dailyFinal($r);

          $sumWork    += $work;
          $sumBase    += $base;
          $sumOTHours += $otH;
          $sumOT1     += $ot1;
          $sumOT2     += $ot2;
          $sumOTTotal += $otTot;
          $sumTotal   += $totSal;
        @endphp
        <tr>
          <td>{{ $fmtDate($r->schedule_date) }}</td>
          <td>{{ $r->employee_id }}</td>
          <td>{{ $r->full_name }}</td>
          <td>{{ $fmtTime($r->clock_in) }}</td>
          <td>{{ $fmtTime($r->clock_out) }}</td>
          <td>
            @if($work >= 7)
              <span class="chip chip-ok">{{ number_format($work, 2, ',', '.') }} h</span>
            @else
              <span class="chip chip-dim">{{ number_format($work, 2, ',', '.') }} h</span>
            @endif
          </td>
          <td>{{ $fmtRupiah($base) }}</td>
          <td>{{ number_format($otH, 0, ',', '.') }}</td>
          <td>{{ $fmtRupiah($ot1) }}</td>
          <td>{{ $fmtRupiah($ot2) }}</td>
          <td>{{ $fmtRupiah($otTot) }}</td>
          <td>{{ $fmtRupiah($totSal) }}</td>
        </tr>
      @endforeach
    </tbody>
    <tfoot>
      <tr>
        <th colspan="5">Grand Total</th>
        <th>{{ number_format($sumWork, 2, ',', '.') }}</th>
        <th>{{ $fmtRupiah($sumBase) }}</th>
        <th>{{ number_format($sumOTHours, 0, ',', '.') }}</th>
        <th>{{ $fmtRupiah($sumOT1) }}</th>
        <th>{{ $fmtRupiah($sumOT2) }}</th>
        <th>{{ $fmtRupiah($sumOTTotal) }}</th>
        <th>{{ $fmtRupiah($sumTotal) }}</th>
      </tr>
    </tfoot>
  </table>

  <div class="footer">
    <p><b>{{ $brandTitle }}</b> — Automated Payroll System</p>
    <p>Generated on {{ now()->format('Y-m-d H:i') }}</p>
  </div>
</div>

<script type="text/php">
if (isset($pdf)) {
  // A4 landscape; sesuaikan posisi jika perlu
  $pdf->page_text(520, 810, "Page {PAGE_NUM}/{PAGE_COUNT}", null, 8, [0.4,0.4,0.4]);
}
</script>
</body>
</html>
