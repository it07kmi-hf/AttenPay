<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Slip Gaji</title>
<style>
  /* ===== KERTAS & FON ===== */
  @page { margin: 12mm 12mm; }
  body { font-family: DejaVu Sans, Arial, Helvetica, sans-serif; font-size: 12px; color:#000; }

  /* ===== 1 halaman per slip ===== */
  .page { page-break-after: always; }
  .page:last-child { page-break-after: auto; }

  /* ===== BINGKAI & HEADER ===== */
  .frame { border: 2px solid #000; padding: 10px 12px 12px; }
  .header { text-align:center; }
  .header .company { font-weight:700; text-transform:uppercase; letter-spacing:.2px; }
  .header .subtitle { margin-top:2px; font-weight:700; }

  /* ===== META ATAS ===== */
  .meta { margin-top:10px; width:100%; border-collapse:collapse; }
  .meta td { padding: 2px 4px; vertical-align:top; }
  .meta .label { width: 95px; }
  .meta .sep   { width: 10px; text-align:center; }
  .meta .right { text-align:right; }
  .meta .days  { white-space:nowrap; }

  /* ===== GARIS SEKSYEN ===== */
  .hr { margin: 8px 0 6px; border-top: 2px solid #000; height:0; }

  /* ===== TABEL UTAMA ===== */
  .grid { width:100%; border-collapse:collapse; table-layout:fixed; }
  .grid th, .grid td { border: 1px solid #000; padding: 6px 8px; }
  .grid th { background:#f6f8ff; }
  .w50 { width:50%; }

  /* ===== TEKS BANTU ===== */
  .r { text-align:right; }
  .c { text-align:center; }
  .bold { font-weight:700; }
  .boxed { border:2px solid #000; display:inline-block; padding:2px 8px; font-weight:700; }

  /* Baris kosong tipis untuk spacing antar subbagian */
  .spacer td { border:none; padding:4px 0; height:4px; }

  /* rapikan angka rupiah agar tidak wrap */
  .nowrap { white-space:nowrap; }
</style>
<?php
  function rp($n){ return 'Rp '.number_format((int)$n,0,',','.'); }
?>
</head>
<body>

@php
  // Normalisasi sumber data dan company
  $items = $slips ?? ($pages ?? []);
  $co    = $company ?? null;
  $companyName = 'PT. KAYU MEBEL INDONESIA'; // tampilkan persis seperti contoh
@endphp

@foreach($items as $row)
  @php
    // Ambil nilai dengan aman (support key lama/baru)
    $get = function($k, $def=null) use($row){
      return is_array($row) ? ($row[$k] ?? $def) : (property_exists((object)$row,$k) ? $row->$k : $def);
    };

    $employee_id  = $get('employee_id','');
    $full_name    = $get('full_name','');
    $bagian       = $get('bagian', $get('job_position','-'));
    $periodLabel  = strtoupper($get('period_mon', $get('period_label','')) ?: '');

    $work_days    = (int)($get('work_days',0));

    $upah_pokok   = (int)$get('upah_pokok', 0);
    $tunj_masa    = (int)$get('tunj_masa',  (int)$get('tunjangan_mk',0));
    $upah_total   = (int)$get('upah_total', (int)$get('upah',0));

    $ot1          = (int)$get('ot_jam1', (int)$get('lembur_1',0));
    $ot2          = (int)$get('ot_jam2', (int)$get('lembur_2',0));
    $ot3          = (int)$get('ot_jam3', 0);
    $ot_libur     = (int)$get('ot_libur', 0);
    $ot_total     = (int)$get('ot_total', 0);

    $premi_hadir  = (int)$get('premi_hadir', 0);

    $bpjs_tk      = (int)$get('bpjs_tk', 0);
    $bpjs_kes     = (int)$get('bpjs_kes', 0);

    // Flag masa kerja >= 1 tahun: dari adanya tunj_masa
    $is_ge_1y     = $tunj_masa > 0;

    // Total penerimaan/potongan/take home
    $total_penerimaan = (int)$get('total_penerimaan', ($upah_total + $ot_total + ($is_ge_1y ? $premi_hadir : 0)));
    $total_potongan   = (int)$get('total_potongan', ($bpjs_tk + $bpjs_kes));
    $take_home        = (int)$get('take_home', ($total_penerimaan - $total_potongan));
  @endphp

  <div class="page">
    <div class="frame">
      <div class="header">
        <div class="company">{{ $companyName }}</div>
        <div class="subtitle">SLIP GAJI</div>
      </div>

      <!-- META -->
      <table class="meta">
        <tr>
          <td class="label">NIK</td><td class="sep">:</td><td>{{ $employee_id }}</td>
          <td></td>
          <td class="right days">JUMLAH HARI KERJA&nbsp;&nbsp;:&nbsp;&nbsp;<span class="bold">{{ $work_days }}</span>&nbsp;&nbsp;HARI</td>
        </tr>
        <tr>
          <td class="label">NAMA</td><td class="sep">:</td><td>{{ $full_name }}</td>
          <td></td><td></td>
        </tr>
        <tr>
          <td class="label">BAGIAN</td><td class="sep">:</td><td>{{ $bagian ?: '-' }}</td>
          <td></td><td></td>
        </tr>
        <tr>
          <td class="label">PERIODE</td><td class="sep">:</td><td>{{ $periodLabel }}</td>
          <td></td><td></td>
        </tr>
      </table>

      <div class="hr"></div>

      <!-- GRID 2 KOLOM -->
      <table class="grid">
        <colgroup>
          <col class="w50"><col class="w50">
        </colgroup>
        <thead>
          <tr>
            <th class="c">PENERIMAAN</th>
            <th class="c">POTONGAN</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <!-- PENERIMAAN -->
            <td>
              <table style="width:100%; border-collapse:collapse;">
                <tr>
                  <td>UPAH POKOK</td><td class="sep">:</td><td class="r nowrap">{{ rp($upah_pokok) }}</td>
                </tr>
                <tr>
                  <!-- jika < 1 tahun, kosongkan nilainya -->
                  <td>TUNJANGAN MASA KERJA</td><td class="sep">:</td>
                  <td class="r nowrap">{{ $is_ge_1y ? rp($tunj_masa) : '' }}</td>
                </tr>
                <tr class="bold">
                  <td>UPAH</td><td class="sep">:</td><td class="r nowrap">{{ rp($upah_total) }}</td>
                </tr>

                <tr class="spacer"><td colspan="3"></td></tr>

                <tr><td colspan="3" class="bold">LEMBUR</td></tr>
                <tr>
                  <td>LEMBUR JAM 1</td><td class="sep">:</td><td class="r nowrap">{{ $ot1 ? rp($ot1) : '' }}</td>
                </tr>
                <tr>
                  <td>LEMBUR JAM 2</td><td class="sep">:</td><td class="r nowrap">{{ $ot2 ? rp($ot2) : '' }}</td>
                </tr>
                <tr>
                  <td>LEMBUR JAM 3</td><td class="sep">:</td><td class="r nowrap">{{ $ot3 ? rp($ot3) : '' }}</td>
                </tr>
                <tr>
                  <td>LEMBUR HARI LIBUR</td><td class="sep">:</td><td class="r nowrap">{{ $ot_libur ? rp($ot_libur) : '' }}</td>
                </tr>
                <tr class="bold">
                  <td>TOTAL UPAH LEMBUR</td><td class="sep">:</td><td class="r nowrap">{{ rp($ot_total) }}</td>
                </tr>

                <tr class="spacer"><td colspan="3"></td></tr>

                <tr>
                  <td>PREMI HADIR</td><td class="sep">:</td>
                  <td class="r nowrap">{{ $is_ge_1y ? rp($premi_hadir) : '' }}</td>
                </tr>
                <tr>
                  <td>TUNJANGAN HARI RAYA</td><td class="sep">:</td><td class="r nowrap"></td>
                </tr>
              </table>
            </td>

            <!-- POTONGAN -->
            <td>
              <table style="width:100%; border-collapse:collapse;">
                <tr>
                  <td>BPJS KETENAGAKERJAAN</td><td class="c" style="width:18px;">Rp</td>
                  <td class="r nowrap">{{ number_format($bpjs_tk,0,',','.') }}</td>
                </tr>
                <tr>
                  <td>BPJS KESEHATAN</td><td class="c">Rp</td>
                  <td class="r nowrap">{{ number_format($bpjs_kes,0,',','.') }}</td>
                </tr>
                <tr>
                  <td>PAJAK PENGHASILAN</td><td class="c">Rp</td>
                  <td class="r nowrap">-</td>
                </tr>
                <tr>
                  <td>LAIN-LAIN</td><td class="c">Rp</td>
                  <td class="r nowrap">-</td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- TOTAL BARIS BAWAH -->
          <tr>
            <td class="bold r">TOTAL PENERIMAAN&nbsp;&nbsp;&nbsp;{{ rp($total_penerimaan) }}</td>
            <td class="bold r">TOTAL POTONGAN&nbsp;&nbsp;&nbsp;Rp&nbsp;{{ number_format($total_potongan,0,',','.') }}</td>
          </tr>
        </tbody>
      </table>

      <div class="hr" style="margin-top:8px;"></div>

      <table style="width:100%;">
        <tr>
          <td class="bold" style="width:220px;">UPAH YANG DITERIMA</td>
          <td style="width:14px;">:</td>
          <td class="r"><span class="boxed">{{ rp($take_home) }}</span></td>
        </tr>
      </table>
    </div>
  </div>
@endforeach

</body>
</html>
