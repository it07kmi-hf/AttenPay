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
     * List data: default range = 1st day of current month -> today (Asia/Jakarta).
     */
    public function index(Request $req)
    {
        $branch = (int)($req->query('branch_id', 21089));

        // Default: bulan ini sampai hari ini (Asia/Jakarta)
        $tz         = 'Asia/Jakarta';
        $today      = now($tz)->toDateString();
        $monthStart = now($tz)->startOfMonth()->toDateString();

        // Ambil dari query jika ada; kalau invalid pakai default
        $from = $req->query('from', $monthStart);
        $to   = $req->query('to', $today);

        try { $from = Carbon::parse($from, $tz)->toDateString(); } catch (\Throwable $e) { $from = $monthStart; }
        try { $to   = Carbon::parse($to,   $tz)->toDateString(); } catch (\Throwable $e) { $to   = $today; }

        // Jika from > to, tukar biar aman
        if ($from > $to) { [$from, $to] = [$to, $from]; }

        $q = trim((string)$req->query('q', ''));

        $builder = Attendance::query()
            ->select([
                'id','employee_id','full_name','schedule_date','clock_in','clock_out',
                'real_work_hour','overtime_hours','overtime_first_amount',
                'overtime_second_amount','overtime_total_amount','branch_id'
            ])
            ->where('branch_id', $branch)
            ->whereBetween('schedule_date', [$from, $to])
            // urut sesuai index
            ->orderBy('schedule_date', 'asc')
            ->orderBy('employee_id', 'asc');

        if ($q !== '') {
            // prefix match agar pakai index
            $builder->where(function ($w) use ($q) {
                $w->where('employee_id', 'like', $q.'%')
                  ->orWhere('full_name', 'like', $q.'%');
            });
        }

        // paginate
        $rows = $builder->paginate(100)->withQueryString();

        return Inertia::render('Attendance/Index', [
            'rows'    => $rows,
            'filters' => ['branch_id' => $branch, 'from' => $from, 'to' => $to, 'q' => $q],
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

        // Urutkan untuk hasil export (query utama)
        $baseQuery->orderBy('schedule_date', 'asc')
                  ->orderBy('employee_id', 'asc');

        // ====== Tentukan nama file ======
        // DISTINCT harus tanpa ORDER BY yang bukan di select list → gunakan ->reorder()
        $meta = (clone $baseQuery)
            ->reorder()
            ->select('employee_id', 'full_name')
            ->distinct()
            ->limit(2)
            ->get();

        if ($meta->count() === 1) {
            $nameSlug = Str::slug($meta[0]->full_name ?: 'unknown', '_');
        } else {
            $nameSlug = 'all';
        }
        $filenameBase = "attendance_{$nameSlug}_{$branch}_{$from}_{$to}";

        // ===== CSV / Excel-compatible CSV =====
        if ($format === 'csv' || $format === 'xlsx') {
            // XLSX asli → gunakan maatwebsite/excel; di sini tetap CSV agar ringan
            $filename = $filenameBase . ($format === 'xlsx' ? '.xlsx.csv' : '.csv');

            $headers = [
                'Content-Type'        => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            ];

            // Kolom disamakan dengan UI/PDF
            $columns = [
                'Date','Employee ID','Name','In','Out',
                'Work Hours','Basic Salary','OT Hours','OT 1 (1.5x)','OT 2 (2x)','OT Total','Total Salary'
            ];

            $selectCols = [
                'schedule_date','employee_id','full_name','clock_in','clock_out',
                'real_work_hour','overtime_hours','overtime_first_amount',
                'overtime_second_amount','overtime_total_amount',
            ];

            $callback = function () use ($baseQuery, $columns, $selectCols) {
                $out = fopen('php://output', 'w');
                // BOM UTF-8 untuk Excel Windows
                fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
                fputcsv($out, $columns);

                $HOURLY_RATE = 28298;

                (clone $baseQuery)
                    ->select($selectCols)
                    ->chunk(2000, function ($chunk) use ($out, $HOURLY_RATE) {
                        foreach ($chunk as $r) {
                            $work   = (float)($r->real_work_hour ?? 0);
                            $base   = (int) round(min(max($work, 0), 7) * $HOURLY_RATE);
                            $otTot  = (int) ($r->overtime_total_amount ?? 0);
                            $totSal = $base + $otTot;

                            fputcsv($out, [
                                substr((string)$r->schedule_date, 0, 10),
                                $r->employee_id,
                                $r->full_name,
                                $r->clock_in  ? substr((string)$r->clock_in,  0, 5) : '-',
                                $r->clock_out ? substr((string)$r->clock_out, 0, 5) : '-',
                                $work,
                                $base,
                                (float)($r->overtime_hours ?? 0),
                                (float)($r->overtime_first_amount ?? 0),
                                (float)($r->overtime_second_amount ?? 0),
                                $otTot,
                                $totSal,
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
                'overtime_second_amount','overtime_total_amount'
            ])->get();

            $pdf = Pdf::loadView('pdf.attendance', [
                'rows'   => $rows,
                'from'   => $from,
                'to'     => $to,
                'branch' => $branch,
            ])->setPaper('a4', 'landscape');

            return $pdf->download($filenameBase . '.pdf');
        }

        // Fallback
        return redirect()->route('attendance.index')->with('error', 'Unknown export format.');
    }
}
