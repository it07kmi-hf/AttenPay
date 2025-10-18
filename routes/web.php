<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AttendanceController;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
});

Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

Route::middleware('auth')->group(function () {
    Route::get('/', fn () => redirect()->route('attendance.index'));

    // Halaman utama attendance
    Route::get('/attendance', [AttendanceController::class, 'index'])->name('attendance.index');

    // Export lama (CSV / Excel CSV / PDF tabel rekap)
    Route::get('/attendance/export', [AttendanceController::class, 'export'])->name('attendance.export');

    /**
     * NEW: Export PDF payslip 1 bulan.
     * Query:
     *   - month=YYYY-MM (wajib)
     *   - branch_id=21089 (opsional, default sama seperti list)
     *   - organization_id=<id|all> (opsional)
     *   - name=<partial name> (OPSIONAL; jika kosong → semua karyawan di bulan tsb)
     *
     * Contoh:
     *   /attendance/export-payslips?month=2025-10&branch_id=21089&name=Rudi
     *   /attendance/export-payslips?month=2025-10&branch_id=21089        (semua)
     */
    Route::get(
        '/attendance/export-payslips',
        [AttendanceController::class, 'exportPayslipsPdf']
    )->name('attendance.exportPayslips');
});
