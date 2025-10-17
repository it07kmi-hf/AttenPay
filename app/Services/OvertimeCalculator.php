<?php

namespace App\Services;

/**
 * Hitung lembur + metadata gaji harian.
 * - Disiapkan juga kolom untuk "premi hadir" (khusus masa kerja >= 1 tahun)
 *   dan default potongan BPJS (bulanan) sebagai metadata.
 */
final class OvertimeCalculator
{
    // Upah bulanan sesuai PDF (bisa kamu pindah ke .env)
    private const MONTHLY_UNDER_1Y = 4_870_511; // < 1 tahun
    private const MONTHLY_GE_1Y    = 4_895_511; // >= 1 tahun (sudah termasuk tunj. jabatan 25rb)

    // Premi hadir (bulanan) + hari kerja dianggap 25 hari → rate harian
    private const PRESENCE_PREMIUM_MONTHLY = 100_000;
    private const WORK_DAYS_PER_MONTH      = 25;

    // Potongan BPJS default (bulanan, disimpan sbg metadata)
    private const BPJS_TK  = 146_115; // Ketenagakerjaan
    private const BPJS_KES = 48_705;  // Kesehatan

    /**
     * @param array $row Satu baris attendance (punya schedule_date, join_date, real_work_hour, holiday, dll.)
     * @return array Row yang sama + field kalkulasi (hourly_rate_used, OT, premi hadir per hari, BPJS meta, dst.)
     */
    public function apply(array $row): array
    {
        $dateStr   = (string)($row['schedule_date'] ?? date('Y-m-d'));
        $joinDate  = $row['join_date'] ?? null;
        $isSunday  = (new \DateTimeImmutable($dateStr))->format('w') === '0';
        $isHoliday = (bool)($row['holiday'] ?? false);
        $hoursReal = (float)($row['real_work_hour'] ?? 0.0);
        $hoursInt  = max(0, (int)floor($hoursReal)); // contoh PDF pakai jam bulat

        // Cek masa kerja >= 1 tahun pada tanggal schedule_date
        $geOneYear = false;
        if ($joinDate) {
            try {
                $jd  = new \DateTimeImmutable($joinDate);
                $sd  = new \DateTimeImmutable($dateStr);
                $diff= $jd->diff($sd);
                $geOneYear = ($diff->y >= 1);
            } catch (\Throwable) {
                $geOneYear = true; // fallback aman
            }
        } else {
            $geOneYear = true; // kalau join_date kosong, anggap >= 1 tahun
        }

        // Gaji acuan & rate dasar
        $monthly     = $geOneYear ? self::MONTHLY_GE_1Y : self::MONTHLY_UNDER_1Y;
        $hourlyRate  = (int) round($monthly / 173); // 173 jam/bulan
        $dailyWage   = (int) round($monthly / 25);  // upah harian normatif (25 hari kerja)

        // Lembur
        $otHours = 0;
        $otL1 = 0; $otL2 = 0;
        $otTotal = 0;

        if ($isSunday || $isHoliday) {
            // Minggu/Libur: jam 1–8 = 2×; jam >=9 = 3×
            $first8 = min(8, $hoursInt);
            $after8 = max(0, $hoursInt - 8);
            $otL1   = (int) round($first8 * 2 * $hourlyRate);
            $otL2   = (int) round($after8 * 3 * $hourlyRate);
            $otTotal= $otL1 + $otL2;
            $otHours= $hoursInt; // untuk pelaporan
        } else {
            // Hari kerja normal (7 jam); sisa jam dianggap lembur.
            $otHours = max(0, $hoursInt - 7);
            // (opsional) batasi 4 jam/hari
            $otHours = min($otHours, 4);

            $first = min(1, $otHours);
            $rest  = max(0, $otHours - 1);
            $otL1  = (int) round($first * 1.5 * $hourlyRate);
            $otL2  = (int) round($rest  * 2.0 * $hourlyRate);
            $otTotal = $otL1 + $otL2;
        }

        // Premi hadir:
        // - Khusus karyawan >= 1 tahun
        // - Skema bulanan: 100.000 dibagi rata 25 hari → rate per hari = 4.000.
        // - Kreditkan per hari jika "hadir/ada jam kerja". Nanti direkap bulanan (sum).
        $premMonthly = $geOneYear ? self::PRESENCE_PREMIUM_MONTHLY : 0;
        $premDaily   = $geOneYear ? (int) floor(self::PRESENCE_PREMIUM_MONTHLY / self::WORK_DAYS_PER_MONTH) : 0;
        $premEarned  = ($geOneYear && $hoursInt > 0) ? $premDaily : 0;

        return array_merge($row, [
            // Rate/jam + ringkasan normatif harian
            'hourly_rate_used'        => $hourlyRate,
            'daily_total_amount'      => $dailyWage,

            // Lembur (report per hari)
            'overtime_hours'          => (int)$otHours,
            'overtime_first_amount'   => (int)$otL1,
            'overtime_second_amount'  => (int)$otL2,
            'overtime_total_amount'   => (int)$otTotal,

            // Premi hadir (metadata + kredit per hari)
            'presence_premium_monthly'      => $premMonthly, // metadata: nilai bulanan (100k jika eligible)
            'presence_premium_day_rate'     => $premDaily,   // metadata: 100k/25 = 4k
            'presence_premium_day_earned'   => $premEarned,  // kredit untuk hari ini (0/4k)

            // Potongan BPJS (metadata bulanan; disimpan sama tiap baris agar gampang direkap)
            'bpjs_tk_deduction_monthly'     => self::BPJS_TK,
            'bpjs_kes_deduction_monthly'    => self::BPJS_KES,
        ]);
    }
}
