<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\MekariClient;
use App\Services\OvertimeCalculator;
use App\Models\Attendance;
use Carbon\CarbonImmutable;

class FetchAttendance extends Command
{
    protected $signature = 'fetch:attendance {--from=2025-05-01} {--to=today} {--branch=21089}';
    protected $description = 'Fetch attendance from a date range and upsert into DB';

    public function handle(MekariClient $api, OvertimeCalculator $calc): int
    {
        @ini_set('memory_limit', '-1');
        @set_time_limit(0);

        $from   = $this->option('from');
        $toOpt  = $this->option('to');
        $to     = $toOpt === 'today' ? now('Asia/Jakarta')->toDateString() : $toOpt;
        $branch = (int) $this->option('branch');

        $start = CarbonImmutable::parse($from, 'Asia/Jakarta');
        $end   = CarbonImmutable::parse($to,   'Asia/Jakarta');

        $this->info("Fetching {$from} → {$to} (branch {$branch})");

        for ($d = $start; $d->lessThanOrEqualTo($end); $d = $d->addDay()) {
            $date = $d->format('Y-m-d');
            $rows = $api->fetchDate($date, $branch);
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
            $this->line($date.' : '.count($rows).' rows');
            usleep(120000); // rate-limit
        }

        $this->info('Done');
        return self::SUCCESS;
    }
}
