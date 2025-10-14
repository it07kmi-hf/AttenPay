<?php

namespace App\Services;

use GuzzleHttp\Client;

class MekariClient
{
    public function __construct(
        private readonly string $baseUrl = 'https://api.mekari.com',
        private readonly string $endpoint = '/v2/talenta/v3/attendance/summary-report',
        private readonly string $username = 'hdKdYmAJUWcXORmB',
        private readonly string $secret   = 'uYR6DXtaBNCW3CZnxbuVD8t8gwQ9hA1P',
        private readonly Client $http = new Client(),
    ) {}

    private function hmacHeaders(string $method, string $fullUrl): array
    {
        $u = parse_url($fullUrl);
        $path = ($u['path'] ?? '/').(isset($u['query'])?'?'.$u['query']:'');
        $date = gmdate('D, d M Y H:i:s T');
        $stringToSign = "date: {$date}\n{$method} {$path} HTTP/1.1";
        $sig = base64_encode(hash_hmac('sha256', $stringToSign, $this->secret, true));
        $auth = 'hmac username="'.$this->username.'", algorithm="hmac-sha256", headers="date request-line", signature="'.$sig.'"';
        return [
            'Authorization' => $auth,
            'Date'          => $date,
            'Accept'        => 'application/json',
        ];
    }

    // returns flat rows for the date (all pages) with fields we store
    public function fetchDate(string $dateYmd, int $branchId, int $limit = 200): array
    {
        $rows = [];
        $next = rtrim($this->baseUrl,'/').$this->endpoint."?date={$dateYmd}&branch_id={$branchId}&limit={$limit}&page=1";

        while ($next) {
            $headers = $this->hmacHeaders('GET', $next);
            $res = $this->http->request('GET', $next, ['headers' => $headers, 'timeout' => 30]);
            if ($res->getStatusCode() < 200 || $res->getStatusCode() >= 300) break;
            $json = json_decode((string)$res->getBody(), true);

            foreach (($json['data']['summary_attendance_report'] ?? []) as $r) {
                $clockIn  = $this->timeOnly($r['clock_in']  ?? '');
                $clockOut = $this->timeOnly($r['clock_out'] ?? '');
                $rows[] = [
                    'employee_id'    => (string)($r['employee_id'] ?? ''),
                    'full_name'      => (string)($r['full_name'] ?? ''),
                    'schedule_date'  => (string)($r['schedule_date'] ?? $dateYmd),
                    'clock_in'       => $clockIn ?: null,
                    'clock_out'      => $clockOut ?: null,
                    'real_work_hour' => (float)($r['real_work_hour'] ?? 0),
                    'branch_id'      => $branchId,
                    'shift_name'     => (string)($r['shift_name'] ?? ''),
                    'attendance_code'=> (string)($r['attendance_code'] ?? ''),
                    'holiday'        => (bool)($r['holiday'] ?? false),
                ];
            }

            $next = $json['data']['pagination']['next_page_url'] ?? '';
            if ($next && str_starts_with($next, '/')) {
                $next = rtrim($this->baseUrl,'/').$next;
            }
            usleep(100000);
        }
        return $rows;
    }

    private function timeOnly(string $val): string
    {
        if ($val === '') return '';
        if (preg_match('/\b(\d{2}:\d{2}:\d{2})\b/', $val, $m)) return $m[1];
        return $val;
    }
}
