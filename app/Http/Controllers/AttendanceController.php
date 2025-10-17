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
     * List data: default range = tanggal 1 bulan berjalan → hari ini (Asia/Jakarta).
     * Catatan:
     * - Select ikut sertakan kolom premi & BPJS agar bisa dipakai di FE.
     * - Urutan dan filter diset agar pakai index.
     */
    public function index(Request $req)
    {
        $branch = (int) $req->query('branch_id', 21089);

        // === per_page: default 10, batasi max 500
        $perPage = (int) $req->query('per_page', 10);
        $perPage = $perPage > 0 ? min($perPage, 500) : 10;

        // Pastikan kita baca parameter page secara eksplisit
        $page = (int) $req->query('page', 1);
        if ($page < 1) $page = 1;

        // Default: bulan ini sampai hari ini (Asia/Jakarta)
        $tz         = 'Asia/Jakarta';
        $today      = now($tz)->toDateString();
        $monthStart = now($tz)->startOfMonth()->toDateString();

        // Ambil dari query jika ada; jika invalid pakai default
        $from = $req->query('from', $monthStart);
        $to   = $req->query('to', $today);

        try { $from = Carbon::parse($from, $tz)->toDateString(); } catch (\Throwable $e) { $from = $monthStart; }
        try { $to   = Carbon::parse($to,   $tz)->toDateString(); } catch (\Throwable $e) { $to   = $today; }

        // Jika from > to, tukar biar aman
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

        // paginate (kunci per_page & page eksplisit)
        $rows = $builder
            ->paginate($perPage, ['*'], 'page', $page)
            ->withQueryString();

        return Inertia::render('Attendance/Index', [
            'rows'    => $rows,
            'filters' => [
                'branch_id' => $branch,
                'from'      => $from,
                'to'        => $to,
                'q'         => $q,
                'per_page'  => $perPage, // kirim balik ke FE
            ],
        ]);
    }

    /**
     * Export CSV / Excel-compatible CSV / PDF.
     * Catatan:
     * - CSV: tetap ringan; sertakan Presence Daily supaya mudah rekap.
     * - PDF: pakai view lama apa adanya (rows sudah membawa field extra).
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
                            $otTot   = (int) ($r->overtime_total_amount ?? 0);
                            $presence= (int) ($r->presence_premium_daily ?? 0);
                            $daily   = is_null($r->daily_total_amount) ? 0 : (int) $r->daily_total_amount;

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
