<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Services\MekariClient;
use App\Services\OvertimeCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Tarik ringkasan absensi Talenta per tanggal, lengkapi metadata karyawan,
 * hitung lembur + premi hadir, lalu upsert ke tabel attendances.
 */
class FetchAttendanceToday extends Command
{
    protected $signature = 'fetch:attendance:today
        {--branch=21089 : Branch ID}
        {--job-levels=44480,44481,44482,44492 : Daftar job_level_id (whitelist, pisahkan koma)}
        {--from= : Tanggal awal (Y-m-d), default: today}
        {--to= : Tanggal akhir (Y-m-d), default: today}
        {--limit=200 : Batas per halaman di API (per_page)}';

    protected $description = 'Fetch Talenta attendance dan upsert ke DB (dengan metadata, lembur & premi hadir).';

    public function handle(): int
    {
        // ========= Parse opsi =========
        $branchId  = (int) $this->option('branch');
        $jobLevels = $this->parseJobLevels((string) $this->option('job-levels'));
        $limit     = max(1, (int) $this->option('limit'));

        $tz     = 'Asia/Jakarta';
        $today  = CarbonImmutable::now($tz)->toDateString();
        $from   = $this->option('from') ?: $today;
        $to     = $this->option('to')   ?: $today;

        try { $from = CarbonImmutable::parse($from, $tz)->toDateString(); } catch (\Throwable) { $from = $today; }
        try { $to   = CarbonImmutable::parse($to,   $tz)->toDateString(); } catch (\Throwable) { $to   = $today; }
        if ($from > $to) { [$from, $to] = [$to, $from]; }

        $this->info(sprintf(
            'Fetching %s → %s (branch %d) job-levels: %s',
            $from, $to, $branchId, ($jobLevels ? implode(',', $jobLevels) : '(all)')
        ));

        /** @var MekariClient $mekari */
        $mekari = app(MekariClient::class);
        /** @var OvertimeCalculator $calc */
        $calc   = app(OvertimeCalculator::class);

        $inserted = 0;
        $updated  = 0;

        // ========= Loop per tanggal =========
        for ($cursor = CarbonImmutable::parse($from, $tz);
             $cursor->lessThanOrEqualTo(CarbonImmutable::parse($to, $tz));
             $cursor = $cursor->addDay()) {

            $dateYmd = $cursor->toDateString();

            // 1) Ambil summary+detail per tanggal (sudah di-enrich MekariClient)
            $rows = $mekari->fetchDate($dateYmd, $branchId, $limit, $jobLevels);
            if (!$rows) {
                $this->line("  - {$dateYmd} : no data");
                continue;
            }

            foreach ($rows as $row) {
                // 2) Hitung lembur/premi via service
                $row = $calc->apply($row);

                // ---- Normalisasi & fallback kalkulasi yang wajib ada ----
                $scheduleDate = substr((string)($row['schedule_date'] ?? $dateYmd), 0, 10);
                $joinDate     = $row['join_date'] ?? null;

                // a) Masa kerja >= 1 tahun pada tanggal tsb
                $tenureGE1 = true;
                if ($joinDate) {
                    try {
                        $jd = new \DateTimeImmutable($joinDate);
                        $sd = new \DateTimeImmutable($scheduleDate);
                        $tenureGE1 = $jd->add(new \DateInterval('P1Y')) <= $sd;
                    } catch (\Throwable) { $tenureGE1 = true; }
                }

                // b) Jam billable harian (cap 7 jam)
                $realHours          = (float)($row['real_work_hour'] ?? 0);
                $dailyBillableHours = min(7.0, max(0.0, $realHours));

                // c) Tarif per jam yang dipakai (ambil dari service jika ada; fallback ke 0)
                $hourlyRateUsed = (int)($row['hourly_rate_used'] ?? ($row['hourly_rate'] ?? 0));

                // d) Premi hadir: hanya untuk >= 1 tahun dan ada jam kerja
                $presenceMonthlyBase = 100_000;
                $presenceDaily       = ($tenureGE1 && $realHours > 0) ? intdiv($presenceMonthlyBase, 25) : 0; // 100000/25=4000

                // e) Potongan BPJS default (semua sama)
                $bpjsTk  = (int)($row['bpjs_tk_deduction']  ?? 146_115);
                $bpjsKes = (int)($row['bpjs_kes_deduction'] ?? 48_705);

                // 3) Kunci unik (employee_id + schedule_date + branch_id)
                $unique = [
                    'employee_id'   => (string)($row['employee_id'] ?? ''),
                    'schedule_date' => $scheduleDate,
                    'branch_id'     => (int)($row['branch_id'] ?? $branchId),
                ];

                // 4) Payload sesuai migration (+ timeoff_id & timeoff_name BARU)
                $payload = [
                    // ===== IDENTITAS / EMPLOYEE =====
                    'user_id'            => $row['user_id'] ?? null,
                    'full_name'          => (string)($row['full_name'] ?? ''),
                    'gender'             => $row['gender'] ?? null,
                    'join_date'          => $joinDate,

                    // ===== CABANG / ORG / JOB =====
                    'branch_name'        => $row['branch_name'] ?? null,
                    'organization_id'    => $this->toNullableInt($row['organization_id'] ?? null),
                    'organization_name'  => $row['organization_name'] ?? null,
                    'job_position_id'    => $this->toNullableInt($row['job_position_id'] ?? null),
                    'job_position'       => $row['job_position'] ?? null,
                    'job_level_id'       => $this->toNullableInt($row['job_level_id'] ?? null),
                    'job_level'          => $row['job_level'] ?? null,

                    // ===== ABSEN =====
                    'clock_in'           => $row['clock_in'] ?? null,
                    'clock_out'          => $row['clock_out'] ?? null,
                    'real_work_hour'     => $realHours,
                    'shift_name'         => $row['shift_name'] ?? null,
                    'attendance_code'    => $row['attendance_code'] ?? null,
                    'holiday'            => (bool)($row['holiday'] ?? false),

                    // ===== TIMEOFF (BARU) =====
                    'timeoff_id'         => $row['timeoff_id']   ?? null,
                    'timeoff_name'       => $row['timeoff_name'] ?? null,

                    // ===== LEMBUR (RUPIAH) =====
                    'overtime_hours'         => (int)($row['overtime_hours'] ?? 0),
                    'overtime_first_amount'  => (int)($row['overtime_first_amount'] ?? 0),
                    'overtime_second_amount' => (int)($row['overtime_second_amount'] ?? 0),
                    'overtime_total_amount'  => (int)($row['overtime_total_amount'] ?? 0),

                    // ===== AUDIT & KALKULASI =====
                    'hourly_rate_used'     => $hourlyRateUsed,
                    'daily_billable_hours' => $dailyBillableHours,
                    'daily_total_amount'   => (int)($row['daily_total_amount'] ?? 0),
                    'tenure_ge_1y'         => $tenureGE1,

                    // ===== PREMI HADIR & POTONGAN (default) =====
                    'presence_premium_monthly_base' => (int)($row['presence_premium_monthly_base'] ?? $presenceMonthlyBase),
                    'presence_premium_daily'        => (int)($row['presence_premium_daily'] ?? $presenceDaily),
                    'bpjs_tk_deduction'             => $bpjsTk,
                    'bpjs_kes_deduction'            => $bpjsKes,
                ];

                // 5) Upsert
                $exists = Attendance::query()->where($unique)->first(['id']);
                if ($exists) {
                    Attendance::whereKey($exists->id)->update($payload);
                    $updated++;
                } else {
                    Attendance::create(array_merge($unique, $payload));
                    $inserted++;
                }
            }

            $this->line(sprintf("  - %s : %d row(s)", $dateYmd, count($rows)));
            usleep(100_000); // jeda kecil antar tanggal (ramah API)
        }

        $this->info("Done. Inserted: {$inserted}, Updated: {$updated}.");
        return self::SUCCESS;
    }

    /** Parse CSV job level ids (string → array unik) */
    private function parseJobLevels(string $csv): array
    {
        $parts = array_map('trim', explode(',', $csv));
        $parts = array_filter($parts, fn($x) => $x !== '');
        return array_values(array_unique($parts));
    }

    /** Konversi ke int nullable */
    private function toNullableInt($val): ?int
    {
        if ($val === null || $val === '' || $val === '—') return null;
        return is_numeric($val) ? (int)$val : null;
    }
}
