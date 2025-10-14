<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <style>
    body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 11px; color: #111; }
    h1 { font-size: 16px; margin: 0 0 6px; }
    .meta { font-size: 11px; color: #555; margin-bottom: 10px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border: 1px solid #ddd; padding: 6px 8px; }
    th { background: #e9f2ff; text-align: left; }
    tr:nth-child(even) td { background: #fafafa; }
    .right { text-align: right; }
  </style>
</head>
<body>
  <h1>Attendance Export</h1>
  <div class="meta">Branch: {{ $branch }} | Period: {{ $from }} → {{ $to }}</div>
  <table>
    <thead>
      <tr>
        <th>Date</th>
        <th>Employee ID</th>
        <th>Name</th>
        <th>Clock In</th>
        <th>Clock Out</th>
        <th>Work Hour</th>
        <th>OT Hours</th>
        <th>OT 1 (1.5x)</th>
        <th>OT 2 (2x)</th>
        <th>OT Total</th>
      </tr>
    </thead>
    <tbody>
      @foreach ($rows as $r)
        <tr>
          <td>{{ $r->schedule_date }}</td>
          <td>{{ $r->employee_id }}</td>
          <td>{{ $r->full_name }}</td>
          <td>{{ $r->clock_in ?? '-' }}</td>
          <td>{{ $r->clock_out ?? '-' }}</td>
          <td class="right">{{ number_format($r->real_work_hour, 2) }}</td>
          <td class="right">{{ (int)$r->overtime_hours }}</td>
          <td class="right">{{ number_format($r->overtime_first_amount, 0, ',', '.') }}</td>
          <td class="right">{{ number_format($r->overtime_second_amount, 0, ',', '.') }}</td>
          <td class="right">{{ number_format($r->overtime_total_amount, 0, ',', '.') }}</td>
        </tr>
      @endforeach
    </tbody>
  </table>
</body>
</html>
