<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Loop per BULAN (rentang YYYY-MM s/d YYYY-MM), memanggil fetch:attendance:month
 * untuk tiap bulan. Opsi penting diteruskan.
 */
class FetchAttendanceMonthsRange extends Command
{
    protected $signature = 'fetch:attendance:months-range
        {--branch=21089 : Branch ID}
        {--job-levels=44480,44481,44482,44492 : Daftar job_level_id (whitelist)}
        {--from= : Bulan awal YYYY-MM}
        {--to= : Bulan akhir YYYY-MM}
        {--limit=200 : Batas per halaman di API (per_page)}
        {--sleep=300 : Delay antar bulan (ms)}';

    protected $description = 'Tarik data absensi untuk rentang bulan (loop bulanan).';

    public function handle(): int
    {
        $tz      = 'Asia/Jakarta';
        $branch  = (int) $this->option('branch');
        $levels  = (string) $this->option('job-levels');
        $limit   = max(1, (int) $this->option('limit'));
        $sleepMs = max(0, (int) $this->option('sleep'));

        $fromM = (string) $this->option('from');
        $toM   = (string) $this->option('to');

        if ($fromM === '' || $toM === '') {
            $this->error('Wajib isi --from=YYYY-MM dan --to=YYYY-MM');
            return self::INVALID;
        }

        try { $from = Carbon::createFromFormat('Y-m', $fromM, $tz)->startOfMonth(); }
        catch (\Throwable) { $this->error('Invalid --from (YYYY-MM)'); return self::INVALID; }

        try { $to   = Carbon::createFromFormat('Y-m', $toM, $tz)->startOfMonth(); }
        catch (\Throwable) { $this->error('Invalid --to (YYYY-MM)'); return self::INVALID; }

        if ($from->gt($to)) { [$from, $to] = [$to, $from]; }

        $this->line(sprintf(
            'Months-range %s → %s (branch %d) job-levels: %s',
            $from->copy()->startOfMonth()->toDateString(),
            $to->copy()->endOfMonth()->toDateString(),
            $branch,
            ($levels ?: '(all)')
        ));

        for ($cursor = $from->copy(); $cursor->lte($to); $cursor->addMonth()) {
            $monthStr = $cursor->format('Y-m');

            $status = $this->call('fetch:attendance:month', [
                '--branch'     => $branch,
                '--job-levels' => $levels,
                '--month'      => $monthStr,
                '--limit'      => $limit,
            ]);

            if ($status !== 0) {
                $this->warn("  ! child (month {$monthStr}) exit code {$status}");
            }

            if ($sleepMs > 0) usleep($sleepMs * 1000);
        }

        $this->info('Done (months-range).');
        return self::SUCCESS;
    }
}
