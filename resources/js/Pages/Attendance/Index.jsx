import React, { useState } from 'react'
import { Link, router } from '@inertiajs/react'

export default function Index({ rows, filters, locale='en' }) {
  const [lang, setLang] = useState(locale)
  const t = (en, id) => (lang === 'id' ? id : en)

  const [q, setQ] = useState(filters.q || '')
  const [from, setFrom] = useState(filters.from)
  const [to, setTo] = useState(filters.to)
  const [branch, setBranch] = useState(filters.branch_id)

  const search = (e) => {
    e.preventDefault()
    router.get('/attendance', { q, from, to, branch_id: branch }, { preserveState: true, replace: true })
  }

  const resetFilters = (e) => {
    e.preventDefault()
    router.get('/attendance', { q: '', from: filters.from, to: filters.to, branch_id: filters.branch_id }, { replace: true })
  }

  const exportUrl = (fmt) =>
    `/attendance/export?format=${fmt}&from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}&branch_id=${encodeURIComponent(branch)}&q=${encodeURIComponent(q)}`

  const header = (
    <header className="bg-gradient-to-r from-sky-600 via-blue-600 to-emerald-600 text-white">
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 flex flex-col md:flex-row md:items-end md:justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold">Attendance Payroll</h1>
          <p className="text-sm opacity-90">
            {t('Period','Periode')} <b>{from}</b> → <b>{to}</b> • {t('Branch','Cabang')} <b>{branch}</b>
          </p>
        </div>
        <div className="flex items-center gap-2">
          <select
            value={lang}
            onChange={e => setLang(e.target.value)}
            className="text-sm text-gray-900 rounded px-2 py-1"
            aria-label={t('Language','Bahasa')}
          >
            <option value="en">EN</option>
            <option value="id">ID</option>
          </select>

          {/* EXPORT */}
          <div className="flex overflow-hidden rounded-lg border border-white/20">
            <a href={exportUrl('csv')} className="px-3 py-2 bg-white/15 hover:bg-white/25">
              CSV
            </a>
            <a href={exportUrl('xlsx')} className="px-3 py-2 bg-white/15 hover:bg-white/25 border-l border-white/20">
              Excel
            </a>
            <a href={exportUrl('pdf')} className="px-3 py-2 bg-white/15 hover:bg-white/25 border-l border-white/20">
              PDF
            </a>
          </div>

          <Link
            href="/logout"
            method="post"
            as="button"
            className="px-3 py-2 bg-white/15 hover:bg-white/25 border border-white/20 rounded"
          >
            {t('Logout','Keluar')}
          </Link>
        </div>
      </div>
    </header>
  )

  return (
    <div className="min-h-screen">
      {header}

      <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">
        {/* Filters */}
        <form onSubmit={search} className="bg-white rounded-2xl shadow border border-sky-100 p-4 md:p-6">
          <div className="grid grid-cols-1 md:grid-cols-7 gap-4 items-end">
            <div className="md:col-span-2">
              <label className="text-sm">{t('From','Dari')}</label>
              <input type="date" className="w-full border rounded px-3 py-2" value={from} onChange={e => setFrom(e.target.value)} />
            </div>
            <div className="md:col-span-2">
              <label className="text-sm">{t('To','Sampai')}</label>
              <input type="date" className="w-full border rounded px-3 py-2" value={to} onChange={e => setTo(e.target.value)} />
            </div>
            <div className="md:col-span-2">
              <label className="text-sm">{t('Branch ID','ID Cabang')}</label>
              <input type="number" className="w-full border rounded px-3 py-2" value={branch} onChange={e => setBranch(e.target.value)} placeholder="21089" />
            </div>
            <div className="md:col-span-1 flex gap-2">
              <button className="w-full bg-sky-600 hover:bg-sky-700 text-white rounded-lg py-2">{t('Apply','Terapkan')}</button>
              <button onClick={resetFilters} className="w-full border rounded-lg py-2">{t('Reset','Reset')}</button>
            </div>
          </div>
          <div className="mt-3 flex items-center gap-3">
            <input
              placeholder={t('Search name / ID / time','Cari nama / ID / jam')}
              className="flex-1 border rounded px-3 py-2"
              value={q}
              onChange={e => setQ(e.target.value)}
            />
            <button onClick={search} className="px-4 py-2 border rounded">{t('Search','Cari')}</button>
          </div>
        </form>

        {/* Table (desktop) */}
        <div className="hidden md:block overflow-x-auto bg-white rounded-2xl shadow border border-sky-100">
          <table className="min-w-full text-sm">
            <thead className="bg-gradient-to-r from-sky-100 via-blue-100 to-emerald-100 border-b border-gray-200">
              <tr>
                <th className="px-4 py-3 text-left font-semibold text-sky-900">{t('Date','Tanggal')}</th>
                <th className="px-4 py-3 text-left font-semibold text-sky-900">Employee ID</th>
                <th className="px-4 py-3 text-left font-semibold text-sky-900">{t('Name','Nama')}</th>
                <th className="px-4 py-3 text-left font-semibold text-sky-900">Clock In</th>
                <th className="px-4 py-3 text-left font-semibold text-sky-900">Clock Out</th>
                <th className="px-4 py-3 text-left font-semibold text-sky-900">{t('Work Hour','Jam Kerja')}</th>
                <th className="px-4 py-3 text-left font-semibold text-sky-900">{t('OT Hours','Jam Lembur')}</th>
                <th className="px-4 py-3 text-left font-semibold text-sky-900">OT 1 (1.5×)</th>
                <th className="px-4 py-3 text-left font-semibold text-sky-900">OT 2 (2×)</th>
                <th className="px-4 py-3 text-left font-semibold text-sky-900">{t('OT Total','Total Lembur')}</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {rows.data.length === 0 && (
                <tr><td className="px-4 py-6 text-center text-gray-500" colSpan="10">{t('No data available','Tidak ada data')}</td></tr>
              )}
              {rows.data.map((r) => (
                <tr key={r.id} className="odd:bg-white even:bg-slate-50 hover:bg-sky-50">
                  <td className="px-4 py-2">{r.schedule_date}</td>
                  <td className="px-4 py-2 font-mono">{r.employee_id}</td>
                  <td className="px-4 py-2 font-medium">{r.full_name}</td>
                  <td className="px-4 py-2">{r.clock_in ?? '-'}</td>
                  <td className="px-4 py-2">{r.clock_out ?? '-'}</td>
                  <td className="px-4 py-2">
                    <span className={`px-2 py-0.5 rounded-full text-xs font-semibold ${r.real_work_hour >= 7 ? 'bg-emerald-100 text-emerald-700':'bg-slate-100 text-slate-700'}`}>
                      {r.real_work_hour} {t('h','jam')}
                    </span>
                  </td>
                  <td className="px-4 py-2"><span className="px-2 py-0.5 rounded-full text-xs font-semibold bg-blue-100 text-blue-700">{r.overtime_hours} {t('h','jam')}</span></td>
                  <td className="px-4 py-2">Rp {Number(r.overtime_first_amount).toLocaleString('id-ID')}</td>
                  <td className="px-4 py-2">Rp {Number(r.overtime_second_amount).toLocaleString('id-ID')}</td>
                  <td className={`px-4 py-2 font-bold ${r.overtime_total_amount>0?'text-emerald-700':'text-slate-500'}`}>Rp {Number(r.overtime_total_amount).toLocaleString('id-ID')}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        {/* Pagination */}
        <div className="flex items-center justify-between text-sm">
          <div>{t('Total','Total')}: {rows.total}</div>
          <div className="flex gap-2">
            {rows.links.map((l, i) => {
              const disabled = !l.url
              return (
                <Link
                  key={i}
                  href={l.url || '#'}
                  className={`px-3 py-1 rounded border ${
                    l.active ? 'bg-sky-600 text-white border-sky-600'
                    : disabled ? 'bg-slate-100 text-slate-400 cursor-not-allowed'
                    : 'bg-white hover:bg-slate-50'
                  }`}
                  dangerouslySetInnerHTML={{ __html: l.label }}
                  preserveScroll
                />
              )
            })}
          </div>
        </div>

        {/* Mobile Cards */}
        <div className="md:hidden space-y-3">
          {rows.data.map((r) => (
            <div key={r.id} className="bg-white rounded-2xl shadow border border-sky-100 p-4">
              <div className="flex items-start justify-between">
                <div>
                  <div className="text-sm font-semibold">{r.schedule_date}</div>
                  <div className="text-xs text-slate-500 font-mono">{r.employee_id}</div>
                  <div className="text-base font-semibold">{r.full_name}</div>
                </div>
                <div className="text-right">
                  <div className="text-xs text-slate-500">{t('OT Total','Total Lembur')}</div>
                  <div className={`text-sm font-bold ${r.overtime_total_amount>0?'text-emerald-700':'text-slate-600'}`}>
                    Rp {Number(r.overtime_total_amount).toLocaleString('id-ID')}
                  </div>
                </div>
              </div>
              <div className="mt-3 grid grid-cols-2 gap-2 text-xs">
                <div className="rounded-lg bg-sky-50 px-2 py-2">Clock In<br/><span className="font-semibold text-sky-700">{r.clock_in ?? '-'}</span></div>
                <div className="rounded-lg bg-sky-50 px-2 py-2">Clock Out<br/><span className="font-semibold text-sky-700">{r.clock_out ?? '-'}</span></div>
                <div className="rounded-lg bg-emerald-50 px-2 py-2">{t('Work Hour','Jam Kerja')}<br/><span className="font-semibold text-emerald-700">{r.real_work_hour} {t('h','jam')}</span></div>
                <div className="rounded-lg bg-blue-50 px-2 py-2">{t('OT Hours','Jam Lembur')}<br/><span className="font-semibold text-blue-700">{r.overtime_hours} {t('h','jam')}</span></div>
                <div className="rounded-lg bg-amber-50 px-2 py-2 col-span-2">OT1 / OT2<br/>
                  <span className="font-semibold text-amber-700">
                    Rp {Number(r.overtime_first_amount).toLocaleString('id-ID')} • Rp {Number(r.overtime_second_amount).toLocaleString('id-ID')}
                  </span>
                </div>
              </div>
            </div>
          ))}
        </div>
      </main>
    </div>
  )
}
