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
     * Aggregate per-employee totals across the entire filtered range (not per page).
     * Adds: work_days = number of present days (real_work_hour > 0 AND has clock in & out).
     * Return: array keyed by employee_id
     *   ['employee_id','full_name','monthly_ot','monthly_bsott','monthly_presence','bpjs_tk','bpjs_kes','work_days']
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
                    'work_days'        => 0, // number of present days
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

            if ($isPresent) {
                $totals[$empId]['work_days']++;
            }

            $tk  = (float)($r->bpjs_tk_deduction  ?? 0);
            $kes = (float)($r->bpjs_kes_deduction ?? 0);
            if ($totals[$empId]['bpjs_tk']  == 0 && $tk  > 0) $totals[$empId]['bpjs_tk']  = $tk;
            if ($totals[$empId]['bpjs_kes'] == 0 && $kes > 0) $totals[$empId]['bpjs_kes'] = $kes;
        }

        return $totals;
    }

    /**
     * List page (fixed 10/page). Default period = current month → today (Asia/Jakarta).
     */
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

        $q     = trim((string) $req->query('q', ''));
        $orgId = (int) $req->query('organization_id', 0); // ✅ NEW: organization filter

        $builder = Attendance::query()
            ->select([
                'id',
                // identity
                'employee_id','full_name','schedule_date',
                // presence
                'clock_in','clock_out','real_work_hour',
                // timeoff
                'timeoff_id','timeoff_name',
                // overtime (IDR)
                'overtime_hours','overtime_first_amount','overtime_second_amount','overtime_total_amount',
                // org
                'branch_name','organization_name','job_position',
                // details
                'gender','join_date',
                // calculations
                'hourly_rate_used','daily_billable_hours','daily_total_amount','tenure_ge_1y',
                // presence bonus & BPJS
                'presence_premium_daily',
                'bpjs_tk_deduction','bpjs_kes_deduction',
            ])
            ->where('branch_id', $branch)
            ->whereBetween('schedule_date', [$from, $to]);

        // ✅ apply organization filter (if provided)
        if ($orgId > 0) {
            $builder->where('organization_id', $orgId);
        }

        $builder->orderBy('schedule_date', 'asc')
                ->orderBy('employee_id', 'asc');

        if ($q !== '') {
            $builder->where(function ($w) use ($q) {
                $w->where('employee_id', 'like', $q.'%')
                  ->orWhere('full_name', 'like', $q.'%');
            });
        }

        $rows = $builder->paginate($perPage)->withQueryString();

        $baseQueryForAgg = Attendance::query()
            ->where('branch_id', $branch)
            ->whereBetween('schedule_date', [$from, $to]);

        if ($orgId > 0) {
            $baseQueryForAgg->where('organization_id', $orgId);
        }

        if ($q !== '') {
            $baseQueryForAgg->where(function ($w) use ($q) {
                $w->where('employee_id', 'like', $q.'%')
                  ->orWhere('full_name', 'like', $q.'%');
            });
        }

        $employeeTotals = $this->buildEmployeeTotals($baseQueryForAgg);

        // ✅ build organization options (for the selected branch & period)
        $orgOptions = Attendance::query()
            ->select('organization_id','organization_name')
            ->where('branch_id', $branch)
            ->whereBetween('schedule_date', [$from, $to])
            ->whereNotNull('organization_id')
            ->groupBy('organization_id','organization_name')
            ->orderBy('organization_name')
            ->get()
            ->map(fn($r) => [
                'id'   => (int)$r->organization_id,
                'name' => (string)($r->organization_name ?? '(Unknown)'),
            ])
            ->values();

        return Inertia::render('Attendance/Index', [
            'rows'    => $rows,
            'filters' => [
                'branch_id'       => $branch,
                'from'            => $from,
                'to'              => $to,
                'q'               => $q,
                'per_page'        => $perPage,
                'organization_id' => $orgId,      // ✅ NEW
            ],
            'employeeTotals' => $employeeTotals,
            'orgOptions'     => $orgOptions,      // ✅ NEW
        ]);
    }

    /**
     * Export (CSV/Excel/PDF). Left as-is; PDF already supports timeoff_name if needed.
     */
    public function export(Request $req)
    {
        $format = strtolower($req->query('format', 'csv'));
        $branch = (int)($req->query('branch_id', 21089));

        $tz         = 'Asia/Jakarta';
        $today      = now($tz)->toDateString();
        $monthStart = now($tz)->startOfMonth()->toDateString();

        $from = $req->query('from', $monthStart);
        $to   = $req->query('to', $today);

        try { $from = Carbon::parse($from, $tz)->toDateString(); } catch (\Throwable) { $from = $monthStart; }
        try { $to   = Carbon::parse($to,   $tz)->toDateString(); } catch (\Throwable) { $to   = $today; }
        if ($from > $to) { [$from, $to] = [$to, $from]; }

        $q = trim((string)$req->query('q', ''));

        $baseQuery = Attendance::query()
            ->where('branch_id', $branch)
            ->whereBetween('schedule_date', [$from, $to]);

        // (opsional: ikutkan organization_id saat export kalau dikirim)
        $orgId = (int) $req->query('organization_id', 0);
        if ($orgId > 0) {
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

        if ($format === 'pdf') {
            $rows = (clone $baseQuery)->select([
                'schedule_date','employee_id','full_name','clock_in','clock_out',
                'real_work_hour','overtime_hours','overtime_first_amount',
                'overtime_second_amount','overtime_total_amount','daily_total_amount',
                'hourly_rate_used','daily_billable_hours','presence_premium_daily',
                'bpjs_tk_deduction','bpjs_kes_deduction',
                'timeoff_name',
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

        return redirect()->route('attendance.index')->with('error', 'Unknown export format.');
    }
}
