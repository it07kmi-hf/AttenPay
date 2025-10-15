<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\MekariClient;
use App\Services\OvertimeCalculator;
use App\Models\Attendance;
use Carbon\CarbonImmutable;

class FetchAttendanceToday extends Command
{
    protected $signature = 'fetch:attendance:today {--branch=21089 : Branch ID}';
    protected $description = 'Fetch attendance for TODAY only (no look-back), safe to run hourly via cron.';

    public function handle(MekariClient $api, OvertimeCalculator $calc): int
    {
        @ini_set('memory_limit', '-1');
        @set_time_limit(0);

        $branch = (int) $this->option('branch');
        $tz     = 'Asia/Jakarta';
        $today  = CarbonImmutable::now($tz)->toDateString();

        // Lock 10 menit biar nggak dobel
        $lockKey = "fetch-attendance-today-{$branch}";
        $lock = cache()->lock($lockKey, 600);
        if (! $lock->get()) {
            $this->warn('Another fetch:attendance:today is running. Skipping.');
            return self::SUCCESS;
        }

        $this->info("Fetching TODAY {$today} (branch {$branch})");

        try {
            $rows = $api->fetchDate($today, $branch);
            foreach ($rows as $r) {
                $calcRow = $calc->apply($r);
                Attendance::updateOrCreate(
                    [
                        'employee_id'   => $calcRow['employee_id'],
                        'schedule_date' => $calcRow['schedule_date'],
                        'branch_id'     => $calcRow['branch_id'],
                    ],
                    $calcRow
                );
            }
            $this->line($today.' : '.count($rows).' rows');
            $this->info('Done.');
            return self::SUCCESS;
        } finally {
            optional($lock)->release();
        }
    }
}
