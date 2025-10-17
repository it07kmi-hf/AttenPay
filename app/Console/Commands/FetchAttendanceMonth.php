<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Loop per HARI dalam 1 bulan (YYYY-MM), memanggil fetch:attendance:today
 * dengan --from=--to=tanggal tsb. Semua opsi penting diteruskan.
 */
class FetchAttendanceMonth extends Command
{
    protected $signature = 'fetch:attendance:month
        {--branch=21089 : Branch ID}
        {--job-levels=44480,44481,44482,44492 : Daftar job_level_id (whitelist)}
        {--month= : Bulan target YYYY-MM (default: bulan berjalan Asia/Jakarta)}
        {--limit=200 : Batas per halaman di API (per_page)}
        {--sleep=200 : Delay antar hari (ms)}';

    protected $description = 'Tarik data absensi 1 bulan penuh (loop harian).';

    public function handle(): int
    {
        $tz      = 'Asia/Jakarta';
        $branch  = (int) $this->option('branch');
        $levels  = (string) $this->option('job-levels');
        $limit   = max(1, (int) $this->option('limit'));
        $sleepMs = max(0, (int) $this->option('sleep'));

        $monthOpt = $this->option('month') ?: now($tz)->format('Y-m');

        try { $m = Carbon::createFromFormat('Y-m', $monthOpt, $tz); }
        catch (\Throwable) { $this->error('Invalid --month (format: YYYY-MM)'); return self::INVALID; }

        $from = $m->copy()->startOfMonth()->toDateString();
        $to   = $m->copy()->endOfMonth()->toDateString();

        $this->line(sprintf(
            'Monthly loop %s → %s (branch %d) job-levels: %s',
            $from, $to, $branch, ($levels ?: '(all)')
        ));

        for ($d = $m->copy()->startOfMonth(); $d->lte($m->copy()->endOfMonth()); $d->addDay()) {
            $status = $this->call('fetch:attendance:today', [
                '--branch'     => $branch,
                '--job-levels' => $levels,
                '--from'       => $d->toDateString(),
                '--to'         => $d->toDateString(),
                '--limit'      => $limit,
            ]);

            if ($status !== 0) {
                $this->warn("  ! child exit code {$status} pada ".$d->toDateString());
            }

            if ($sleepMs > 0) usleep($sleepMs * 1000);
        }

        $this->info('Done (monthly).');
        return self::SUCCESS;
    }
}
