<?php

namespace App\Services;

/**
 * Hitung lembur + metadata gaji harian.
 * - Jika tidak hadir (jam kerja 0 atau clock in/out kosong) maka semua nilai uang/jam = 0.
 * - Premi hadir harian (4.000) hanya diberikan jika masa kerja >= 1 tahun **dan** ada jam kerja (>0).
 */
final class OvertimeCalculator
{
    // Upah bulanan (boleh pindah ke .env)
    private const MONTHLY_UNDER_1Y = 4_870_511; // < 1 tahun
    private const MONTHLY_GE_1Y    = 4_895_511; // >= 1 tahun (termasuk tunj. jabatan 25rb)

    // Premi hadir bulanan dan asumsi hari kerja
    private const PRESENCE_PREMIUM_MONTHLY = 100_000;
    private const WORK_DAYS_PER_MONTH      = 25;

    // Potongan BPJS default (bulanan) — disimpan sebagai metadata per baris
    private const BPJS_TK  = 146_115;
    private const BPJS_KES = 48_705;

    public function apply(array $row): array
    {
        $dateStr   = (string)($row['schedule_date'] ?? date('Y-m-d'));
        $joinDate  = $row['join_date'] ?? null;
        $isHoliday = (bool)($row['holiday'] ?? false);

        // Jam kerja real dibulatkan ke bawah ke jam bulat (sesuai contoh)
        $hoursReal = (float)($row['real_work_hour'] ?? 0.0);
        $hoursInt  = max(0, (int)floor($hoursReal));

        $clockIn   = trim((string)($row['clock_in']  ?? ''));
        $clockOut  = trim((string)($row['clock_out'] ?? ''));

        // === Tenure (>= 1 tahun?) ===
        $geOneYear = false;
        if ($joinDate) {
            try {
                $jd = new \DateTimeImmutable($joinDate);
                $sd = new \DateTimeImmutable($dateStr);
                $geOneYear = ($jd->diff($sd)->y >= 1);
            } catch (\Throwable) {
                $geOneYear = true; // fallback aman
            }
        } else {
            $geOneYear = true; // kalau join_date kosong, anggap >= 1 tahun
        }

        // === Rate dasar (hanya dipakai jika hadir) ===
        $monthly    = $geOneYear ? self::MONTHLY_GE_1Y : self::MONTHLY_UNDER_1Y;
        $hourlyRate = (int) round($monthly / 173); // 173 jam/bulan

        // === Deteksi hadir ===
        // Definisi sederhana: ada jam kerja > 0 DAN punya clock in & out.
        $isPresent  = ($hoursInt > 0 && $clockIn !== '' && $clockOut !== '');

        // === Billable hours (maks 7 di hari kerja normal), 0 kalau tidak hadir ===
        $billableH = $isPresent ? min(7, $hoursInt) : 0;

        // === Lembur (0 kalau tidak hadir) ===
        $otHours = 0; $otL1 = 0; $otL2 = 0; $otTotal = 0;

        if ($isPresent) {
            // Minggu/Libur pakai kode (2x untuk jam 1–8, 3x setelahnya)
            $isSunday = (new \DateTimeImmutable($dateStr))->format('w') === '0';

            if ($isSunday || $isHoliday) {
                $first8 = min(8, $hoursInt);
                $after8 = max(0, $hoursInt - 8);
                $otL1   = (int) round($first8 * 2 * $hourlyRate);
                $otL2   = (int) round($after8 * 3 * $hourlyRate);
                $otTotal= $otL1 + $otL2;
                $otHours= $hoursInt;
            } else {
                // Hari kerja normal: yang >7 dianggap lembur (opsional batasi 4/jam)
                $otHours = min(max(0, $hoursInt - 7), 4);
                $first = min(1, $otHours);
                $rest  = max(0, $otHours - 1);
                $otL1  = (int) round($first * 1.5 * $hourlyRate);
                $otL2  = (int) round($rest  * 2.0 * $hourlyRate);
                $otTotal = $otL1 + $otL2;
            }
        }

        // === Gaji dasar harian (0 kalau tidak hadir) ===
        $baseSalary = (int) round($billableH * $hourlyRate);

        // === Total harian (0 kalau tidak hadir) ===
        $dailyTotal = $isPresent ? ($baseSalary + $otTotal) : 0;

        // === Premi hadir: hanya jika >=1y & hadir ===
        $premDailyRate = $geOneYear ? (int) floor(self::PRESENCE_PREMIUM_MONTHLY / self::WORK_DAYS_PER_MONTH) : 0;
        $premEarned    = ($geOneYear && $isPresent) ? $premDailyRate : 0;

        // === Nilai yang disimpan ke DB ===
        return array_merge($row, [
            // Informasi tenure
            'tenure_ge_1y'          => $geOneYear,

            // Rate & perhitungan (semua 0 jika tidak hadir)
            'hourly_rate_used'      => $isPresent ? $hourlyRate : 0,
            'daily_billable_hours'  => $billableH,
            'overtime_hours'        => $otHours,
            'overtime_first_amount' => $otL1,
            'overtime_second_amount'=> $otL2,
            'overtime_total_amount' => $otTotal,
            'daily_total_amount'    => $dailyTotal,

            // Premi hadir
            'presence_premium_daily' => $premEarned, // yang dipakai FE
            // (opsional metadata)
            'presence_premium_monthly_base' => $geOneYear ? self::PRESENCE_PREMIUM_MONTHLY : 0,

            // BPJS (metadata bulanan; tetap disimpan per baris agar mudah direkap)
            'bpjs_tk_deduction'  => self::BPJS_TK,
            'bpjs_kes_deduction' => self::BPJS_KES,
        ]);
    }
}
