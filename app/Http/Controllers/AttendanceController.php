<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

        try {
            $from = Carbon::parse($from, $tz)->toDateString();
        } catch (\Throwable $e) {
            $from = $monthStart;
        }
        try {
            $to = Carbon::parse($to, $tz)->toDateString();
        } catch (\Throwable $e) {
            $to = $today;
        }

        // Jika from > to, tukar biar aman
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $q = trim((string)$req->query('q', ''));

        $builder = Attendance::query()
            ->select([
                'id','employee_id','full_name','schedule_date','clock_in','clock_out',
                'real_work_hour','overtime_hours','overtime_first_amount',
                'overtime_second_amount','overtime_total_amount','branch_id'
            ])
            ->where('branch_id', $branch)
            ->whereBetween('schedule_date', [$from, $to])
            // urut sesuai index: (schedule_date, employee_id) di dalam branch
            ->orderBy('schedule_date', 'asc')
            ->orderBy('employee_id', 'asc');

        if ($q !== '') {
            // Prefix match agar bisa memanfaatkan index (hindari %q%)
            $builder->where(function ($w) use ($q) {
                $w->where('employee_id', 'like', $q.'%')
                  ->orWhere('full_name', 'like', $q.'%');
            });
        }

        // paginate (bisa diganti simplePaginate(100) kalau total rows sangat besar)
        $rows = $builder->paginate(100)->withQueryString();

        return Inertia::render('Attendance/Index', [
            'rows'    => $rows,
            'filters' => ['branch_id' => $branch, 'from' => $from, 'to' => $to, 'q' => $q],
            // 'locale'  => app()->getLocale(), // dihapus: tidak pakai bilingual
        ]);
    }

    /**
     * Export CSV / Excel-compatible CSV / PDF.
     * CSV di-stream per chunk agar hemat memori dan cepat.
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

        try {
            $from = Carbon::parse($from, $tz)->toDateString();
        } catch (\Throwable $e) {
            $from = $monthStart;
        }
        try {
            $to = Carbon::parse($to, $tz)->toDateString();
        } catch (\Throwable $e) {
            $to = $today;
        }
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $q = trim((string)$req->query('q', ''));

        $baseQuery = Attendance::query()
            ->where('branch_id', $branch)
            ->whereBetween('schedule_date', [$from, $to]);

        // filter pencarian (prefix)
        if ($q !== '') {
            $baseQuery->where(function ($w) use ($q) {
                $w->where('employee_id', 'like', $q.'%')
                  ->orWhere('full_name', 'like', $q.'%');
            });
        }

        // Urutan final untuk export
        $baseQuery->orderBy('schedule_date', 'asc')
                  ->orderBy('employee_id', 'asc');

        $filenameBase = "attendance_{$branch}_{$from}_{$to}";

        // ===== CSV / Excel-compatible CSV =====
        if ($format === 'csv' || $format === 'xlsx') {
            // NOTE: Jika mau XLSX asli, install maatwebsite/excel; di sini tetap CSV agar ringan & universal.
            $filename = $filenameBase . ($format === 'xlsx' ? '.xlsx.csv' : '.csv');
            $headers = [
                'Content-Type'        => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            ];

            $columns = [
                'Date','Employee ID','Name','Clock In','Clock Out',
                'Work Hour','OT Hours','OT 1 (1.5x)','OT 2 (2x)','OT Total'
            ];

            $selectCols = [
                'schedule_date','employee_id','full_name','clock_in','clock_out',
                'real_work_hour','overtime_hours','overtime_first_amount',
                'overtime_second_amount','overtime_total_amount',
            ];

            $callback = function () use ($baseQuery, $columns, $selectCols) {
                $out = fopen('php://output', 'w');
                // BOM UTF-8 agar Excel Windows aman
                fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
                fputcsv($out, $columns);

                // stream per-chunk (urut tetap dijaga di $baseQuery di atas)
                (clone $baseQuery)
                    ->select($selectCols)
                    ->chunk(2000, function ($chunk) use ($out) {
                        foreach ($chunk as $r) {
                            fputcsv($out, [
                                $r->schedule_date,
                                $r->employee_id,
                                $r->full_name,
                                $r->clock_in ?? '-',
                                $r->clock_out ?? '-',
                                (string)$r->real_work_hour,
                                (int)$r->overtime_hours,
                                (int)$r->overtime_first_amount,
                                (int)$r->overtime_second_amount,
                                (int)$r->overtime_total_amount,
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
            // BUTUH: composer require barryvdh/laravel-dompdf
            $rows = (clone $baseQuery)->select([
                'schedule_date','employee_id','full_name','clock_in','clock_out',
                'real_work_hour','overtime_hours','overtime_first_amount',
                'overtime_second_amount','overtime_total_amount'
            ])->get();

            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.attendance', [
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
