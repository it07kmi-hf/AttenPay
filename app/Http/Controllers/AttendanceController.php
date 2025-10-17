<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Barryvdh\DomPDF\Facade\Pdf;

class AttendanceController extends Controller
{
    /**
     * Rate fallback (sama seperti di FE).
     */
    private const FALLBACK_RATE = 28153;

    /**
     * Hitung agregat per karyawan untuk seluruh hasil filter (bukan per halaman).
     * Return: array keyed by employee_id -> [
     *   'employee_id','full_name','monthly_ot','monthly_bsott','monthly_presence','bpjs_tk','bpjs_kes'
     * ]
     */
    private function buildEmployeeTotals($baseQuery): array
    {
        $rows = (clone $baseQuery)->select([
            'employee_id','full_name',
            'overtime_total_amount','presence_premium_daily','daily_total_amount',
            'hourly_rate_used','daily_billable_hours','real_work_hour',
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
                ];
            }

            // OT total
            $otTot = (float)($r->overtime_total_amount ?? 0);

            // Presence harian
            $presence = (float)($r->presence_premium_daily ?? 0);

            // Total harian (ikut logika FE)
            $rate = is_numeric($r->hourly_rate_used) && $r->hourly_rate_used > 0
                ? (float)$r->hourly_rate_used
                : (float)self::FALLBACK_RATE;

            $billable = !is_null($r->daily_billable_hours)
                ? max(0, (float)$r->daily_billable_hours)
                : min(7, max(0, (float)($r->real_work_hour ?? 0)));

            $baseSalary = round($billable * $rate, 0);

            $totalDay = is_null($r->daily_total_amount)
                ? ($baseSalary + $otTot)
                : (float)$r->daily_total_amount;

            // Accumulate
            $totals[$empId]['monthly_ot']       += $otTot;
            $totals[$empId]['monthly_bsott']    += $totalDay;
            $totals[$empId]['monthly_presence'] += $presence;

            // BPJS: ambil nilai pertama non-zero (sesuai FE yang ambil yang ada)
            $tk  = (float)($r->bpjs_tk_deduction  ?? 0);
            $kes = (float)($r->bpjs_kes_deduction ?? 0);
            if ($totals[$empId]['bpjs_tk']  == 0 && $tk  > 0) $totals[$empId]['bpjs_tk']  = $tk;
            if ($totals[$empId]['bpjs_kes'] == 0 && $kes > 0) $totals[$empId]['bpjs_kes'] = $kes;
        }

        return $totals; // keyed by employee_id
    }

    /**
     * List data dengan pagination fixed 10 rows per halaman.
     * Default range: tgl 1 bulan berjalan → hari ini (Asia/Jakarta).
     */
    public function index(Request $req)
    {
        $branch  = (int) $req->query('branch_id', 21089);

        // ✅ Fixed per page 10
        $perPage = 10;

        // Default: bulan ini sampai hari ini (Asia/Jakarta)
        $tz         = 'Asia/Jakarta';
        $today      = now($tz)->toDateString();
        $monthStart = now($tz)->startOfMonth()->toDateString();

        // Ambil dari query jika ada; jika invalid pakai default
        $from = $req->query('from', $monthStart);
        $to   = $req->query('to', $today);

        try { $from = Carbon::parse($from, $tz)->toDateString(); } catch (\Throwable $e) { $from = $monthStart; }
        try { $to   = Carbon::parse($to,   $tz)->toDateString(); } catch (\Throwable $e) { $to   = $today; }

        if ($from > $to) { [$from, $to] = [$to, $from]; }

        $q = trim((string) $req->query('q', ''));

        // SELECT kolom yang dipakai di Index.jsx (+ kolom premi hadir & BPJS)
        $builder = Attendance::query()
            ->select([
                'id',
                // identitas
                'user_id','employee_id','full_name','schedule_date',
                // jam hadir
                'clock_in','clock_out','real_work_hour',
                // lembur (rupiah)
                'overtime_hours','overtime_first_amount','overtime_second_amount','overtime_total_amount',
                // shift / cabang
                'branch_id','branch_name','shift_name','attendance_code','holiday',
                // employee detail
                'gender','organization_id','organization_name','job_position_id','job_position',
                'job_level_id','job_level','join_date',
                // audit/kalkulasi
                'hourly_rate_used','daily_billable_hours','daily_total_amount','tenure_ge_1y',
                // premi hadir & BPJS (BARU)
                'presence_premium_monthly_base','presence_premium_daily',
                'bpjs_tk_deduction','bpjs_kes_deduction',
            ])
            ->where('branch_id', $branch)
            ->whereBetween('schedule_date', [$from, $to])
            ->orderBy('schedule_date', 'asc')
            ->orderBy('employee_id', 'asc');

        if ($q !== '') {
            // prefix match agar pakai index
            $builder->where(function ($w) use ($q) {
                $w->where('employee_id', 'like', $q.'%')
                  ->orWhere('full_name', 'like', $q.'%');
            });
        }

        // ✅ paginate fixed 10
        $rows = $builder->paginate($perPage)->withQueryString();

        // ✅ agregat per-karyawan untuk seluruh range (bukan per halaman)
        $baseQueryForAgg = Attendance::query()
            ->where('branch_id', $branch)
            ->whereBetween('schedule_date', [$from, $to]);
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
                'branch_id' => $branch,
                'from'      => $from,
                'to'        => $to,
                'q'         => $q,
                'per_page'  => $perPage, // info untuk FE (tetap 10)
            ],
            'employeeTotals' => $employeeTotals, // ✅ FE pakai ini untuk subtotal konsisten
            'fallbackRate'   => self::FALLBACK_RATE,
        ]);
    }

    /**
     * Export CSV / Excel-compatible CSV / PDF.
     */
    public function export(Request $req)
    {
        $format = strtolower($req->query('format', 'csv')); // csv | xlsx | pdf
        $branch = (int)($req->query('branch_id', 21089));

        $tz         = 'Asia/Jakarta';
        $today      = now($tz)->toDateString();
        $monthStart = now($tz)->startOfMonth()->toDateString();

        $from = $req->query('from', $monthStart);
        $to   = $req->query('to', $today);

        try { $from = Carbon::parse($from, $tz)->toDateString(); } catch (\Throwable $e) { $from = $monthStart; }
        try { $to   = Carbon::parse($to,   $tz)->toDateString(); } catch (\Throwable $e) { $to   = $today; }
        if ($from > $to) { [$from, $to] = [$to, $from]; }

        $q = trim((string)$req->query('q', ''));

        $baseQuery = Attendance::query()
            ->where('branch_id', $branch)
            ->whereBetween('schedule_date', [$from, $to]);

        if ($q !== '') {
            $baseQuery->where(function ($w) use ($q) {
                $w->where('employee_id', 'like', $q.'%')
                  ->orWhere('full_name', 'like', $q.'%');
            });
        }

        // Urutkan untuk hasil export
        $baseQuery->orderBy('schedule_date', 'asc')
                  ->orderBy('employee_id', 'asc');

        // Nama file
        $meta = (clone $baseQuery)
            ->reorder()
            ->select('employee_id', 'full_name')
            ->distinct()
            ->limit(2)
            ->get();

        $nameSlug = $meta->count() === 1
            ? Str::slug($meta[0]->full_name ?: 'unknown', '_')
            : 'all';

        $filenameBase = "attendance_{$nameSlug}_{$branch}_{$from}_{$to}";

        // ===== CSV / Excel-compatible CSV =====
        if ($format === 'csv' || $format === 'xlsx') {
            $filename = $filenameBase . ($format === 'xlsx' ? '.xlsx.csv' : '.csv');

            $headers = [
                'Content-Type'        => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            ];

            // Kolom export (ringkas + Presence Daily)
            $columns = [
                'Date','Employee ID','Name','In','Out',
                'Work Hours','OT Hours','OT 1 (1.5x)','OT 2 (2x)','OT Total',
                'Presence Daily','Daily Total',
            ];

            $selectCols = [
                'schedule_date','employee_id','full_name','clock_in','clock_out',
                'real_work_hour','overtime_hours','overtime_first_amount',
                'overtime_second_amount','overtime_total_amount',
                'presence_premium_daily','daily_total_amount',
            ];

            $callback = function () use ($baseQuery, $columns, $selectCols) {
                $out = fopen('php://output', 'w');
                // BOM UTF-8 untuk Excel Windows
                fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
                fputcsv($out, $columns);

                (clone $baseQuery)
                    ->select($selectCols)
                    ->chunk(2000, function ($chunk) use ($out) {
                        foreach ($chunk as $r) {
                            $otTot    = (int) ($r->overtime_total_amount ?? 0);
                            $presence = (int) ($r->presence_premium_daily ?? 0);
                            $daily    = is_null($r->daily_total_amount) ? 0 : (int) $r->daily_total_amount;

                            fputcsv($out, [
                                substr((string)$r->schedule_date, 0, 10),
                                $r->employee_id,
                                $r->full_name,
                                $r->clock_in  ? substr((string)$r->clock_in,  0, 5) : '-',
                                $r->clock_out ? substr((string)$r->clock_out, 0, 5) : '-',
                                (float)($r->real_work_hour ?? 0),
                                (float)($r->overtime_hours ?? 0),
                                (float)($r->overtime_first_amount ?? 0),
                                (float)($r->overtime_second_amount ?? 0),
                                $otTot,
                                $presence,
                                $daily,
                            ]);
                        }
                        if (function_exists('flush')) { flush(); }
                    });

                fclose($out);
            };

            return response()->stream($callback, 200, $headers);
        }

        // ===== PDF =====
        if ($format === 'pdf') {
            $rows = (clone $baseQuery)->select([
                'schedule_date','employee_id','full_name','clock_in','clock_out',
                'real_work_hour','overtime_hours','overtime_first_amount',
                'overtime_second_amount','overtime_total_amount','daily_total_amount',
                'hourly_rate_used','daily_billable_hours','presence_premium_daily',
                'bpjs_tk_deduction','bpjs_kes_deduction',
            ])->get();

            // ✅ Totals untuk dipakai di view PDF (konsisten dengan layar)
            $employeeTotals = $this->buildEmployeeTotals($baseQuery);

            $pdf = Pdf::loadView('pdf.attendance', [
                'rows'            => $rows,
                'from'            => $from,
                'to'              => $to,
                'branch'          => $branch,
                'employeeTotals'  => $employeeTotals,
                'fallbackRate'    => self::FALLBACK_RATE,
            ])->setPaper('a4', 'landscape');

            return $pdf->download($filenameBase . '.pdf');
        }

        // Fallback
        return redirect()->route('attendance.index')->with('error', 'Unknown export format.');
    }
}
