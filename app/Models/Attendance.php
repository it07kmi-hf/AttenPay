<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Attendance extends Model
{
    protected $fillable = [
        'employee_id','full_name','schedule_date','clock_in','clock_out',
        'real_work_hour','overtime_hours','overtime_first_amount','overtime_second_amount',
        'overtime_total_amount','branch_id','shift_name','attendance_code','holiday',
    ];

    protected $casts = [
        'schedule_date' => 'date:Y-m-d',
        'holiday' => 'boolean',
    ];
}
