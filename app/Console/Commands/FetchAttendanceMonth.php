<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\MekariClient;
use App\Services\OvertimeCalculator;
use App\Models\Attendance;
use Carbon\CarbonImmutable;

class FetchAttendanceMonth extends Command
{
    protected $signature = 'fetch:attendance:month {--period=} {--branch=21089} {--chunk=0}';
    protected $description = 'Fetch attendance for a single month (YYYY-MM). Optional chunking to reduce timeout risk.';

    public function handle(MekariClient $api, OvertimeCalculator $calc): int
    {
        // minimalkan risiko timeout CLI
        @ini_set('memory_limit', '-1');
        @set_time_limit(0);

        $period = (string) $this->option('period'); // format: YYYY-MM
        $branch = (int) $this->option('branch');
        $chunk  = (int) $this->option('chunk');     // 0 = tanpa chunk; >0 = pecah per N hari

        if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
            $this->error('Please provide --period in YYYY-MM format, e.g. --period=2025-05');
            return self::INVALID;
        }

        $start = CarbonImmutable::createFromFormat('Y-m', $period, 'Asia/Jakarta')->startOfMonth();
        $end   = $start->endOfMonth();

        $this->info("Fetching month {$period} (branch {$branch})");

        // Tanpa chunk: loop harian biasa
        if ($chunk <= 0) {
            for ($d = $start; $d->lessThanOrEqualTo($end); $d = $d->addDay()) {
                $date = $d->format('Y-m-d');
                $rows = $api->fetchDate($date, $branch);
                foreach ($rows as $r) {
                    $calcRow = $calc->apply($r); // hasil kolom EN: overtime_hours, dll.
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
                usleep(120000); // jaga rate-limit
            }
            $this->info('Done month '.$period);
            return self::SUCCESS;
        }

        // Dengan chunk: pecah per N hari → jeda kecil antar chunk
        $from = $start;
        while ($from->lessThanOrEqualTo($end)) {
            $to = $from->addDays($chunk - 1);
            if ($to->greaterThan($end)) $to = $end;

            $this->line("Chunk: ".$from->format('Y-m-d')." → ".$to->format('Y-m-d'));

            for ($d = $from; $d->lessThanOrEqualTo($to); $d = $d->addDay()) {
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
                usleep(120000);
            }

            // jeda antar chunk
            usleep(250000);
            $from = $to->addDay();
        }

        $this->info('Done month '.$period);
        return self::SUCCESS;
    }
}
