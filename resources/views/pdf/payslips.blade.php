<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>SLIP GAJI</title>
<style>
  /* ==== KERTAS (dirapatkan biar muat 1 halaman) ==== */
  @page { margin: 8mm 10mm; }                 /* sebelumnya 10.5/12mm */
  body { font-family: DejaVu Sans, Arial, Helvetica, sans-serif; color:#000; font-size: 12px; }

  /* Satu slip = 1 halaman, tanpa halaman kosong di akhir */
  .page { page-break-after: always; }
  .page:last-child { page-break-after: auto; }

  /* ==== BINGKAI & HEADER ==== */
  .frame { border: 2px solid #000; padding: 5.5mm 6.5mm 6mm; } /* padding top/btm diperkecil */
  .header { text-align:center; line-height:1.1; }
  .company { font-weight:700; text-transform:uppercase; letter-spacing:.2px; }
  .subtitle { margin-top: 2px; font-weight:700; }

  /* ==== META ==== */
  .meta { margin-top: 5.5mm; width:100%; border-collapse:collapse; }
  .meta td { padding: 1.6px 4px; vertical-align:top; }
  .meta .label { width: 100px; }
  .meta .sep { width: 12px; text-align:center; }
  .meta .right { text-align:right; }
  .meta .days { white-space:nowrap; }

  /* ==== GARIS SEKSYEN ==== */
  .rule { border-top:2px solid #000; height:0; margin: 3.2mm 0 3mm; } /* jarak diperkecil */

  /* ==== GRID 2 KOLOM ==== */
  .grid { width:100%; border-collapse:collapse; table-layout:fixed; }
  .grid th, .grid > tbody > tr > td { border:1px solid #000; padding: 5px 7px; } /* vertikal 5px */
  .grid th { background:#f3f6ff; font-weight:700; }
  .w50 { width:50%; }

  /* ==== TABEL DI DALAM KOLOM (tanpa border internal) ==== */
  .inn { width:100%; border-collapse:collapse; table-layout:fixed; margin:0; }
  .inn td { padding: 4px 6px; border:0 !important; }
  .inn .sep { width: 10px; text-align:center; }
  .inn .rp  { width: 20px; text-align:center; }
  .inn .r   { text-align:right; white-space:nowrap; }

  /* Kolom POTONGAN menempel ke header */
  .pot-col { vertical-align: top; padding-top: 3px !important; }

  .bold { font-weight:700; }

  /* TOTAL baris bawah – garis tebal di atas, padding hemat */
  .total-row td { border-top:2px solid #000 !important; background:#fff; padding-top:6px; padding-bottom:6px; }

  /* TAKE HOME */
  .take-home { margin-top: 4.2mm; }
  .box { border:2px solid #000; display:inline-block; padding: 3px 9px; font-weight:700; }
</style>
<?php
  function rp($n){ return 'Rp '.number_format((int)$n,0,',','.'); }
?>
</head>
<body>

@php
  $items = $slips ?? ($pages ?? []);
@endphp

@foreach($items as $row)
  @php
    $get = function($k, $def=null) use($row){
      if (is_array($row)) return $row[$k] ?? $def;
      $o = (object)$row; return property_exists($o,$k) ? $o->$k : $def;
    };

    $employee_id = $get('employee_id','');
    $full_name   = $get('full_name','');
    $bagian      = $get('bagian', $get('job_position','-'));
    $period      = strtoupper($get('period_mon', $get('period_label','')) ?: '');
    $work_days   = (int)$get('work_days',0);

    $upah_pokok  = (int)$get('upah_pokok',0);
    $tunj_masa   = (int)$get('tunj_masa', (int)$get('tunjangan_mk',0));
    $upah_total  = (int)$get('upah_total', (int)$get('upah',0));

    $ot1         = (int)$get('ot_jam1', (int)$get('lembur_1',0));
    $ot2         = (int)$get('ot_jam2', (int)$get('lembur_2',0));
    $ot3         = (int)$get('ot_jam3', 0);
    $ot_libur    = (int)$get('ot_libur', 0);
    $ot_total    = (int)$get('ot_total', 0);

    $premi_hadir = (int)$get('premi_hadir',0);

    $bpjs_tk     = (int)$get('bpjs_tk',0);
    $bpjs_kes    = (int)$get('bpjs_kes',0);

    $is_ge_1y    = $tunj_masa > 0;

    $total_penerimaan = (int)$get('total_penerimaan', ($upah_total + $ot_total + ($is_ge_1y ? $premi_hadir : 0)));
    $total_potongan   = (int)$get('total_potongan', ($bpjs_tk + $bpjs_kes));
    $take_home        = (int)$get('take_home', ($total_penerimaan - $total_potongan));
  @endphp

  <div class="page">
    <div class="frame">
      <div class="header">
        <div class="company">PT. KAYU MEBEL INDONESIA</div>
        <div class="subtitle">SLIP GAJI</div>
      </div>

      <table class="meta">
        <tr>
          <td class="label">NIK</td><td class="sep">:</td><td>{{ $employee_id }}</td>
          <td></td>
          <td class="right days">JUMLAH HARI KERJA &nbsp;: &nbsp;<span class="bold">{{ $work_days }}</span> &nbsp;HARI</td>
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
          <td class="label">PERIODE</td><td class="sep">:</td><td>{{ $period }}</td>
          <td></td><td></td>
        </tr>
      </table>

      <div class="rule"></div>

      <table class="grid">
        <colgroup><col class="w50"><col class="w50"></colgroup>
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
              <table class="inn">
                <tr><td>UPAH POKOK</td><td class="sep">:</td><td class="r">{{ rp($upah_pokok) }}</td></tr>
                <tr><td>TUNJANGAN MASA KERJA</td><td class="sep">:</td><td class="r">{{ $is_ge_1y ? rp($tunj_masa) : '' }}</td></tr>
                <tr class="bold"><td>UPAH</td><td class="sep">:</td><td class="r">{{ rp($upah_total) }}</td></tr>

                <tr><td colspan="3" style="height:6px;border:0"></td></tr>

                <tr><td colspan="3" class="bold">LEMBUR</td></tr>
                <tr><td>LEMBUR JAM 1</td><td class="sep">:</td><td class="r">{{ $ot1 ? rp($ot1) : '' }}</td></tr>
                <tr><td>LEMBUR JAM 2</td><td class="sep">:</td><td class="r">{{ $ot2 ? rp($ot2) : '' }}</td></tr>
                <tr><td>LEMBUR JAM 3</td><td class="sep">:</td><td class="r">{{ $ot3 ? rp($ot3) : '' }}</td></tr>
                <tr><td>LEMBUR HARI LIBUR</td><td class="sep">:</td><td class="r">{{ $ot_libur ? rp($ot_libur) : '' }}</td></tr>
                <tr class="bold"><td>TOTAL UPAH LEMBUR</td><td class="sep">:</td><td class="r">{{ rp($ot_total) }}</td></tr>

                <tr><td colspan="3" style="height:6px;border:0"></td></tr>

                <tr><td>PREMI HADIR</td><td class="sep">:</td><td class="r">{{ $is_ge_1y ? rp($premi_hadir) : '' }}</td></tr>
                <tr><td>TUNJANGAN HARI RAYA</td><td class="sep">:</td><td class="r"></td></tr>
              </table>
            </td>

            <!-- POTONGAN -->
            <td class="pot-col">
              <table class="inn">
                <tr><td>BPJS KETENAGAKERJAAN</td><td class="rp">Rp</td><td class="r">{{ number_format($bpjs_tk,0,',','.') }}</td></tr>
                <tr><td>BPJS KESEHATAN</td><td class="rp">Rp</td><td class="r">{{ number_format($bpjs_kes,0,',','.') }}</td></tr>
                <tr><td>PAJAK PENGHASILAN</td><td class="rp">Rp</td><td class="r">-</td></tr>
                <tr><td>LAIN-LAIN</td><td class="rp">Rp</td><td class="r">-</td></tr>
              </table>
            </td>
          </tr>

          <tr class="total-row">
            <td class="r bold">TOTAL PENERIMAAN&nbsp;&nbsp;&nbsp;{{ rp($total_penerimaan) }}</td>
            <td class="r bold">TOTAL POTONGAN&nbsp;&nbsp;&nbsp;Rp {{ number_format($total_potongan,0,',','.') }}</td>
          </tr>
        </tbody>
      </table>

      <table class="take-home" style="width:100%;">
        <tr>
          <td class="bold" style="width:220px;">UPAH YANG DITERIMA</td>
          <td style="width:14px;">:</td>
          <td class="r"><span class="box">{{ rp($take_home) }}</span></td>
        </tr>
      </table>
    </div>
  </div>
@endforeach

</body>
</html>
