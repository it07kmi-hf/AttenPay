<?php

namespace App\Services;

class OvertimeCalculator
{
    public const HOURLY = 28298; // Rp 28.298

    public function apply(array $row): array
    {
        $jam = (float)($row['real_work_hour'] ?? 0);
        $jamInt = (int)floor($jam);
        $isSunday = (new \DateTime($row['schedule_date']))->format('w') === '0';

        $jamLembur = 0;
        $l1 = 0;
        $l2 = 0;
        $total = 0;

        if ($isSunday) {
            $jamLembur = max(0, $jamInt);
            $total = $jamLembur * 2 * self::HOURLY;
            $l2 = $total;
        } else {
            $jamLembur = max(0, $jamInt - 7);
            if ($jamLembur > 0) {
                $first = min(1, $jamLembur);
                $l1 = (int)round($first * 1.5 * self::HOURLY);
                $rest = max(0, $jamLembur - 1);
                $l2 = (int)round($rest * 2 * self::HOURLY);
                $total = $l1 + $l2;
            }
        }

        return array_merge($row, [
            'overtime_hours'     => $jamLembur,
            'overtime_first_amount' => (int)$l1,
            'overtime_second_amount'   => (int)$l2,
            'overtime_total_amount'   => (int)$total,
        ]);
    }
}
