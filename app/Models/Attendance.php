<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Attendance extends Model
{
    /** Nama tabel (opsional karena default sudah "attendances") */
    protected $table = 'attendances';

    /**
     * Izinkan mass assignment untuk semua kolom.
     * (Kalau mau lebih ketat, ganti ke $fillable.)
     */
    protected $guarded = [];

    /** Casting kolom agar enak dipakai di aplikasi */
    protected $casts = [
        // ===== Tanggal =====
        'schedule_date' => 'date:Y-m-d',   // tanggal absensi
        'join_date'     => 'date:Y-m-d',   // tanggal bergabung karyawan

        // ===== Boolean =====
        'holiday'       => 'boolean',      // penanda tanggal merah/libur
        'tenure_ge_1y'  => 'boolean',      // masa kerja >= 1 tahun pada schedule_date

        // ===== Numerik/Jam =====
        'real_work_hour'       => 'float',   // jam kerja real (angka desimal)
        'daily_billable_hours' => 'float',   // jam ditagihkan (cap 7 jam sesuai aturan)

        // ===== Nominal (Rupiah) =====
        'hourly_rate_used'        => 'integer', // upah per jam yang dipakai kalkulasi
        'daily_total_amount'      => 'integer', // total harian (base + OT) sesuai audit
        'overtime_hours'          => 'integer', // jam lembur (dibulatkan sesuai aturan)
        'overtime_first_amount'   => 'integer', // nominal lembur blok pertama
        'overtime_second_amount'  => 'integer', // nominal lembur blok kedua/lebih
        'overtime_total_amount'   => 'integer', // total nominal lembur

        // ===== Premi Hadir & Potongan BPJS (disimpan per baris untuk laporan) =====
        'presence_premium_monthly_base' => 'integer', // premi hadir bulanan (mis. 100.000 untuk >=1 tahun)
        'presence_premium_daily'        => 'integer', // premi hadir per hari yang didapat
        'bpjs_tk_deduction'             => 'integer', // potongan BPJS Ketenagakerjaan (default sama)
        'bpjs_kes_deduction'            => 'integer', // potongan BPJS Kesehatan (default sama)

        // ===== ID/Relasi numerik =====
        'branch_id'        => 'integer',
        'organization_id'  => 'integer',
        'job_position_id'  => 'integer',
        'job_level_id'     => 'integer',

        // ===== Timeoff (izin/cuti) =====
        'timeoff_id'       => 'string',
        'timeoff_name'     => 'string',
    ];

    /* =======================
     *  Accessor bantu
     * ======================= */
    /**
     * is_present: true jika ada clock_in & clock_out dan real_work_hour > 0
     */
    public function getIsPresentAttribute(): bool
    {
        $in  = trim((string)($this->clock_in ?? ''));
        $out = trim((string)($this->clock_out ?? ''));
        $hrs = (float)($this->real_work_hour ?? 0);
        return $in !== '' && $out !== '' && $hrs > 0;
    }

    /* =======================
     *  Query Scopes bantu
     * ======================= */

    /** Filter per cabang */
    public function scopeForBranch($query, int $branchId)
    {
        return $query->where('branch_id', $branchId);
    }

    /** Filter tepat pada satu tanggal (YYYY-MM-DD) */
    public function scopeOnDate($query, string $dateYmd)
    {
        return $query->whereDate('schedule_date', $dateYmd);
    }

    /** Filter rentang tanggal (YYYY-MM-DD s/d YYYY-MM-DD) */
    public function scopeBetweenDates($query, string $fromYmd, string $toYmd)
    {
        return $query->whereBetween('schedule_date', [$fromYmd, $toYmd]);
    }

    /** Filter whitelist job level */
    public function scopeJobLevels($query, array $levelIds)
    {
        if (empty($levelIds)) return $query;
        return $query->whereIn('job_level_id', $levelIds);
    }

    /** Pencarian sederhana nama/ID karyawan */
    public function scopeSearch($query, ?string $q)
    {
        $q = trim((string)$q);
        if ($q === '') return $query;

        return $query->where(function ($qq) use ($q) {
            $qq->where('full_name', 'like', "%{$q}%")
               ->orWhere('employee_id', 'like', "%{$q}%")
               ->orWhere('user_id', 'like', "%{$q}%");
        });
    }

    /** Filter hanya baris yang punya timeoff (izin/cuti) */
    public function scopeHasTimeoff($query)
    {
        return $query->whereNotNull('timeoff_id')->where('timeoff_id', '!=', '');
    }

    /** Filter berdasarkan kode/ID timeoff tertentu */
    public function scopeTimeoff($query, $timeoffId)
    {
        if (is_array($timeoffId)) {
            return $query->whereIn('timeoff_id', $timeoffId);
        }
        return $query->where('timeoff_id', $timeoffId);
    }

    /** Filter baris tanpa timeoff (hadir/alpha tanpa data izin) */
    public function scopeNoTimeoff($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('timeoff_id')->orWhere('timeoff_id', '');
        });
    }

    /*
     * --- Alternatif: kalau lebih suka whitelist kolom, gunakan ini
     * (GANTI $guarded = [] di atas dengan $fillable berikut)
     *
     * protected $fillable = [
     *   // identitas & org
     *   'schedule_date','user_id','employee_id','full_name','gender','join_date',
     *   'branch_id','branch_name','organization_id','organization_name',
     *   'job_position_id','job_position','job_level_id','job_level',
     *
     *   // absensi
     *   'clock_in','clock_out','real_work_hour','shift_name','attendance_code','holiday',
     *
     *   // timeoff
     *   'timeoff_id','timeoff_name',
     *
     *   // lembur
     *   'overtime_hours','overtime_first_amount','overtime_second_amount','overtime_total_amount',
     *
     *   // audit & kalkulasi
     *   'hourly_rate_used','daily_billable_hours','daily_total_amount','tenure_ge_1y',
     *
     *   // premi hadir & potongan
     *   'presence_premium_monthly_base','presence_premium_daily',
     *   'bpjs_tk_deduction','bpjs_kes_deduction',
     * ];
     */
}
