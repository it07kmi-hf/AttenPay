import React, { useState, useMemo } from 'react'
import { Link, router } from '@inertiajs/react'

// pakai path logo yang sama seperti di halaman login
const LOGO_KMI = '/img/logo-kmi.png'

export default function Index({ rows, filters }) {
  const [q, setQ] = useState(filters.q || '')
  const [from, setFrom] = useState(filters.from)
  const [to, setTo] = useState(filters.to)
  const [branch, setBranch] = useState(filters.branch_id)

  // === Constants ===
  const HOURLY_RATE = 28298 // Rp 28.298 per hour

  const [mobileFiltersOpen, setMobileFiltersOpen] = useState(false)
  const [mobileExportOpen, setMobileExportOpen] = useState(false)
  const [isSearching, setIsSearching] = useState(false)
  const searchDelayMs = 500
  const _searchTimer = React.useRef(null)

  // live search (debounce)
  React.useEffect(() => {
    if (_searchTimer.current) clearTimeout(_searchTimer.current)
    _searchTimer.current = setTimeout(() => {
      setIsSearching(true)
      router.get('/attendance', { q, from, to, branch_id: branch }, {
        preserveState: true, replace: true, preserveScroll: true, only: ['rows','filters']
      })
      setIsSearching(false)
    }, searchDelayMs)
    return () => { if (_searchTimer.current) clearTimeout(_searchTimer.current) }
  }, [q])

  const data = rows?.data ?? []

  const fmtTime = (val) => {
    if (!val) return '-'
    const m = String(val).match(/\b(\d{2}:\d{2})/)
    return m ? m[1] : val
  }
  const fmtIDR = (n) => `Rp ${Number(n || 0).toLocaleString('id-ID')}`

  // Basic salary harian (cap 7 jam)
  const calcDailyTotal = (realWorkHour) => {
    const h = Math.max(0, parseFloat(realWorkHour ?? 0))
    const billable = Math.min(h, 7)
    return Math.round(billable * HOURLY_RATE)
  }

  const search = (e) => {
    e.preventDefault()
    router.get('/attendance', { q, from, to, branch_id: branch }, {
      preserveState: true, replace: true, preserveScroll: true
    })
  }

  const resetFilters = (e) => {
    e.preventDefault()
    router.get('/attendance', { q: '', branch_id: branch }, { replace: true, preserveScroll: true })
  }

  const exportUrl = (fmt) =>
    `/attendance/export?format=${fmt}&from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}&branch_id=${encodeURIComponent(branch)}&q=${encodeURIComponent(q)}`

  // ===== Pagination =====
  const current = rows?.current_page ?? rows?.meta?.current_page ?? 1
  const last = rows?.last_page ?? rows?.meta?.last_page ?? 1
  const total = rows?.total ?? rows?.meta?.total ?? 0

  const makePages = (cur, max) => {
    if (max <= 7) return Array.from({ length: max }, (_, i) => i + 1)
    if (cur <= 4) return [1, 2, 3, 4, '…', max]
    if (cur >= max - 3) return [1, '…', max - 3, max - 2, max - 1, max]
    return [1, '…', cur - 1, cur, cur + 1, '…', max]
  }
  const pages = makePages(current, last)

  const goto = (page) => {
    router.get('/attendance', { q, from, to, branch_id: branch, page }, {
      preserveState: true, preserveScroll: true, replace: true
    })
  }

  // ===== Confirm export =====
  const confirmExport = async (fmt) => {
    const nice = { csv: 'CSV', xlsx: 'Excel', pdf: 'PDF' }[fmt] || fmt.toUpperCase()
    try {
      const Swal = (await import('sweetalert2')).default
      const res = await Swal.fire({
        icon: 'question',
        title: 'Export confirmation',
        html: `
          <div style="text-align:left">
            <div><b>Format:</b> ${nice}</div>
            <div><b>Rows:</b> ${total.toLocaleString('en-US')}</div>
            <div><b>Period:</b> ${from} → ${to}</div>
            ${q ? `<div><b>Search:</b> ${q}</div>` : ''}
          </div>
        `,
        showCancelButton: true,
        confirmButtonText: 'Yes, export',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#0284c7',
        focusCancel: true,
      })
      if (res.isConfirmed) window.location.href = exportUrl(fmt)
    } catch {
      if (window.confirm(`You’re about to export ${total} row(s)\nfor Period ${from} → ${to}, as ${nice}.\nProceed?`)) {
        window.location.href = exportUrl(fmt)
      }
    }
  }

  // ===== Group per employee + monthly totals (OT & BSOTT) =====
  const groups = useMemo(() => {
    const map = new Map()
    const order = []
    data.forEach((r, idx) => {
      const key = `${r.employee_id ?? ''}::${r.full_name ?? ''}`
      if (!map.has(key)) {
        map.set(key, {
          key,
          employee_id: r.employee_id,
          name: r.full_name,
          firstIndex: idx,
          items: [],
          monthlyOt: 0,
          monthlyBsott: 0,
        })
        order.push(key)
      }
      const g = map.get(key)
      g.items.push(r)
      const base = calcDailyTotal(r.real_work_hour)
      const ot = Number(r.overtime_total_amount || 0)
      g.monthlyOt += ot
      g.monthlyBsott += base + ot
    })
    return order.map((k) => map.get(k))
  }, [data])

  return (
    <div
      className="min-h-dvh bg-slate-50"
      style={{
        paddingTop: 'env(safe-area-inset-top)',
        paddingBottom: 'env(safe-area-inset-bottom)',
        paddingLeft: 'env(safe-area-inset-left)',
        paddingRight: 'env(safe-area-inset-right)',
      }}
    >
      {/* Header */}
      <header className="sticky top-0 z-30 bg-gradient-to-r from-sky-600 via-blue-600 to-emerald-600 text-white shadow">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-3 md:py-4 flex items-center justify-between gap-3">
          {/* === LOGO + TITLE === */}
          <div className="min-w-0 flex items-center gap-4 sm:gap-5">
            {/* bulat putih, ukuran pas */}
            <div className="h-9 w-9 md:h-10 md:w-10 shrink-0 rounded-full bg-white p-1 ring-1 ring-white/50 shadow-sm">
              <img
                src={LOGO_KMI}
                alt="KMI Logo"
                className="h-full w-full object-contain"
                loading="eager"
                decoding="async"
                onError={(e) => (e.currentTarget.style.display = 'none')}
              />
            </div>
            <div className="min-w-0">
              <h1 className="text-lg sm:text-2xl font-bold truncate">Attendance Payroll System</h1>
              {/* hanya company name di header biru */}
              <p className="text-xs sm:text-sm opacity-90 truncate">
                <b>PT Kayu Mebel Indonesia (KMI)</b>
              </p>
            </div>
          </div>

          {/* Desktop actions */}
          <div className="hidden md:flex items-center gap-2 shrink-0">
            <div className="flex overflow-hidden rounded-lg border border-white/20">
              <button onClick={() => confirmExport('csv')} className="px-2.5 py-1.5 text-sm bg-white/15 hover:bg-white/25">CSV</button>
              <button onClick={() => confirmExport('xlsx')} className="px-2.5 py-1.5 text-sm bg-white/15 hover:bg-white/25 border-l border-white/20">Excel</button>
              <button onClick={() => confirmExport('pdf')} className="px-2.5 py-1.5 text-sm bg-white/15 hover:bg-white/25 border-l border-white/20">PDF</button>
            </div>

            {/* Logout icon (merah) */}
            <Link
              href="/logout"
              method="post"
              as="button"
              aria-label="Logout"
              className="p-2 rounded-md border border-white/20 bg-white/10 hover:bg-white/20"
              title="Logout"
            >
              <svg
                xmlns="http://www.w3.org/2000/svg"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                strokeWidth="1.8"
                className="h-5 w-5 text-red-400 hover:text-red-500 transition-colors"
              >
                {/* Heroicons: ArrowRightOnRectangle */}
                <path strokeLinecap="round" strokeLinejoin="round"
                  d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6A2.25 2.25 0 0 0 5.25 5.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15" />
                <path strokeLinecap="round" strokeLinejoin="round"
                  d="M12 9l3 3m0 0l-3 3m3-3H3" />
              </svg>
            </Link>
          </div>

          {/* Mobile actions */}
          <div className="md:hidden flex items-center gap-2 shrink-0">
            <button onClick={() => setMobileFiltersOpen(v => !v)} className="px-2.5 py-1.5 text-sm bg-white/15 hover:bg-white/25 border border-white/20 rounded-md">Filters</button>
            <div className="relative">
              <button onClick={() => setMobileExportOpen(v => !v)} className="px-2.5 py-1.5 text-sm bg-white/15 hover:bg-white/25 border border-white/20 rounded-md">Export</button>
              {mobileExportOpen && (
                <div className="absolute right-0 mt-2 w-40 rounded-lg overflow-hidden border border-white/30 bg-white/90 text-slate-800 shadow-lg backdrop-blur">
                  <button onClick={() => { setMobileExportOpen(false); confirmExport('csv') }} className="block w-full text-left px-3 py-2 hover:bg-slate-100">CSV</button>
                  <button onClick={() => { setMobileExportOpen(false); confirmExport('xlsx') }} className="block w-full text-left px-3 py-2 hover:bg-slate-100 border-t">Excel</button>
                  <button onClick={() => { setMobileExportOpen(false); confirmExport('pdf') }} className="block w-full text-left px-3 py-2 hover:bg-slate-100 border-t">PDF</button>
                </div>
              )}
            </div>

            {/* Logout icon mobile */}
            <Link
              href="/logout"
              method="post"
              as="button"
              aria-label="Logout"
              className="p-2 rounded-md border border-white/20 bg-white/10 hover:bg-white/20"
              title="Logout"
            >
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                   fill="none" stroke="currentColor" strokeWidth="1.8"
                   className="h-5 w-5 text-red-400 hover:text-red-500">
                <path strokeLinecap="round" strokeLinejoin="round"
                  d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6A2.25 2.25 0 0 0 5.25 5.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15" />
                <path strokeLinecap="round" strokeLinejoin="round"
                  d="M12 9l3 3m0 0l-3 3m3-3H3" />
              </svg>
            </Link>
          </div>
        </div>
      </header>

      {/* === Period badge tepat di bawah header biru (lebih kecil) === */}
      <div className="bg-slate-50">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-2">
          <div
            className="inline-flex items-center gap-1.5 rounded-full border border-sky-200 bg-white/80 backdrop-blur px-2.5 py-0.5 text-[10px] sm:text-[11px] text-sky-700 shadow-sm"
            aria-label="Selected period"
          >
            <span className="font-medium">Period:</span>
            <span className="font-semibold">{from}</span>
            <span>→</span>
            <span className="font-semibold">{to}</span>
          </div>
        </div>
      </div>

      {/* Main */}
      <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 md:py-5 space-y-6">
        {/* Filters (Desktop) — dibuat lebih pendek */}
        <form onSubmit={search} className="hidden md:block bg-white rounded-xl shadow border border-sky-100 p-3 md:p-4">
          <div className="grid grid-cols-1 md:grid-cols-5 gap-3 items-end">
            <div className="md:col-span-2">
              <label className="text-sm">From</label>
              <input type="date" className="w-full border rounded-md px-3 py-1.5 text-sm" value={from} onChange={e => setFrom(e.target.value)} />
            </div>
            <div className="md:col-span-2">
              <label className="text-sm">To</label>
              <input type="date" className="w-full border rounded-md px-3 py-1.5 text-sm" value={to} onChange={e => setTo(e.target.value)} />
            </div>
            <div className="md:col-span-1 flex gap-2">
              <button type="submit" className="w-full bg-sky-600 hover:bg-sky-700 text-white rounded-md py-1.5 text-sm">Apply</button>
              <button onClick={resetFilters} className="w-full border rounded-md py-1.5 text-sm">Reset</button>
            </div>
          </div>
          <div className="mt-3 flex items-center gap-3">
            <input
              placeholder="Search name / ID"
              className="flex-1 border rounded-md px-3 py-1.5 text-sm"
              value={q}
              onChange={e => setQ(e.target.value)}
              onKeyDown={(e) => { if (e.key === 'Enter') e.preventDefault() }}
            />
            <span className="text-sm text-slate-500">{isSearching ? 'Searching…' : 'Type to search'}</span>
          </div>
        </form>

        {/* Table (Desktop) — full kiri-kanan, sejajar */}
        <div className="hidden md:block bg-white rounded-2xl shadow border border-sky-100">
          <table className="w-full text-xs md:text-sm table-fixed">
            {/* NOTE: kolom Name fleksibel */}
            <colgroup>
              <col className="w-[6rem]" />
              <col className="w-[6rem]" />
              <col />
              <col className="w-[5rem]" />
              <col className="w-[5rem]" />
              <col className="w-[6rem]" />
              <col className="w-[5.5rem]" />
              <col className="w-[5rem]" />
              <col className="w-[6rem]" />
              <col className="w-[6rem]" />
              <col className="w-[6rem]" />
              <col className="w-[6rem]" />
            </colgroup>

            <thead className="bg-gradient-to-r from-sky-100 via-blue-100 to-emerald-100 border-b border-gray-200">
              <tr>
                <th className="px-3 py-3 font-semibold text-sky-900 whitespace-nowrap text-center">Date</th>
                <th className="px-3 py-3 font-semibold text-sky-900 whitespace-nowrap text-center">Employee ID</th>
                <th className="px-3 py-3 font-semibold text-sky-900 whitespace-nowrap text-center">Name</th>
                <th className="px-3 py-3 font-semibold text-sky-900 whitespace-nowrap text-center">In</th>
                <th className="px-3 py-3 font-semibold text-sky-900 whitespace-nowrap text-center">Out</th>
                <th className="px-3 py-3 font-semibold text-sky-900 whitespace-nowrap text-center">Work Hours</th>
                <th className="px-3 py-3 font-semibold text-sky-900 whitespace-nowrap text-center">Basic Salary</th>
                <th className="px-3 py-3 font-semibold text-sky-900 whitespace-nowrap text-center">OT Hours</th>
                <th className="px-3 py-3 font-semibold text-sky-900 whitespace-nowrap text-center">OT 1 (1.5×)</th>
                <th className="px-3 py-3 font-semibold text-sky-900 whitespace-nowrap text-center">OT 2 (2×)</th>
                <th className="px-3 py-3 font-semibold text-sky-900 whitespace-nowrap text-center">OT Total</th>
                <th className="px-3 py-3 font-semibold text-sky-900 whitespace-nowrap text-center">Total Salary</th>
              </tr>
            </thead>

            <tbody className="divide-y divide-gray-100">
              {data.length === 0 && (
                <tr><td className="px-4 py-6 text-center text-gray-500" colSpan={12}>No data</td></tr>
              )}

              {groups.map((g) => (
                <React.Fragment key={g.key}>
                  {g.items.map((r) => {
                    const base = calcDailyTotal(r.real_work_hour)
                    const ot = Number(r.overtime_total_amount || 0)
                    const bsott = base + ot
                    return (
                      <tr key={r.id} className="odd:bg-white even:bg-slate-50 hover:bg-sky-50">
                        <td className="px-3 py-2 whitespace-nowrap font-mono text-center">{r.schedule_date}</td>
                        <td className="px-3 py-2 whitespace-nowrap font-mono text-center">{r.employee_id}</td>
                        <td className="px-3 py-2 whitespace-nowrap text-center">
                          <div className="truncate">{r.full_name}</div>
                        </td>
                        <td className="px-3 py-2 whitespace-nowrap text-center">{fmtTime(r.clock_in)}</td>
                        <td className="px-3 py-2 whitespace-nowrap text-center">{fmtTime(r.clock_out)}</td>
                        <td className="px-3 py-2 whitespace-nowrap text-center">
                          <span className={`px-2 py-0.5 rounded-full text-xs font-semibold ${r.real_work_hour >= 7 ? 'bg-emerald-100 text-emerald-700':'bg-slate-100 text-slate-700'}`}>
                            {r.real_work_hour} h
                          </span>
                        </td>
                        <td className="px-3 py-2 whitespace-nowrap font-semibold text-slate-800 text-center">{fmtIDR(base)}</td>
                        <td className="px-3 py-2 whitespace-nowrap text-center">
                          <span className="px-2 py-0.5 rounded-full text-xs font-semibold bg-blue-100 text-blue-700">{r.overtime_hours} h</span>
                        </td>
                        <td className="px-3 py-2 whitespace-nowrap text-center">{fmtIDR(r.overtime_first_amount)}</td>
                        <td className="px-3 py-2 whitespace-nowrap text-center">{fmtIDR(r.overtime_second_amount)}</td>
                        <td className={`px-3 py-2 whitespace-nowrap font-bold ${ot>0?'text-emerald-700':'text-slate-500'} text-center`}>{fmtIDR(ot)}</td>
                        <td className="px-3 py-2 whitespace-nowrap font-extrabold text-slate-800 text-center">{fmtIDR(bsott)}</td>
                      </tr>
                    )
                  })}

                  {/* Subtotal per employee */}
                  <tr className="bg-amber-50/60">
                    <td className="px-3 py-2 text-amber-700 font-semibold whitespace-nowrap text-right pr-4" colSpan={10}>
                      Grand Total
                    </td>
                    <td className="px-3 py-2 whitespace-nowrap font-extrabold text-amber-700 text-center">{fmtIDR(g.monthlyOt)}</td>
                    <td className="px-3 py-2 whitespace-nowrap font-extrabold text-amber-700 text-center">{fmtIDR(g.monthlyBsott)}</td>
                  </tr>
                </React.Fragment>
              ))}
            </tbody>
          </table>
        </div>

        {/* Pagination */}
        <div className="flex items-center justify-between text-sm flex-wrap gap-3">
          <div>Total: {total}</div>
          <div className="flex items-center gap-2 flex-wrap">
            <button onClick={() => goto(Math.max(1, current - 1))} disabled={current === 1} className={`px-3 py-1 rounded border ${current === 1 ? 'bg-slate-100 text-slate-400 cursor-not-allowed' : 'bg-white hover:bg-slate-50'}`}>Prev</button>
            {pages.map((p, i) =>
              p === '…' ? <span key={`e-${i}`} className="px-2 text-slate-500 select-none">…</span> : (
                <button key={p} onClick={() => goto(p)} className={`px-3 py-1 rounded border ${p === current ? 'bg-sky-600 text-white border-sky-600' : 'bg-white hover:bg-slate-50'}`}>{p}</button>
              )
            )}
            <button onClick={() => goto(Math.min(last, current + 1))} disabled={current === last} className={`px-3 py-1 rounded border ${current === last ? 'bg-slate-100 text-slate-400 cursor-not-allowed' : 'bg-white hover:bg-slate-50'}`}>Next</button>
          </div>
        </div>

        {/* Cards (Mobile) */}
        <div className="md:hidden space-y-3">
          {groups.map((g) => (
            <div key={g.key} className="space-y-3">
              {g.items.map((r) => {
                const base = calcDailyTotal(r.real_work_hour)
                const ot = Number(r.overtime_total_amount || 0)
                const bsott = base + ot
                return (
                  <div key={r.id} className="bg-white rounded-2xl shadow border border-sky-100 p-4">
                    <div className="flex items-start justify-between gap-3">
                      <div className="min-w-0">
                        <div className="text-sm font-semibold">{r.schedule_date}</div>
                        <div className="text-xs text-slate-500 font-mono">{r.employee_id}</div>
                        <div className="text-base font-semibold truncate">{r.full_name}</div>
                      </div>
                      <div className="text-right shrink-0">
                        <div className="text-xs text-slate-500">OT Total</div>
                        <div className={`text-sm font-bold ${ot>0?'text-emerald-700':'text-slate-600'}`}>{fmtIDR(ot)}</div>
                        <div className="text-xs text-slate-500 mt-1">Total Salary</div>
                        <div className="text-sm font-extrabold text-slate-800">{fmtIDR(bsott)}</div>
                      </div>
                    </div>

                    <div className="mt-3 grid grid-cols-2 gap-2 text-xs">
                      <div className="rounded-lg bg-sky-50 px-2 py-2">In<br /><span className="font-semibold text-sky-700">{fmtTime(r.clock_in)}</span></div>
                      <div className="rounded-lg bg-sky-50 px-2 py-2">Out<br /><span className="font-semibold text-sky-700">{fmtTime(r.clock_out)}</span></div>
                      <div className="rounded-lg bg-emerald-50 px-2 py-2">Work Hours<br /><span className="font-semibold text-emerald-700">{r.real_work_hour} h</span></div>
                      <div className="rounded-lg bg-blue-50 px-2 py-2">OT Hours<br /><span className="font-semibold text-blue-700">{r.overtime_hours} h</span></div>
                      <div className="rounded-lg bg-amber-50 px-2 py-2 col-span-2">Basic Salary<br /><span className="font-semibold text-amber-700">{fmtIDR(base)}</span></div>
                      <div className="rounded-lg bg-amber-50/60 px-2 py-2 col-span-2">OT 1 / OT 2<br /><span className="font-semibold text-amber-700">{fmtIDR(r.overtime_first_amount)} • {fmtIDR(r.overtime_second_amount)}</span></div>
                    </div>
                  </div>
                )
              })}
              <div className="bg-amber-50 border border-amber-100 rounded-2xl p-4">
                <div className="grid grid-cols-2 gap-3 items-end">
                  <div>
                    <div className="text-xs text-amber-700/80">Grand Total OT</div>
                    <div className="text-lg font-extrabold text-amber-700">{fmtIDR(g.monthlyOt)}</div>
                  </div>
                  <div className="text-right">
                    <div className="text-xs text-amber-700/80">Total Salary</div>
                    <div className="text-lg font-extrabold text-amber-700">{fmtIDR(g.monthlyBsott)}</div>
                  </div>
                </div>
                <div className="mt-2 text-right text-xs text-slate-500">
                  <div>{from} → {to}</div>
                  {g.employee_id && <div>Emp: {g.employee_id}</div>}
                </div>
              </div>
            </div>
          ))}
        </div>
      </main>
    </div>
  )
}
