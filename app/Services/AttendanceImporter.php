<?php

namespace App\Services;

use App\Models\Attendance;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AttendanceImporter
{
    /** Default hourly rate jika sumber tidak mengirim */
    private const DEFAULT_HOURLY_RATE = 28153;

    /**
     * @param string $from Y-m-d
     * @param string $to   Y-m-d
     * @param int    $branchId
     * @param array  $sourceRows  daftar array asosiatif hasil fetch (bebas), akan dinormalisasi
     * @return array{affected:int}
     */
    public function import(string $from, string $to, int $branchId, array $sourceRows): array
    {
        if (empty($sourceRows)) {
            return ['affected' => 0];
        }

        $payload = [];
        foreach ($sourceRows as $row) {
            $payload[] = $this->normalizeRow($row, $branchId);
        }

        // Buang null rows (jika normalisasi gagal)
        $payload = array_values(array_filter($payload));

        if (empty($payload)) {
            return ['affected' => 0];
        }

        // Upsert per-chunk agar aman
        $affected = 0;
        $uniqueBy = ['employee_id', 'schedule_date', 'branch_id'];

        // kolom update = semua kolom kecuali unique keys dan id/timestamps yang ingin kita biarkan di-recompute
        $updateCols = array_values(array_diff(array_keys($payload[0]), array_merge($uniqueBy, ['id', 'created_at'])));

        foreach (array_chunk($payload, 1000) as $chunk) {
            $affected += Attendance::upsert($chunk, $uniqueBy, $updateCols);
        }

        return ['affected' => $affected];
    }

    /**
     * Ubah 1 baris sumber jadi siap simpan ke tabel attendances.
     */
    private function normalizeRow(array $src, int $branchId): ?array
    {
        // Ambil nilai dasar
        $userId       = (string) (Arr::get($src, 'user_id') ?? Arr::get($src, 'talenta_user_id', ''));
        $employeeId   = (string) Arr::get($src, 'employee_id');
        $fullName     = (string) Arr::get($src, 'full_name');
        $scheduleDate = (string) (Arr::get($src, 'schedule_date') ?? Arr::get($src, 'date'));

        if (!$employeeId || !$scheduleDate) {
            // baris tak valid
            return null;
        }

        $scheduleDate = $this->toDate($scheduleDate); // Y-m-d

        // Times → simpan HH:MM:SS
        $clockIn  = $this->toTimeOrNull(Arr::get($src, 'clock_in'));
        $clockOut = $this->toTimeOrNull(Arr::get($src, 'clock_out'));

        // Angka dasar
        $realWorkHour = $this->num(Arr::get($src, 'real_work_hour'), 0.0);
        $otHours      = (int) $this->num(Arr::get($src, 'overtime_hours'), 0);
        $ot1          = (int) $this->num(Arr::get($src, 'overtime_first_amount'), 0);
        $ot2          = (int) $this->num(Arr::get($src, 'overtime_second_amount'), 0);
        $otTotal      = (int) $this->num(Arr::get($src, 'overtime_total_amount'), 0);

        // Branch / shift / code / holiday
        $branchName     = Arr::get($src, 'branch_name');
        $shiftName      = Arr::get($src, 'shift_name');
        $attendanceCode = Arr::get($src, 'attendance_code');
        $holiday        = $this->bool(Arr::get($src, 'holiday', false));

        // Employee detail
        $gender           = Arr::get($src, 'gender');
        $organizationId   = $this->bigintOrNull(Arr::get($src, 'organization_id'));
        $organizationName = Arr::get($src, 'organization_name');
        $jobPositionId    = $this->bigintOrNull(Arr::get($src, 'job_position_id'));
        $jobPosition      = Arr::get($src, 'job_position');
        $jobLevelId       = $this->bigintOrNull(Arr::get($src, 'job_level_id'));
        $jobLevel         = Arr::get($src, 'job_level');
        $joinDate         = $this->toDateOrNull(Arr::get($src, 'join_date'));

        // Audit params
        $rateSrc = $this->num(Arr::get($src, 'hourly_rate', Arr::get($src, 'hourly_rate_used')), 0);
        $hourlyRateUsed     = (int) ($rateSrc > 0 ? $rateSrc : self::DEFAULT_HOURLY_RATE);
        $dailyBillableHours = $this->calcBillable($realWorkHour);
        $dailyBaseAmount    = (int) round($dailyBillableHours * $hourlyRateUsed);
        $dailyTotalAmount   = (int) ($this->num(Arr::get($src, 'daily_total_amount'), 0) ?: ($dailyBaseAmount + $otTotal));

        $tenureGe1y = $this->calcTenureFlag($joinDate, $scheduleDate);

        return [
            // identity
            'user_id'        => $userId !== '' ? $userId : null,
            'employee_id'    => $employeeId,
            'full_name'      => $fullName,

            // date/time
            'schedule_date'  => $scheduleDate,
            'clock_in'       => $clockIn,
            'clock_out'      => $clockOut,
            'real_work_hour' => $realWorkHour,

            // overtime (Rp)
            'overtime_hours'         => $otHours,
            'overtime_first_amount'  => $ot1,
            'overtime_second_amount' => $ot2,
            'overtime_total_amount'  => $otTotal,

            // shift/meta
            'branch_id'      => (int) ($src['branch_id'] ?? $branchId),
            'branch_name'    => $branchName,
            'shift_name'     => $shiftName,
            'attendance_code'=> $attendanceCode,
            'holiday'        => $holiday,

            // employee detail
            'gender'            => $gender,
            'organization_id'   => $organizationId,
            'organization_name' => $organizationName,
            'job_position_id'   => $jobPositionId,
            'job_position'      => $jobPosition,
            'job_level_id'      => $jobLevelId,
            'job_level'         => $jobLevel,
            'join_date'         => $joinDate,

            // audit
            'hourly_rate_used'    => $hourlyRateUsed,
            'daily_billable_hours'=> $dailyBillableHours,
            'daily_total_amount'  => $dailyTotalAmount,
            'tenure_ge_1y'        => $tenureGe1y,

            // timestamps (biarkan Eloquent isi)
            'updated_at' => now(),
            'created_at' => now(),
        ];
    }

    private function toDate(string $val): string
    {
        try {
            return Carbon::parse($val)->toDateString();
        } catch (\Throwable $e) {
            return substr($val, 0, 10);
        }
    }

    private function toDateOrNull($val): ?string
    {
        if (!$val) return null;
        try {
            return Carbon::parse($val)->toDateString();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function toTimeOrNull($val): ?string
    {
        if (!$val) return null;
        try {
            // Jika sudah HH:MM:SS langsung balikkan
            if (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', (string)$val)) {
                return strlen($val) === 5 ? $val . ':00' : $val;
            }
            // Ambil time dari datetime
            return Carbon::parse($val)->format('H:i:s');
        } catch (\Throwable $e) {
            // fallback: coba ambil pattern HH:MM dari string
            if (preg_match('/(\d{2}:\d{2})(:\d{2})?/', (string)$val, $m)) {
                return strlen($m[0]) === 5 ? $m[0] . ':00' : $m[0];
            }
            return null;
        }
    }

    private function num($v, $default = 0): float
    {
        if ($v === null || $v === '') return (float)$default;
        $n = is_numeric($v) ? (float)$v : (float) str_replace([','], '', (string)$v);
        return is_finite($n) ? $n : (float)$default;
    }

    private function bigintOrNull($v): ?int
    {
        if ($v === null || $v === '') return null;
        $n = (int)$v;
        return $n > 0 ? $n : null;
    }

    private function bool($v): bool
    {
        if (is_bool($v)) return $v;
        $s = strtolower((string)$v);
        return in_array($s, ['1','true','yes','y','on'], true);
    }

    private function calcBillable(float $real): float
    {
        // aturan default: cap 7 jam
        $h = max(0.0, $real);
        return round(min($h, 7.0), 2);
    }

    private function calcTenureFlag(?string $joinDate, string $onDate): bool
    {
        if (!$joinDate) return true; // default true jika join_date tak tersedia (aman)
        try {
            $join = Carbon::parse($joinDate)->startOfDay();
            $on   = Carbon::parse($onDate)->startOfDay();
            return $join->diffInDays($on) >= 365;
        } catch (\Throwable $e) {
            return true;
        }
    }
}
