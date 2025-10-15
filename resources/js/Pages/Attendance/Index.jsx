import React, { useState, useMemo } from 'react'
import { Link, router } from '@inertiajs/react'

export default function Index({ rows, filters }) {
  const [q, setQ] = useState(filters.q || '')
  const [from, setFrom] = useState(filters.from)
  const [to, setTo] = useState(filters.to)
  const [branch, setBranch] = useState(filters.branch_id)

  // === Constants ===
  const HOURLY_RATE = 28298 // Rp 28.298 per hour

  const [mobileFiltersOpen, setMobileFiltersOpen] = useState(false)
  const [mobileExportOpen, setMobileExportOpen] = useState(false)
  // === Live search (debounced, no full reload) ===
  const [isSearching, setIsSearching] = useState(false)
  const searchDelayMs = 500
  const _searchTimer = React.useRef(null)
  React.useEffect(() => {
    if (_searchTimer.current) clearTimeout(_searchTimer.current)
    _searchTimer.current = setTimeout(() => {
      setIsSearching(true)
      router.get(
        '/attendance',
        { q, from, to, branch_id: branch },
        { preserveState: true, replace: true, preserveScroll: true, only: ['rows','filters'] }
      )
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

  // billable total (cap 7 hours)
  const calcDailyTotal = (realWorkHour) => {
    const h = Math.max(0, parseFloat(realWorkHour ?? 0))
    const billable = Math.min(h, 7)
    return Math.round(billable * HOURLY_RATE)
  }

  const search = (e) => {
    e.preventDefault()
    router.get(
      '/attendance',
      { q, from, to, branch_id: branch },
      { preserveState: true, replace: true, preserveScroll: true }
    )
  }

  const resetFilters = (e) => {
    e.preventDefault()
    router.get(
      '/attendance',
      { q: '', branch_id: branch },
      { replace: true, preserveScroll: true }
    )
  }

  const exportUrl = (fmt) =>
    `/attendance/export?format=${fmt}&from=${encodeURIComponent(from)}&to=${encodeURIComponent(
      to
    )}&branch_id=${encodeURIComponent(branch)}&q=${encodeURIComponent(q)}`

  // ===== Compact Pagination Helpers =====
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
    router.get(
      '/attendance',
      { q, from, to, branch_id: branch, page },
      { preserveState: true, preserveScroll: true, replace: true }
    )
  }

  // ===== Sweet Alert confirm before export =====
  const confirmExport = async (fmt) => {
    const nice = { csv: 'CSV', xlsx: 'Excel', pdf: 'PDF' }[fmt] || fmt.toUpperCase()
    const message = `You’re about to export ${total.toLocaleString('en-US')} row(s)
for Period ${from} → ${to}, as ${nice}.
Proceed?`

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
      if (window.confirm(message)) {
        window.location.href = exportUrl(fmt)
      }
    }
  }

  // ============= NEW: Monthly OT Total per Employee (within loaded page) =============
  // Build a stable, ordered grouping by employee (keeps first appearance order)
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
        })
        order.push(key)
      }
      const g = map.get(key)
      g.items.push(r)
      g.monthlyOt += Number(r.overtime_total_amount || 0)
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
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 flex items-center justify-between gap-3">
          <div className="min-w-0">
            <h1 className="text-lg sm:text-2xl font-bold truncate">Attendance Payroll</h1>
            <p className="text-xs sm:text-sm opacity-90 truncate">
              Period <b>{from}</b> → <b>{to}</b> • Branch <b>PT Kayu Mebel Indonesia (KMI)</b>
            </p>
          </div>

          {/* Desktop actions */}
          <div className="hidden md:flex items-center gap-2 shrink-0">
            <div className="flex overflow-hidden rounded-lg border border-white/20">
              <button onClick={() => confirmExport('csv')} className="px-3 py-2 bg-white/15 hover:bg-white/25">CSV</button>
              <button onClick={() => confirmExport('xlsx')} className="px-3 py-2 bg-white/15 hover:bg-white/25 border-l border-white/20">Excel</button>
              <button onClick={() => confirmExport('pdf')} className="px-3 py-2 bg-white/15 hover:bg-white/25 border-l border-white/20">PDF</button>
            </div>
            <Link
              href="/logout"
              method="post"
              as="button"
              className="px-3 py-2 bg-white/15 hover:bg-white/25 border border-white/20 rounded"
            >
              Logout
            </Link>
          </div>

          {/* Mobile actions */}
          <div className="md:hidden flex items-center gap-2 shrink-0">
            <button
              onClick={() => setMobileFiltersOpen((v) => !v)}
              className="px-3 py-2 bg-white/15 hover:bg-white/25 border border-white/20 rounded text-sm"
              aria-expanded={mobileFiltersOpen}
              aria-controls="filters-mobile"
            >
              Filters
            </button>

            <div className="relative">
              <button
                onClick={() => setMobileExportOpen((v) => !v)}
                className="px-3 py-2 bg-white/15 hover:bg-white/25 border border-white/20 rounded text-sm"
                aria-expanded={mobileExportOpen}
                aria-haspopup="menu"
              >
                Export
              </button>
              {mobileExportOpen && (
                <div
                  className="absolute right-0 mt-2 w-40 rounded-lg overflow-hidden border border-white/30 bg-white/90 text-slate-800 shadow-lg backdrop-blur"
                  role="menu"
                >
                  <button onClick={() => { setMobileExportOpen(false); confirmExport('csv') }} className="block w-full text-left px-3 py-2 hover:bg-slate-100" role="menuitem">CSV</button>
                  <button onClick={() => { setMobileExportOpen(false); confirmExport('xlsx') }} className="block w-full text-left px-3 py-2 hover:bg-slate-100 border-t" role="menuitem">Excel</button>
                  <button onClick={() => { setMobileExportOpen(false); confirmExport('pdf') }} className="block w-full text-left px-3 py-2 hover:bg-slate-100 border-t" role="menuitem">PDF</button>
                </div>
              )}
            </div>

            <Link
              href="/logout"
              method="post"
              as="button"
              className="px-3 py-2 bg-white/15 hover:bg-white/25 border border-white/20 rounded text-sm"
            >
              Logout
            </Link>
          </div>
        </div>

        {/* Mobile Filters Panel */}
        {mobileFiltersOpen && (
          <div id="filters-mobile" className="md:hidden border-t border-white/15 bg-white/10 backdrop-blur pb-4">
            <div className="max-w-7xl mx-auto px-4 pt-3">
              <form onSubmit={search} className="grid grid-cols-1 gap-3">
                <div>
                  <label className="text-xs text-white/90">From</label>
                  <input type="date" className="w-full border rounded px-3 py-2 bg-white" value={from} onChange={e => setFrom(e.target.value)} />
                </div>
                <div>
                  <label className="text-xs text-white/90">To</label>
                  <input type="date" className="w-full border rounded px-3 py-2 bg-white" value={to} onChange={e => setTo(e.target.value)} />
                </div>
                <div className="flex gap-2">
                  <button type="submit" className="flex-1 bg-white/90 hover:bg-white text-sky-700 font-medium rounded-lg py-2">Apply</button>
                  <button onClick={resetFilters} className="flex-1 bg-white/15 hover:bg-white/25 border border-white/40 rounded-lg py-2 text-white">Reset</button>
                </div>
                <div className="flex gap-2 items-center">
                  <input
                    placeholder="Search name / ID"
                    className="flex-1 border rounded px-3 py-2 bg-white"
                    value={q}
                    onChange={e => setQ(e.target.value)}
                    onKeyDown={(e) => { if (e.key === 'Enter') e.preventDefault() }}
                  />
                  <span className="text-xs text-white/90">{isSearching ? 'Searching…' : 'Type to search'}</span>
                </div>
              </form>
            </div>
          </div>
        )}
      </header>

      {/* Main */}
      <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">
        {/* Filters (Desktop/Tablet) */}
        <form onSubmit={search} className="hidden md:block bg-white rounded-2xl shadow border border-sky-100 p-4 md:p-6">
          <div className="grid grid-cols-1 md:grid-cols-5 gap-4 items-end">
            <div className="md:col-span-2">
              <label className="text-sm">From</label>
              <input type="date" className="w-full border rounded px-3 py-2" value={from} onChange={e => setFrom(e.target.value)} />
            </div>
            <div className="md:col-span-2">
              <label className="text-sm">To</label>
              <input type="date" className="w-full border rounded px-3 py-2" value={to} onChange={e => setTo(e.target.value)} />
            </div>
            <div className="md:col-span-1 flex gap-2">
              <button type="submit" className="w-full bg-sky-600 hover:bg-sky-700 text-white rounded-lg py-2">Apply</button>
              <button onClick={resetFilters} className="w-full border rounded-lg py-2">Reset</button>
            </div>
          </div>
          <div className="mt-3 flex items-center gap-3">
            <input
              placeholder="Search name / ID"
              className="flex-1 border rounded px-3 py-2"
              value={q}
              onChange={e => setQ(e.target.value)}
              onKeyDown={(e) => { if (e.key === 'Enter') e.preventDefault() }}
            />
            <span className="text-sm text-slate-500">{isSearching ? 'Searching…' : 'Type to search'}</span>
          </div>
        </form>

        {/* Table (Desktop) */}
        <div className="hidden md:block overflow-x-auto bg-white rounded-2xl shadow border border-sky-100">
          <table className="min-w-[1140px] w-full text-sm">
            <thead className="bg-gradient-to-r from-sky-100 via-blue-100 to-emerald-100 border-b border-gray-200">
              <tr>
                <th className="px-4 py-3 text-left font-semibold text-sky-900">Date</th>
                <th className="px-4 py-3 text-left font-semibold text-sky-900">Employee ID</th>
                <th className="px-4 py-3 text-left font-semibold text-sky-900">Name</th>
                <th className="px-4 py-3 text-left font-semibold text-sky-900">In</th>
                <th className="px-4 py-3 text-left font-semibold text-sky-900">Out</th>
                <th className="px-4 py-3 text-left font-semibold text-sky-900">Work Hours</th>
                <th className="px-4 py-3 text-left font-semibold text-sky-900">Total</th>
                <th className="px-4 py-3 text-left font-semibold text-sky-900">OT Hours</th>
                <th className="px-4 py-3 text-left font-semibold text-sky-900">OT 1 (1.5×)</th>
                <th className="px-4 py-3 text-left font-semibold text-sky-900">OT 2 (2×)</th>
                <th className="px-4 py-3 text-left font-semibold text-sky-900">OT Total</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {data.length === 0 && (
                <tr><td className="px-4 py-6 text-center text-gray-500" colSpan="11">No data</td></tr>
              )}

              {/* Render grouped by employee */}
              {groups.map((g) => (
                <React.Fragment key={g.key}>
                  {g.items.map((r) => {
                    const dailyTotal = calcDailyTotal(r.real_work_hour)
                    return (
                      <tr key={r.id} className="odd:bg-white even:bg-slate-50 hover:bg-sky-50">
                        <td className="px-4 py-2">{r.schedule_date}</td>
                        <td className="px-4 py-2 font-mono">{r.employee_id}</td>
                        <td className="px-4 py-2 font-medium truncate max-w-[260px]">{r.full_name}</td>
                        <td className="px-4 py-2">{fmtTime(r.clock_in)}</td>
                        <td className="px-4 py-2">{fmtTime(r.clock_out)}</td>
                        <td className="px-4 py-2">
                          <span className={`px-2 py-0.5 rounded-full text-xs font-semibold ${r.real_work_hour >= 7 ? 'bg-emerald-100 text-emerald-700':'bg-slate-100 text-slate-700'}`}>
                            {r.real_work_hour} h
                          </span>
                        </td>
                        <td className="px-4 py-2 font-semibold text-slate-800">{fmtIDR(dailyTotal)}</td>
                        <td className="px-4 py-2"><span className="px-2 py-0.5 rounded-full text-xs font-semibold bg-blue-100 text-blue-700">{r.overtime_hours} h</span></td>
                        <td className="px-4 py-2">{fmtIDR(r.overtime_first_amount)}</td>
                        <td className="px-4 py-2">{fmtIDR(r.overtime_second_amount)}</td>
                        <td className={`px-4 py-2 font-bold ${r.overtime_total_amount>0?'text-emerald-700':'text-slate-500'}`}>{fmtIDR(r.overtime_total_amount)}</td>
                      </tr>
                    )
                  })}

                  {/* Subtotal row per employee: OT Total (Month) */}
                  <tr key={`${g.key}-subtotal`} className="bg-amber-50/60">
                    <td className="px-4 py-2 text-right text-amber-700 font-semibold" colSpan={10}>
                      Total Lembur
                    </td>
                    <td className="px-4 py-2 font-extrabold text-amber-700">{fmtIDR(g.monthlyOt)}</td>
                  </tr>
                </React.Fragment>
              ))}
            </tbody>
          </table>
        </div>

        {/* Pagination (compact) */}
        <div className="flex items-center justify-between text-sm flex-wrap gap-3">
          <div>Total: {total}</div>

          <div className="flex items-center gap-2 flex-wrap">
            <button
              onClick={() => goto(Math.max(1, current - 1))}
              disabled={current === 1}
              className={`px-3 py-1 rounded border ${current === 1 ? 'bg-slate-100 text-slate-400 cursor-not-allowed' : 'bg-white hover:bg-slate-50'}`}
            >
              Prev
            </button>

            {pages.map((p, i) =>
              p === '…' ? (
                <span key={`e-${i}`} className="px-2 text-slate-500 select-none">…</span>
              ) : (
                <button
                  key={p}
                  onClick={() => goto(p)}
                  className={`px-3 py-1 rounded border ${p === current ? 'bg-sky-600 text-white border-sky-600' : 'bg-white hover:bg-slate-50'}`}
                >
                  {p}
                </button>
              )
            )}

            <button
              onClick={() => goto(Math.min(last, current + 1))}
              disabled={current === last}
              className={`px-3 py-1 rounded border ${current === last ? 'bg-slate-100 text-slate-400 cursor-not-allowed' : 'bg-white hover:bg-slate-50'}`}
            >
              Next
            </button>
          </div>
        </div>

        {/* Cards (Mobile) — grouped by employee with a monthly subtotal card */}
        <div className="md:hidden space-y-3">
          {groups.map((g) => (
            <div key={g.key} className="space-y-3">
              {g.items.map((r) => {
                const dailyTotal = calcDailyTotal(r.real_work_hour)
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
                        <div className={`text-sm font-bold ${r.overtime_total_amount>0?'text-emerald-700':'text-slate-600'}`}>
                          {fmtIDR(r.overtime_total_amount)}
                        </div>
                      </div>
                    </div>

                    <div className="mt-3 grid grid-cols-2 gap-2 text-xs">
                      <div className="rounded-lg bg-sky-50 px-2 py-2">
                        In<br /><span className="font-semibold text-sky-700">{fmtTime(r.clock_in)}</span>
                      </div>
                      <div className="rounded-lg bg-sky-50 px-2 py-2">
                        Out<br /><span className="font-semibold text-sky-700">{fmtTime(r.clock_out)}</span>
                      </div>
                      <div className="rounded-lg bg-emerald-50 px-2 py-2">
                        Work Hours<br /><span className="font-semibold text-emerald-700">{r.real_work_hour} h</span>
                      </div>
                      <div className="rounded-lg bg-blue-50 px-2 py-2">
                        OT Hours<br /><span className="font-semibold text-blue-700">{r.overtime_hours} h</span>
                      </div>
                      <div className="rounded-lg bg-amber-50 px-2 py-2 col-span-2">
                        Daily Total (≤7h)<br />
                        <span className="font-semibold text-amber-700">{fmtIDR(dailyTotal)}</span>
                      </div>
                      <div className="rounded-lg bg-amber-50/60 px-2 py-2 col-span-2">
                        OT 1 / OT 2<br />
                        <span className="font-semibold text-amber-700">
                          {fmtIDR(r.overtime_first_amount)} • {fmtIDR(r.overtime_second_amount)}
                        </span>
                      </div>
                    </div>
                  </div>
                )
              })}

              {/* Monthly subtotal card per employee */}
              <div className="bg-amber-50 border border-amber-100 rounded-2xl p-4 flex items-center justify-between">
                <div>
                  <div className="text-xs text-amber-700/80">Total Lembur</div>
                  <div className="text-lg font-extrabold text-amber-700">{fmtIDR(g.monthlyOt)}</div>
                </div>
                <div className="text-right text-xs text-slate-500">
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
