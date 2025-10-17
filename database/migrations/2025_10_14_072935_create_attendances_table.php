<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();

            /*
             |------------------------------------------------------------------
             | 1) Employee identity & organization (URUTAN UTAMA)
             |------------------------------------------------------------------
             */
            $table->date('schedule_date')->comment('Tanggal absensi (Y-m-d)');

            $table->string('user_id')->nullable()->comment('Talenta user_id (tipe string agar fleksibel)');
            $table->string('employee_id')->comment('ID unik karyawan dari Talenta');
            $table->string('full_name')->comment('Nama lengkap karyawan');
            $table->string('gender', 20)->nullable()->comment('Jenis kelamin');
            $table->date('join_date')->nullable()->comment('Tanggal masuk kerja (Y-m-d)');

            $table->unsignedBigInteger('branch_id')->comment('ID cabang');
            $table->string('branch_name')->nullable()->comment('Nama cabang');

            $table->unsignedBigInteger('organization_id')->nullable()->comment('ID organisasi / departemen');
            $table->string('organization_name')->nullable()->comment('Nama organisasi / departemen');

            $table->unsignedBigInteger('job_position_id')->nullable()->comment('ID jabatan');
            $table->string('job_position')->nullable()->comment('Nama jabatan');

            $table->unsignedBigInteger('job_level_id')->nullable()->comment('ID level jabatan');
            $table->string('job_level')->nullable()->comment('Nama level jabatan');

            /*
             |------------------------------------------------------------------
             | 2) Daily attendance & overtime (sesuai urutan permintaan)
             |------------------------------------------------------------------
             */
            $table->time('clock_in')->nullable()->comment('Jam masuk (HH:MM:SS) tanpa tanggal');
            $table->time('clock_out')->nullable()->comment('Jam pulang (HH:MM:SS) tanpa tanggal');
            $table->decimal('real_work_hour', 5, 2)->default(0.00)->comment('Total jam kerja aktual per hari');

            $table->unsignedSmallInteger('overtime_hours')->default(0)->comment('Total jam lembur (bulat)');
            $table->unsignedInteger('overtime_first_amount')->default(0)->comment('Nominal lembur blok pertama (1.5× / 2×)');
            $table->unsignedInteger('overtime_second_amount')->default(0)->comment('Nominal lembur blok berikutnya (2× / 3×)');
            $table->unsignedInteger('overtime_total_amount')->default(0)->comment('Total nominal lembur per hari');

            /*
             |------------------------------------------------------------------
             | 3) Attendance meta (opsional tampilan/laporan)
             |------------------------------------------------------------------
             */
            $table->string('shift_name')->nullable()->comment('Nama shift');
            $table->string('attendance_code', 10)->nullable()->comment('Kode status absensi');
            $table->boolean('holiday')->default(false)->comment('Penanda hari libur / tanggal merah');

            /*
             |------------------------------------------------------------------
             | 4) Audit & payroll parameters (aturan PDF + tambahan premi/BPJS)
             |------------------------------------------------------------------
             */
            $table->unsignedInteger('hourly_rate_used')->default(0)->comment('Tarif per jam yang dipakai (IDR/jam)');
            $table->decimal('daily_billable_hours', 4, 2)->default(0.00)->comment('Jam tagih harian (cap 7 jam sesuai aturan)');
            $table->unsignedInteger('daily_total_amount')->default(0)->comment('Nominal harian final (gaji dasar + lembur)');
            $table->boolean('tenure_ge_1y')->default(true)->comment('Masa kerja >= 1 tahun pada tanggal absensi');

            // Premi hadir (khusus karyawan tenure >= 1 tahun, prorata 25 hari kerja/bulan)
            $table->unsignedInteger('presence_premium_monthly_base')->default(100000)->comment('Nominal premi hadir bulanan (tenure >= 1 tahun)');
            $table->unsignedInteger('presence_premium_daily')->default(0)->comment('Premi hadir harian (prorata base/25; 0 jika absen/libur)');

            // Potongan BPJS (default bulanan, dipakai saat rekap)
            $table->unsignedInteger('bpjs_tk_deduction')->default(146115)->comment('Potongan BPJS Ketenagakerjaan (bulanan, default)');
            $table->unsignedInteger('bpjs_kes_deduction')->default(48705)->comment('Potongan BPJS Kesehatan (bulanan, default)');

            $table->timestamps();

            /*
             |------------------------------------------------------------------
             | Indexes & constraints
             |------------------------------------------------------------------
             */
            $table->unique(['employee_id','schedule_date','branch_id'], 'uniq_emp_date_branch');

            $table->index(['branch_id', 'schedule_date'], 'idx_att_branch_date');
            $table->index(['branch_id', 'schedule_date', 'employee_id'], 'idx_att_branch_date_emp');

            $table->index('user_id', 'idx_att_userid');
            $table->index('employee_id', 'idx_att_empid');
            $table->index('full_name', 'idx_att_fullname');

            $table->index('job_level_id', 'idx_att_job_level_id');
            $table->index('job_position_id', 'idx_att_job_pos_id');
            $table->index('organization_id', 'idx_att_org_id');
            $table->index('join_date', 'idx_att_join_date');

            $table->comment('Snapshot absensi harian karyawan: identitas, organisasi, jam kerja, lembur, premi hadir & potongan BPJS.');
        });
    }

    public function down(): void {
        Schema::dropIfExists('attendances');
    }
};
