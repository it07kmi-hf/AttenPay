<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttendanceController extends Controller
{
    public function index(Request $req)
    {
        $branch = (int)($req->query('branch_id', 21089));
        $from   = $req->query('from', '2025-05-01');
        $to     = $req->query('to', now('Asia/Jakarta')->toDateString());
        $q      = trim((string)$req->query('q', ''));

        $builder = Attendance::query()
            ->select([
                'id','employee_id','full_name','schedule_date','clock_in','clock_out',
                'real_work_hour','overtime_hours','overtime_first_amount',
                'overtime_second_amount','overtime_total_amount','branch_id'
            ])
            ->where('branch_id', $branch)
            ->whereBetween('schedule_date', [$from, $to])
            // urut sesuai index komposit: (branch_id, schedule_date, employee_id)
            ->orderBy('schedule_date', 'asc')
            ->orderBy('employee_id', 'asc');

        if ($q !== '') {
            // Prefix match agar bisa pakai index (lebih cepat daripada %q%)
            $builder->where(function ($w) use ($q) {
                $w->where('employee_id', 'like', $q.'%')
                  ->orWhere('full_name', 'like', $q.'%');
            });
        }

        // Jika nanti data jadi super besar, boleh ganti ke simplePaginate(100)
        $rows = $builder->paginate(100)->withQueryString();

        return Inertia::render('Attendance/Index', [
            'rows'    => $rows,
            'filters' => ['branch_id' => $branch, 'from' => $from, 'to' => $to, 'q' => $q],
            'locale'  => app()->getLocale(),
        ]);
    }

    public function export(Request $req)
    {
        $format = strtolower($req->query('format', 'csv')); // csv | xlsx | pdf
        $branch = (int)($req->query('branch_id', 21089));
        $from   = $req->query('from', '2025-05-01');
        $to     = $req->query('to', now('Asia/Jakarta')->toDateString());
        $q      = trim((string)$req->query('q', ''));

        $baseQuery = Attendance::query()
            ->select([
                'schedule_date','employee_id','full_name','clock_in','clock_out',
                'real_work_hour','overtime_hours','overtime_first_amount',
                'overtime_second_amount','overtime_total_amount'
            ])
            ->where('branch_id', $branch)
            ->whereBetween('schedule_date', [$from, $to])
            ->orderBy('schedule_date', 'asc')
            ->orderBy('employee_id', 'asc');

        if ($q !== '') {
            $baseQuery->where(function ($w) use ($q) {
                $w->where('employee_id', 'like', $q.'%')
                  ->orWhere('full_name', 'like', $q.'%');
            });
        }

        $filenameBase = "attendance_{$branch}_{$from}_{$to}";

        // CSV: pakai streaming + chunkById biar hemat RAM
        if ($format === 'csv' || $format === 'xlsx') {
            // NOTE: jika butuh XLSX beneran, install maatwebsite/excel (lihat catatan bawah).
            $filename = $filenameBase . ($format === 'xlsx' ? '.xlsx.csv' : '.csv'); // Excel-compatible CSV
            $headers = [
                'Content-Type'        => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            ];

            $columns = [
                'Date','Employee ID','Name','Clock In','Clock Out',
                'Work Hour','OT Hours','OT 1 (1.5x)','OT 2 (2x)','OT Total'
            ];

            $callback = function () use ($baseQuery, $columns) {
                $out = fopen('php://output', 'w');
                // BOM UTF-8 agar Excel Windows tidak berantakan
                fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
                fputcsv($out, $columns);

                // stream per-chunk
                $baseQuery->clone()->orderBy('id')->chunk(2000, function ($chunk) use ($out) {
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
                    // flush tiap chunk
                    if (function_exists('flush')) { flush(); }
                });

                fclose($out);
            };

            return response()->stream($callback, 200, $headers);
        }

        if ($format === 'pdf') {
            // BUTUH package: barryvdh/laravel-dompdf (lihat catatan bawah)
            $rows = $baseQuery->get();
            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.attendance', [
                'rows'   => $rows,
                'from'   => $from,
                'to'     => $to,
                'branch' => $branch,
            ])->setPaper('a4', 'landscape');

            return $pdf->download($filenameBase.'.pdf');
        }

        // fallback
        return redirect()->route('attendance.index')->with('error', 'Unknown export format.');
    }

}
