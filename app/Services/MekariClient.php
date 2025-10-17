<?php

namespace App\Services;

use GuzzleHttp\Client;

/**
 * Client HMAC untuk API Mekari/Talenta.
 * - Semua comment pakai bahasa Indonesia biar gampang dibaca.
 * - HANYA ada 1 class di file ini (MekariClient), sesuai PSR-4.
 */
class MekariClient
{
    public function __construct(
        private readonly string $baseUrl   = 'https://api.mekari.com',
        private readonly string $sumEP     = '/v2/talenta/v3/attendance/summary-report',
        private readonly string $empEP     = '/v2/talenta/v2/employee',
        private readonly string $username  = 'hdKdYmAJUWcXORmB',
        private readonly string $secret    = 'uYR6DXtaBNCW3CZnxbuVD8t8gwQ9hA1P',
        private readonly Client $http      = new Client(),
    ) {}

    /** cache detail per request (kurangi hit API) */
    private array $empCache = [];

    /** Buat header HMAC Mekari (Authorization + Date) */
    private function hmacHeaders(string $method, string $fullUrl): array
    {
        $u = parse_url($fullUrl);
        $path = ($u['path'] ?? '/').(isset($u['query']) ? '?'.$u['query'] : '');
        $date = gmdate('D, d M Y H:i:s T');
        $sig  = base64_encode(hash_hmac('sha256', "date: {$date}\n{$method} {$path} HTTP/1.1", $this->secret, true));
        $auth = 'hmac username="'.$this->username.'", algorithm="hmac-sha256", headers="date request-line", signature="'.$sig.'"';
        return ['Authorization'=>$auth,'Date'=>$date,'Accept'=>'application/json'];
    }

    /** Ambil HH:MM:SS saja; jika kosong → null */
    private function timeOnly(string $v): ?string
    {
        if ($v === '') return null;
        if (preg_match('/\b(\d{2}:\d{2}:\d{2})\b/', $v, $m)) return $m[1];
        return $v;
    }

    /** Ambil user_id dari beberapa kemungkinan field response */
    private function pickUserId(array $r): ?string
    {
        foreach ([
            $r['user_id'] ?? null,
            $r['employee_user_id'] ?? null,
            $r['user']['id'] ?? null,
        ] as $v) {
            if ($v !== null && $v !== '') return (string)$v;
        }
        return null;
    }

    /** Normalisasi gender ke 'Male' / 'Female' (kalau bisa) */
    private function normalizeGender(mixed $val): ?string
    {
        if ($val === null || $val === '') return null;
        if (is_numeric($val)) {
            $n = (int)$val; return $n===1 ? 'Male' : ($n===2 ? 'Female' : null);
        }
        $s = strtolower(trim((string)$val));
        return match ($s) {
            'male','m','l','pria','laki-laki' => 'Male',
            'female','f','p','wanita','perempuan' => 'Female',
            default => ucfirst($s),
        };
    }

    /** Format Y-m-d aman */
    private function ymdOrNull(?string $s): ?string
    {
        if (!$s) return null;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s;
        $ts = strtotime($s);
        return $ts ? date('Y-m-d', $ts) : null;
    }

    /** Ambil detail employee dari /employee/{user_id} dan normalisasi field. */
    private function fetchEmployeeDetail(string $userId): array
    {
        if (isset($this->empCache[$userId])) return $this->empCache[$userId];

        $url = rtrim($this->baseUrl,'/').rtrim($this->empEP,'/').'/'.rawurlencode($userId);
        try {
            $res = $this->http->request('GET', $url, [
                'headers' => $this->hmacHeaders('GET', $url),
                'timeout' => 30,
            ]);
            if ($res->getStatusCode() < 200 || $res->getStatusCode() >= 300) {
                return $this->empCache[$userId] = [];
            }
            $j = json_decode((string)$res->getBody(), true) ?: [];
        } catch (\Throwable) {
            return $this->empCache[$userId] = [];
        }

        $emp        = $j['data']['employee'] ?? [];
        $personal   = $emp['personal'] ?? [];
        $employment = $emp['employment'] ?? [];

        $gender = $personal['gender'] ?? ($emp['gender'] ?? ($j['data']['user']['gender'] ?? null));
        $gender = $this->normalizeGender($gender);

        return $this->empCache[$userId] = [
            'gender'            => $gender,
            'organization_id'   => isset($employment['organization_id']) ? (int)$employment['organization_id'] : null,
            'organization_name' => $employment['organization_name'] ?? null,
            'job_position_id'   => isset($employment['job_position_id']) ? (int)$employment['job_position_id'] : null,
            'job_position'      => $employment['job_position'] ?? null,
            'job_level_id'      => isset($employment['job_level_id']) ? (int)$employment['job_level_id'] : null,
            'job_level'         => $employment['job_level'] ?? null,
            'branch_id'         => isset($employment['branch_id']) ? (int)$employment['branch_id'] : null,
            'branch_name'       => $employment['branch'] ?? null,
            'join_date'         => $this->ymdOrNull($employment['join_date'] ?? null),
        ];
    }

    /**
     * Tarik semua halaman summary untuk 1 tanggal lalu enrich dengan detail karyawan.
     * $onlyJobLevelIds: jika diisi, filter baris berdasarkan job_level_id sesudah enrich.
     */
    public function fetchDate(string $dateYmd, int $branchId, int $limit = 200, array $onlyJobLevelIds = []): array
    {
        $rows = [];
        $next = rtrim($this->baseUrl,'/').$this->sumEP."?date={$dateYmd}&branch_id={$branchId}&limit={$limit}&page=1";

        while ($next) {
            $res = $this->http->request('GET', $next, [
                'headers' => $this->hmacHeaders('GET', $next),
                'timeout' => 30,
            ]);
            if ($res->getStatusCode() < 200 || $res->getStatusCode() >= 300) break;

            $json  = json_decode((string)$res->getBody(), true) ?: [];
            $items = $json['data']['summary_attendance_report'] ?? [];

            foreach ($items as $r) {
                $uid      = $this->pickUserId($r);
                $clockIn  = $this->timeOnly((string)($r['clock_in']  ?? ''));
                $clockOut = $this->timeOnly((string)($r['clock_out'] ?? ''));

                $row = [
                    // summary dari endpoint attendance
                    'user_id'          => $uid,
                    'employee_id'      => (string)($r['employee_id'] ?? ''),
                    'full_name'        => (string)($r['full_name'] ?? ''),
                    'schedule_date'    => (string)($r['schedule_date'] ?? $dateYmd),
                    'clock_in'         => $clockIn,
                    'clock_out'        => $clockOut,
                    'real_work_hour'   => (float)($r['real_work_hour'] ?? 0),
                    'branch_id'        => (int)($r['branch_id'] ?? $branchId),
                    'shift_name'       => (string)($r['shift_name'] ?? ''),
                    'attendance_code'  => (string)($r['attendance_code'] ?? ''),
                    'holiday'          => (bool)($r['holiday'] ?? false),

                    // placeholder detail (diisi di bawah)
                    'branch_name'       => null,
                    'gender'            => null,
                    'organization_id'   => null,
                    'organization_name' => null,
                    'job_position_id'   => null,
                    'job_position'      => null,
                    'job_level_id'      => null,
                    'job_level'         => null,
                    'join_date'         => null,
                ];

                // enrich detail employee
                if ($uid) {
                    $d = $this->fetchEmployeeDetail($uid);
                    if (!empty($d)) {
                        $row['gender']            = $d['gender']            ?? $row['gender'];
                        $row['organization_id']   = $d['organization_id']   ?? $row['organization_id'];
                        $row['organization_name'] = $d['organization_name'] ?? $row['organization_name'];
                        $row['job_position_id']   = $d['job_position_id']   ?? $row['job_position_id'];
                        $row['job_position']      = $d['job_position']      ?? $row['job_position'];
                        $row['job_level_id']      = $d['job_level_id']      ?? $row['job_level_id'];
                        $row['job_level']         = $d['job_level']         ?? $row['job_level'];
                        $row['join_date']         = $d['join_date']         ?? $row['join_date'];
                        if (!empty($d['branch_id']))   $row['branch_id']   = (int)$d['branch_id'];
                        if (!empty($d['branch_name'])) $row['branch_name'] = (string)$d['branch_name'];
                    }
                }

                $rows[] = $row;
            }

            $next = $json['data']['pagination']['next_page_url'] ?? '';
            if ($next && str_starts_with($next, '/')) {
                $next = rtrim($this->baseUrl,'/').$next;
            }
            if ($next) usleep(100000);
        }

        // filter whitelist job level (setelah enrich)
        if (!empty($onlyJobLevelIds)) {
            $allow = array_map('intval', $onlyJobLevelIds);
            $rows = array_values(array_filter($rows, function(array $r) use ($allow): bool {
                $jl = $r['job_level_id'] ?? null;
                return $jl !== null && in_array((int)$jl, $allow, true);
            }));
        }

        return $rows;
    }
}
