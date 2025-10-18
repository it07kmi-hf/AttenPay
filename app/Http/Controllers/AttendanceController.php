<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Barryvdh\DomPDF\Facade\Pdf;

// ===== Excel (opsional). Jika paket tidak terpasang, kode akan fallback.
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class AttendanceController extends Controller
{
    /** Aggregate per-employee totals across the whole filtered range (not per page). */
    private function buildEmployeeTotals($baseQuery): array
    {
        $rows = (clone $baseQuery)->select([
            'employee_id','full_name',
            'clock_in','clock_out','real_work_hour',
            'hourly_rate_used','daily_billable_hours',
            'overtime_total_amount','presence_premium_daily','daily_total_amount',
            'bpjs_tk_deduction','bpjs_kes_deduction',
        ])->orderBy('employee_id','asc')->get();

        $totals = [];

        foreach ($rows as $r) {
            $empId = (string)($r->employee_id ?? '');
            if ($empId === '') continue;

            if (!isset($totals[$empId])) {
                $totals[$empId] = [
                    'employee_id'      => $empId,
                    'full_name'        => (string)($r->full_name ?? ''),
                    'monthly_ot'       => 0,
                    'monthly_bsott'    => 0,
                    'monthly_presence' => 0,
                    'bpjs_tk'          => 0,
                    'bpjs_kes'         => 0,
                    'work_days'        => 0,
                ];
            }

            $clockIn   = trim((string)($r->clock_in  ?? ''));
            $clockOut  = trim((string)($r->clock_out ?? ''));
            $hours     = max(0, (float)($r->real_work_hour ?? 0));
            $isPresent = ($hours > 0 && $clockIn !== '' && $clockOut !== '');

            $otTot     = (float)($r->overtime_total_amount ?? 0);
            $presence  = (float)($r->presence_premium_daily ?? 0);

            if (is_numeric($r->daily_total_amount)) {
                $totalDay = (float)$r->daily_total_amount;
            } else {
                if ($isPresent) {
                    $rate     = max(0, (float)($r->hourly_rate_used ?? 0));
                    $billable = max(0, (float)($r->daily_billable_hours ?? 0));
                    $base     = (int)round($rate * $billable);
                    $totalDay = $base + $otTot;
                } else {
                    $totalDay = 0;
                }
            }

            $totals[$empId]['monthly_ot']       += $otTot;
            $totals[$empId]['monthly_bsott']    += $totalDay;
            $totals[$empId]['monthly_presence'] += $presence;

            if ($isPresent) $totals[$empId]['work_days']++;

            $tk  = (float)($r->bpjs_tk_deduction  ?? 0);
            $kes = (float)($r->bpjs_kes_deduction ?? 0);
            if ($totals[$empId]['bpjs_tk']  == 0 && $tk  > 0) $totals[$empId]['bpjs_tk']  = $tk;
            if ($totals[$empId]['bpjs_kes'] == 0 && $kes > 0) $totals[$empId]['bpjs_kes'] = $kes;
        }

        return $totals;
    }

    /** List page (fixed 10/page) + organization filter + daftar employees (untuk modal payslip). */
    public function index(Request $req)
    {
        $branch  = (int) $req->query('branch_id', 21089);
        $perPage = 10;

        $tz         = 'Asia/Jakarta';
        $today      = now($tz)->toDateString();
        $monthStart = now($tz)->startOfMonth()->toDateString();

        $from = $req->query('from', $monthStart);
        $to   = $req->query('to', $today);

        try { $from = Carbon::parse($from, $tz)->toDateString(); } catch (\Throwable) { $from = $monthStart; }
        try { $to   = Carbon::parse($to,   $tz)->toDateString(); } catch (\Throwable) { $to   = $today; }
        if ($from > $to) { [$from, $to] = [$to, $from]; }

        $q   = trim((string) $req->query('q', ''));
        $org = $req->query('organization_id'); // null | 'all' | numeric

        $builder = Attendance::query()
            ->select([
                'id',
                // identity
                'employee_id','full_name','schedule_date',
                // presence
                'clock_in','clock_out','real_work_hour',
                // timeoff
                'timeoff_id','timeoff_name',
                // overtime
                'overtime_hours','overtime_first_amount','overtime_second_amount','overtime_total_amount',
                // org
                'branch_name','organization_id','organization_name','job_position',
                // details
                'gender','join_date',
                // calc
                'hourly_rate_used','daily_billable_hours','daily_total_amount','tenure_ge_1y',
                // presence & bpjs
                'presence_premium_daily','bpjs_tk_deduction','bpjs_kes_deduction',
            ])
            ->where('branch_id', $branch)
            ->whereBetween('schedule_date', [$from, $to])
            ->when(($org !== null && $org !== '' && $org !== 'all'), fn($q2) =>
                $q2->where('organization_id', (int)request('organization_id'))
            )
            ->orderBy('schedule_date', 'asc')
            ->orderBy('employee_id', 'asc');

        if ($q !== '') {
            $builder->where(function ($w) use ($q) {
                $w->where('employee_id', 'like', $q.'%')
                  ->orWhere('full_name', 'like', $q.'%');
            });
        }

        $rows = $builder->paginate($perPage)->withQueryString();

        // List organizations (dropdown)
        $organizations = Attendance::query()
            ->selectRaw('organization_id, COALESCE(organization_name,"(Unknown)") as organization_name')
            ->where('branch_id', $branch)
            ->whereNotNull('organization_id')
            ->groupBy('organization_id','organization_name')
            ->orderBy('organization_name')
            ->get()
            ->map(fn($r) => ['id' => (int)$r->organization_id, 'name' => (string)$r->organization_name])
            ->values();

        // List employees (distinct) untuk searchable select di modal payslip
        $employees = Attendance::query()
            ->select('employee_id','full_name')
            ->where('branch_id', $branch)
            ->when(($org !== null && $org !== '' && $org !== 'all'), fn($q2) =>
                $q2->where('organization_id', (int)request('organization_id'))
            )
            ->groupBy('employee_id','full_name')
            ->orderBy('full_name')
            ->get()
            ->map(fn($r) => ['id' => (string)$r->employee_id, 'name' => (string)$r->full_name])
            ->values();

        // Aggregate for whole range
        $baseQueryForAgg = Attendance::query()
            ->where('branch_id', $branch)
            ->whereBetween('schedule_date', [$from, $to])
            ->when(($org !== null && $org !== '' && $org !== 'all'), fn($q2) =>
                $q2->where('organization_id', (int)request('organization_id'))
            );

        if ($q !== '') {
            $baseQueryForAgg->where(function ($w) use ($q) {
                $w->where('employee_id', 'like', $q.'%')
                  ->orWhere('full_name', 'like', $q.'%');
            });
        }

        $employeeTotals = $this->buildEmployeeTotals($baseQueryForAgg);

        return Inertia::render('Attendance/Index', [
            'rows'    => $rows,
            'filters' => [
                'branch_id'       => $branch,
                'from'            => $from,
                'to'              => $to,
                'q'               => $q,
                'per_page'        => $perPage,
                'organization_id' => ($org === null ? 'all' : $org),
            ],
            'organizations' => $organizations,
            'employees'     => $employees, // untuk modal payslip
            'employeeTotals'=> $employeeTotals,
        ]);
    }

    /** Export rekap (CSV | XLSX | PDF). */
    public function export(Request $req)
    {
        $format = strtolower($req->query('format', 'csv')); // csv | xlsx | pdf
        $branch = (int)($req->query('branch_id', 21089));

        $tz         = 'Asia/Jakarta';
        $today      = now($tz)->toDateString();
        $monthStart = now($tz)->startOfMonth()->toDateString();

        $from = $req->query('from', $monthStart);
        $to   = $req->query('to', $today);

        try { $from = Carbon::parse($from, $tz)->toDateString(); } catch (\Throwable) { $from = $monthStart; }
        try { $to   = Carbon::parse($to,   $tz)->toDateString(); } catch (\Throwable) { $to   = $today; }
        if ($from > $to) { [$from, $to] = [$to, $from]; }

        $q   = trim((string)$req->query('q', ''));
        $org = $req->query('organization_id');

        $baseQuery = Attendance::query()
            ->where('branch_id', $branch)
            ->whereBetween('schedule_date', [$from, $to])
            ->when(($org !== null && $org !== '' && $org !== 'all'), fn($q2) =>
                $q2->where('organization_id', (int)request('organization_id'))
            );

        if ($q !== '') {
            $baseQuery->where(function ($w) use ($q) {
                $w->where('employee_id', 'like', $q.'%')
                  ->orWhere('full_name', 'like', $q.'%');
            });
        }

        $baseQuery->orderBy('schedule_date', 'asc')
                  ->orderBy('employee_id', 'asc');

        // ===== PDF rekap =====
        if ($format === 'pdf') {
            $rows = (clone $baseQuery)->select([
                'schedule_date','employee_id','full_name','clock_in','clock_out',
                'real_work_hour','overtime_hours','overtime_first_amount',
                'overtime_second_amount','overtime_total_amount','daily_total_amount',
                'hourly_rate_used','daily_billable_hours','presence_premium_daily',
                'bpjs_tk_deduction','bpjs_kes_deduction','timeoff_name',
                'branch_name','organization_name','job_position','gender','join_date','tenure_ge_1y',
            ])->get();

            $employeeTotals = $this->buildEmployeeTotals($baseQuery);

            $label = $q !== '' ? Str::slug($q, '_') : 'all';
            $filename = "attendance_{$label}_{$branch}_{$from}_{$to}.pdf";

            $pdf = Pdf::loadView('pdf.attendance', [
                'rows'            => $rows,
                'from'            => $from,
                'to'              => $to,
                'branch'          => $branch,
                'employeeTotals'  => $employeeTotals,
            ])->setPaper('a4', 'landscape');

            return $pdf->download($filename);
        }

        // ===== Data untuk CSV/XLSX =====
        $rows = (clone $baseQuery)->select([
            'schedule_date','employee_id','full_name','gender','join_date',
            'branch_name','organization_name','job_position',
            'clock_in','clock_out','real_work_hour','timeoff_name',
            'overtime_hours','overtime_first_amount','overtime_second_amount','overtime_total_amount',
            'hourly_rate_used','daily_billable_hours','daily_total_amount',
            'presence_premium_daily','bpjs_tk_deduction','bpjs_kes_deduction','tenure_ge_1y',
        ])->get();

        // Helper perhitungan (selaras UI/PDF)
        $safe = fn($v) => is_numeric($v) ? (float)$v : 0;
        $isPresent = function($r) use ($safe) {
            $cin  = trim((string)($r->clock_in ?? ''));
            $cout = trim((string)($r->clock_out ?? ''));
            $hrs  = max(0, $safe($r->real_work_hour ?? 0));
            return ($cin !== '' && $cout !== '' && $hrs > 0);
        };
        $billable = function($r) use ($safe, $isPresent) {
            if (!$isPresent($r)) return 0;
            if (!is_null($r->daily_billable_hours)) {
                return max(0, $safe($r->daily_billable_hours));
            }
            return min(7, max(0, $safe($r->real_work_hour)));
        };
        $hourly  = fn($r) => $isPresent($r) ? max(0, (int)($r->hourly_rate_used ?? 0)) : 0;
        $baseSal = fn($r) => (int) round($billable($r) * $hourly($r));
        $ot1     = fn($r) => $isPresent($r) ? (int)$safe($r->overtime_first_amount)  : 0;
        $ot2     = fn($r) => $isPresent($r) ? (int)$safe($r->overtime_second_amount) : 0;
        $otTot   = fn($r) => $isPresent($r) ? (int)$safe($r->overtime_total_amount)  : 0;
        $presence= fn($r) => $isPresent($r) ? (int)$safe($r->presence_premium_daily) : 0;
        $dailyTotal = function($r) use ($baseSal, $otTot, $isPresent) {
            if (!is_null($r->daily_total_amount) && is_numeric($r->daily_total_amount)) {
                return (int)$r->daily_total_amount;
            }
            return $isPresent($r) ? ($baseSal($r) + $otTot($r)) : 0;
        };

        $header = [
            'Date','Employee ID','Name','Gender','Join Date',
            'Branch','Organization','Job Position',
            'Clock In','Clock Out','Work Hours','Timeoff',
            'OT Hours','OT 1 (1.5x)','OT 2 (2x)','OT Total',
            'Presence Daily','Hourly Rate','Billable Hours','Basic Salary','Daily Total',
            'Tenure >= 1y','BPJS TK','BPJS Kesehatan',
        ];

        $data = [];
        foreach ($rows as $r) {
            $data[] = [
                substr((string)$r->schedule_date,0,10),
                (string)$r->employee_id,
                (string)$r->full_name,
                (string)($r->gender ?? ''),
                substr((string)$r->join_date,0,10),
                (string)$r->branch_name,
                (string)$r->organization_name,
                (string)$r->job_position,
                $r->clock_in ? substr((string)$r->clock_in,0,5) : '',
                $r->clock_out? substr((string)$r->clock_out,0,5): '',
                $isPresent($r) ? (string)$safe($r->real_work_hour) : '0',
                (string)($r->timeoff_name ?? ''),
                $isPresent($r) ? (string)$safe($r->overtime_hours) : '0',
                (string)$ot1($r),
                (string)$ot2($r),
                (string)$otTot($r),
                (string)$presence($r),
                (string)$hourly($r),
                (string)$billable($r),
                (string)$baseSal($r),
                (string)$dailyTotal($r),
                (string)(($r->tenure_ge_1y ?? false) ? 'Yes' : 'No'),
                (string)(int)($r->bpjs_tk_deduction ?? 0),
                (string)(int)($r->bpjs_kes_deduction ?? 0),
            ];
        }

        $label = $q !== '' ? Str::slug($q, '_') : 'all';
        $baseFilename = "attendance_{$label}_{$branch}_{$from}_{$to}";

        // ===== CSV =====
        if ($format === 'csv') {
            $filename = $baseFilename.'.csv';
            $callback = function() use ($header, $data) {
                echo "\xEF\xBB\xBF"; // BOM UTF-8 (Excel Windows friendly)
                $fh = fopen('php://output', 'w');
                fputcsv($fh, $header);
                foreach ($data as $row) fputcsv($fh, $row);
                fclose($fh);
            };
            return response()->streamDownload($callback, $filename, [
                'Content-Type' => 'text/csv; charset=UTF-8',
            ]);
        }

        // ===== XLSX (jika paket tersedia) — fallback ke .xls (TSV) jika tidak =====
        if ($format === 'xlsx') {
            if (class_exists(Excel::class)) {
                $export = new class($header, $data) implements FromArray, WithHeadings, ShouldAutoSize, WithStyles {
                    public function __construct(private array $head, private array $rows) {}
                    public function array(): array { return $this->rows; }
                    public function headings(): array { return $this->head; }
                    public function styles(Worksheet $sheet)
                    {
                        $highest = $sheet->getHighestColumn().'1';
                        $sheet->getStyle('A1:'.$highest)->getFont()->setBold(true);
                        return [];
                    }
                };
                return Excel::download($export, $baseFilename.'.xlsx');
            } else {
                // Fallback: kirim tab-separated .xls (terbuka mulus di Excel)
                $filename = $baseFilename.'.xls';
                $callback = function() use ($header, $data) {
                    echo "\xEF\xBB\xBF";
                    $fh = fopen('php://output', 'w');
                    fputcsv($fh, $header, "\t");
                    foreach ($data as $row) fputcsv($fh, $row, "\t");
                    fclose($fh);
                };
                return response()->streamDownload($callback, $filename, [
                    'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
                ]);
            }
        }

        return redirect()->route('attendance.index')->with('error', 'Unknown export format.');
    }

    /** Export PDF payslips (bulanan) dengan optional filter nama (partial). */
    public function exportPayslipsPdf(Request $req)
    {
        $tz     = 'Asia/Jakarta';
        $branch = (int)$req->query('branch_id', 21089);
        $month  = (string)$req->query('month', now($tz)->format('Y-m'));
        $org    = $req->query('organization_id');
        $nameQ  = trim((string)$req->query('name', ''));

        try { $m = Carbon::createFromFormat('Y-m', $month, $tz); }
        catch (\Throwable) {
            return redirect()->route('attendance.index')->with('error', 'Invalid month (YYYY-MM).');
        }

        $from = $m->copy()->startOfMonth()->toDateString();
        $to   = $m->copy()->endOfMonth()->toDateString();

        $empQuery = Attendance::query()
            ->select('employee_id','full_name')
            ->where('branch_id', $branch)
            ->whereBetween('schedule_date', [$from, $to])
            ->when(($org !== null && $org !== '' && $org !== 'all'), fn($q2) =>
                $q2->where('organization_id', (int)request('organization_id'))
            )
            ->when($nameQ !== '', fn($q2) =>
                $q2->where('full_name', 'like', "%{$nameQ}%")
            )
            ->groupBy('employee_id','full_name')
            ->orderBy('employee_id','asc');

        $employees = $empQuery->get();
        if ($employees->isEmpty()) {
            return redirect()->route('attendance.index')->with('error', 'Tidak ada data untuk bulan tersebut / nama.');
        }

        $pages = [];
        foreach ($employees as $emp) {
            $eid  = (string)$emp->employee_id;
            $name = (string)$emp->full_name;

            $rows = Attendance::query()
                ->where('employee_id', $eid)
                ->where('branch_id', $branch)
                ->whereBetween('schedule_date', [$from, $to])
                ->when(($org !== null && $org !== '' && $org !== 'all'), fn($q2) =>
                    $q2->where('organization_id', (int)request('organization_id'))
                )
                ->orderBy('schedule_date','asc')
                ->get([
                    'schedule_date','join_date','tenure_ge_1y',
                    'overtime_first_amount','overtime_second_amount','overtime_total_amount',
                    'presence_premium_daily','bpjs_tk_deduction','bpjs_kes_deduction',
                    'job_position','organization_name'
                ]);

            if ($rows->isEmpty()) continue;

            $join = $rows->firstWhere('join_date','!=',null)->join_date ?? null;
            $ge1y = true;
            if ($join) {
                try {
                    $jd = Carbon::parse($join, $tz);
                    $ge1y = $jd->addYear()->lte($m->copy()->endOfMonth());
                } catch (\Throwable) { $ge1y = true; }
            }

            $MONTHLY_UNDER_1Y = 4_870_511;
            $TENURE_ALLOWANCE = 25_000;
            $MONTHLY_GE_1Y    = $MONTHLY_UNDER_1Y + $TENURE_ALLOWANCE;

            $ot1   = (int)$rows->sum('overtime_first_amount');
            $ot2   = (int)$rows->sum('overtime_second_amount');
            $otTot = (int)$rows->sum('overtime_total_amount');
            $premi = (int)$rows->sum('presence_premium_daily');

            $bpjsTk  = (int)($rows->firstWhere('bpjs_tk_deduction','>',0)->bpjs_tk_deduction  ?? 146_115);
            $bpjsKes = (int)($rows->firstWhere('bpjs_kes_deduction','>',0)->bpjs_kes_deduction ?? 48_705);

            $workDays = 0;
            foreach ($rows as $r) if (($r->presence_premium_daily ?? 0) > 0) $workDays++;

            $upah_pokok   = $MONTHLY_UNDER_1Y;
            $tunj_masa    = $ge1y ? $TENURE_ALLOWANCE : 0;
            $upah_total   = $ge1y ? $MONTHLY_GE_1Y : $MONTHLY_UNDER_1Y;

            $total_penerimaan = $upah_total + $otTot + ($ge1y ? $premi : 0);
            $total_potongan   = $bpjsTk + $bpjsKes;
            $take_home_pay    = $total_penerimaan - $total_potongan;

            // Untuk template slip gaji
            $pages[] = [
                'employee_id'   => $eid,
                'full_name'     => $name,
                'bagian'        => (string)($rows->first()->job_position ?? ''),
                'period_label'  => strtoupper($m->isoFormat('MMMM')),
                'work_days'     => $workDays,

                'upah_pokok'    => $upah_pokok,
                'tunj_masa'     => $tunj_masa,
                'upah_total'    => $upah_total,

                'ot_jam1'       => $ot1,
                'ot_jam2'       => $ot2,
                'ot_jam3'       => 0,
                'ot_libur'      => 0,
                'ot_total'      => $otTot,

                'premi_hadir'   => $premi,
                'tunj_hari_raya'=> 0,

                'total_penerimaan' => $total_penerimaan,

                'bpjs_tk'       => $bpjsTk,
                'bpjs_kes'      => $bpjsKes,
                'pajak'         => 0,
                'lain_lain'     => 0,

                'total_potongan'=> $total_potongan,
                'take_home'     => $take_home_pay,
            ];
        }

        if (empty($pages)) {
            return redirect()->route('attendance.index')->with('error', 'Tidak ada data yang bisa dicetak.');
        }

        // filename mengikuti pola umum
        $labelQ = $nameQ ? ('_'.Str::slug($nameQ,'_')) : '';
        $filename = 'payslips_'.$m->format('Y_m').'_branch'.$branch.$labelQ.'.pdf';

        $pdf = Pdf::loadView('pdf.payslips', [
            'pages'  => $pages,   // template slip menerima $pages/$slips
            'month'  => $m->format('Y-m'),
            'branch' => $branch,
        ])->setPaper('a4', 'landscape');

        return $pdf->download($filename);
    }
}
