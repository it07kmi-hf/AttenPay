<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Carbon\CarbonImmutable;

class FetchAttendanceMonthly extends Command
{
    protected $signature = 'fetch:attendance:monthly {--from=2025-05-01} {--branch=21089}';
    protected $description = 'Backfill month by month from a starting month until current month';

    public function handle(): int
    {
        @set_time_limit(0);

        $branch = (int) $this->option('branch');
        $from   = $this->option('from'); // e.g. 2025-05-01

        $start = CarbonImmutable::parse($from, 'Asia/Jakarta')->startOfMonth();
        $end   = now('Asia/Jakarta')->startOfMonth();

        for ($m = $start; $m->lessThanOrEqualTo($end); $m = $m->addMonth()) {
            $fromM = $m->format('Y-m-01');
            $toM   = $m->endOfMonth()->format('Y-m-d');

            $this->call('fetch:attendance', [
                '--from'   => $fromM,
                '--to'     => $toM,
                '--branch' => $branch,
            ]);
        }

        return self::SUCCESS;
    }
}
