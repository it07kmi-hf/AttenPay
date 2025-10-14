<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();

            // Data dasar
            $table->string('employee_id');         // cari & sort pakai composite index di bawah
            $table->string('full_name');           // jika mau cari by nama, tetap simpan index tunggal (opsional)
            $table->date('schedule_date');         // bagian dari composite index
            $table->time('clock_in')->nullable();
            $table->time('clock_out')->nullable();
            $table->decimal('real_work_hour', 5, 2)->default(0);

            // Kolom kalkulasi
            $table->unsignedSmallInteger('overtime_hours')->default(0);
            $table->unsignedInteger('overtime_first_amount')->default(0);
            $table->unsignedInteger('overtime_second_amount')->default(0);
            $table->unsignedInteger('overtime_total_amount')->default(0);

            // Metadata
            $table->unsignedBigInteger('branch_id');
            $table->string('shift_name')->nullable();
            $table->string('attendance_code', 10)->nullable();
            $table->boolean('holiday')->default(false);

            $table->timestamps();

            // Unik untuk upsert (aman diulang)
            $table->unique(['employee_id','schedule_date','branch_id'], 'uniq_emp_date_branch');

            // ✅ Index komposit utama (filter cepat)
            $table->index(['branch_id', 'schedule_date'], 'idx_att_branch_date');

            // ✅ Index komposit untuk filter+sort (ORDER BY schedule_date, employee_id)
            $table->index(['branch_id', 'schedule_date', 'employee_id'], 'idx_att_branch_date_emp');

            // (Opsional) index tunggal bila sering cari nama/ID via prefix
            $table->index('employee_id', 'idx_att_empid');
            $table->index('full_name', 'idx_att_fullname');

            // (Opsional) FULLTEXT (MySQL 8+) untuk pencarian nama cepat:
            // $table->fullText(['full_name','employee_id'], 'ft_att_name_emp');
        });
    }

    public function down(): void {
        Schema::dropIfExists('attendances');
    }
};
