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
     * Aggregate per employee for the WHOLE filtered result (not per page).
     * Return: array keyed by employee_id -> [
     *   'employee_id','full_name','monthly_ot','monthly_bsott','monthly_presence','bpjs_tk','bpjs_kes'
     * ]
     *
     * Rules:
     * - Absent rows => 0 for Hourly/OT/Presence/Total.
     * - If daily_total_amount is numeric → use it as-is (including 0).
     * - If NULL and present → base (rate*billable) + OT. If NULL and absent → 0.
     */
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

            $tk  = (float)($r->bpjs_tk_deduction  ?? 0);
            $kes = (float)($r->bpjs_kes_deduction ?? 0);
            if ($totals[$empId]['bpjs_tk']  == 0 && $tk  > 0) $totals[$empId]['bpjs_tk']  = $tk;
            if ($totals[$empId]['bpjs_kes'] == 0 && $kes > 0) $totals[$empId]['bpjs_kes'] = $kes;
        }

        return $totals;
    }

    /**
     * List:
     * - Default range = 1st day of month → today (Asia/Jakarta)
     * - ✅ Fixed paginate = 10 per page (consistent on all envs)
     * - Adds organization filter (org_id)
     */
    public function index(Request $req)
    {
        $branch  = (int) $req->query('branch_id', 21089);
        $perPage = 10; // fixed

        // Dates
        $tz         = 'Asia/Jakarta';
        $today      = now($tz)->toDateString();
        $monthStart = now($tz)->startOfMonth()->toDateString();

        $from = $req->query('from', $monthStart);
        $to   = $req->query('to', $today);

        try { $from = Carbon::parse($from, $tz)->toDateString(); } catch (\Throwable $e) { $from = $monthStart; }
        try { $to   = Carbon::parse($to,   $tz)->toDateString(); } catch (\Throwable $e) { $to   = $today; }
        if ($from > $to) { [$from, $to] = [$to, $from]; }

        $q = trim((string) $req->query('q', ''));

        // NEW: Organization filter (nullable)
        $orgIdParam = $req->query('org_id'); // string|null
        $orgId = ($orgIdParam === null || $orgIdParam === '') ? null : (int)$orgIdParam;

        // Base builder
        $builder = Attendance::query()
            ->select([
                'id',
                'employee_id','full_name','schedule_date',
                'clock_in','clock_out','real_work_hour',
                'overtime_hours','overtime_first_amount','overtime_second_amount','overtime_total_amount',
                'branch_name','organization_name','job_position',
                'gender','join_date',
                'hourly_rate_used','daily_billable_hours','daily_total_amount','tenure_ge_1y',
                'presence_premium_daily',
                'bpjs_tk_deduction','bpjs_kes_deduction',
                // (opsional) kalau kamu sudah menambahkan timeoff_name sebelumnya:
                // 'timeoff_name',
            ])
            ->where('branch_id', $branch)
            ->whereBetween('schedule_date', [$from, $to]);

        if ($orgId !== null) {
            $builder->where('organization_id', $orgId);
        }

        if ($q !== '') {
            // prefix match for index usage
            $builder->where(function ($w) use ($q) {
                $w->where('employee_id', 'like', $q.'%')
                  ->orWhere('full_name', 'like', $q.'%');
            });
        }

        $builder->orderBy('schedule_date', 'asc')
                ->orderBy('employee_id', 'asc');

        $rows = $builder->paginate($perPage)->withQueryString();

        // Build aggregate query (same filters)
        $baseQueryForAgg = Attendance::query()
            ->where('branch_id', $branch)
            ->whereBetween('schedule_date', [$from, $to]);

        if ($orgId !== null) {
            $baseQueryForAgg->where('organization_id', $orgId);
        }

        if ($q !== '') {
            $baseQueryForAgg->where(function ($w) use ($q) {
                $w->where('employee_id', 'like', $q.'%')
                  ->orWhere('full_name', 'like', $q.'%');
            });
        }

        $employeeTotals = $this->buildEmployeeTotals($baseQueryForAgg);

        // === Organization options (for dropdown) ===
        // Distinct (id, name) within current branch & date range
        $orgOptions = Attendance::query()
            ->selectRaw('organization_id, COALESCE(NULLIF(organization_name, \'\'), \'(Unassigned)\') AS organization_name')
            ->where('branch_id', $branch)
            ->whereBetween('schedule_date', [$from, $to])
            ->distinct()
            ->orderBy('organization_name', 'asc')
            ->get()
            ->map(fn($r) => [
                'id'   => $r->organization_id,            // nullable for unassigned
                'name' => (string)$r->organization_name,
            ])
            ->values();

        return Inertia::render('Attendance/Index', [
            'rows'    => $rows,
            'filters' => [
                'branch_id' => $branch,
                'from'      => $from,
                'to'        => $to,
                'q'         => $q,
                'per_page'  => $perPage,
                'org_id'    => $orgId,     // NEW
            ],
            'employeeTotals' => $employeeTotals,
            'orgOptions'     => $orgOptions, // NEW
        ]);
    }

    /**
     * Export CSV / Excel-compatible CSV / PDF.
     * - Includes org_id filter.
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

        // NEW: Organization filter
        $orgIdParam = $req->query('org_id');
        $orgId = ($orgIdParam === null || $orgIdParam === '') ? null : (int)$orgIdParam;

        $baseQuery = Attendance::query()
            ->where('branch_id', $branch)
            ->whereBetween('schedule_date', [$from, $to]);

        if ($orgId !== null) {
            $baseQuery->where('organization_id', $orgId);
        }

        if ($q !== '') {
            $baseQuery->where(function ($w) use ($q) {
                $w->where('employee_id', 'like', $q.'%')
                  ->orWhere('full_name', 'like', $q.'%');
            });
        }

        $baseQuery->orderBy('schedule_date', 'asc')
                  ->orderBy('employee_id', 'asc');

        // Filename
        $meta = (clone $baseQuery)
            ->reorder()
            ->select('employee_id', 'full_name')
            ->distinct()
            ->limit(2)
            ->get();

        $nameSlug = $meta->count() === 1
            ? Str::slug($meta[0]->full_name ?: 'unknown', '_')
            : 'all';

        $filenameBase = "attendance_{$nameSlug}_{$branch}_{$from}_{$to}" . ($orgId !== null ? "_org{$orgId}" : "");

        // ===== CSV / Excel-compatible CSV =====
        if ($format === 'csv' || $format === 'xlsx') {
            $filename = $filenameBase . ($format === 'xlsx' ? '.xlsx.csv' : '.csv');

            $headers = [
                'Content-Type'        => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            ];

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
                // UTF-8 BOM for Excel (Windows)
                fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
                fputcsv($out, $columns);

                (clone $baseQuery)
                    ->select($selectCols)
                    ->chunk(2000, function ($chunk) use ($out) {
                        foreach ($chunk as $r) {
                            $otTot    = (int) ($r->overtime_total_amount ?? 0);
                            $presence = (int) ($r->presence_premium_daily ?? 0);
                            $daily    = is_numeric($r->daily_total_amount) ? (int)$r->daily_total_amount : 0;

                            fputcsv($out, [
                                substr((string)$r->schedule_date, 0, 10),
                                $r->employee_id,
                                $r->full_name,
                                $r->clock_in  ? substr((string)$r->clock_in,  0, 5) : '',
                                $r->clock_out ? substr((string)$r->clock_out, 0, 5) : '',
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

            $employeeTotals = $this->buildEmployeeTotals($baseQuery);

            $pdf = Pdf::loadView('pdf.attendance', [
                'rows'            => $rows,
                'from'            => $from,
                'to'              => $to,
                'branch'          => $branch,
                'employeeTotals'  => $employeeTotals,
            ])->setPaper('a4', 'landscape');

            return $pdf->download($filenameBase . '.pdf');
        }

        // Fallback
        return redirect()->route('attendance.index')->with('error', 'Unknown export format.');
    }
}
