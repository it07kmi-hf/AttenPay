import React, { useState, useMemo } from 'react'
import { Link, router } from '@inertiajs/react'

const LOGO_KMI = '/img/logo-kmi.png'
const PER_PAGE = 10

export default function Index({ rows, filters, employeeTotals = {} }) {
  // filters state
  const [q, setQ] = useState(filters.q || '')
  const [from, setFrom] = useState(filters.from)
  const [to, setTo] = useState(filters.to)
  const [branch, setBranch] = useState(filters.branch_id)
  const [perPage] = useState(PER_PAGE)

  const [mobileFiltersOpen, setMobileFiltersOpen] = useState(false)
  const [mobileExportOpen, setMobileExportOpen] = useState(false)
  const [isSearching, setIsSearching] = useState(false)
  const searchDelayMs = 500
  const _searchTimer = React.useRef(null)

  // live search debounce
  React.useEffect(() => {
    if (_searchTimer.current) clearTimeout(_searchTimer.current)
    _searchTimer.current = setTimeout(() => {
      setIsSearching(true)
      router.get('/attendance',
        { q, from, to, branch_id: branch, per_page: perPage },
        { preserveState: true, replace: true, preserveScroll: true, only: ['rows','filters','employeeTotals'] }
      )
      setIsSearching(false)
    }, searchDelayMs)
    return () => { if (_searchTimer.current) clearTimeout(_searchTimer.current) }
  }, [q])

  const data = rows?.data ?? []

  // format helpers
  const fmtTime = (val) => {
    if (!val) return '-'
    const m = String(val).match(/\b(\d{2}:\d{2})/)
    return m ? m[1] : val
  }
  const fmtIDR = (n) => `Rp ${Number(n || 0).toLocaleString('id-ID')}`
  const safeNum = (v) => {
    const n = Number(v)
    return Number.isFinite(n) ? n : 0
  }
  const fmtBoolBadge = (v, yes = 'Yes', no = 'No') => (
    <span className={`px-2 py-0.5 rounded-full text-xs font-semibold inline-block text-center ${v ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-700'}`}>
      {v ? yes : no}
    </span>
  )
  const fmtDate = (v) => (v ? String(v).substring(0,10) : '-')
  const cleanBranchName = (name) => {
    if (!name) return '-'
    return String(name).split(/\s[–—-]\s/)[0]
  }

  // presence logic
  const isPresent = (r) =>
    !!(r.clock_in && r.clock_out && Number(r.real_work_hour) > 0)

  // calculations (0 if not present)
  const hourlyRate = (r) => isPresent(r) ? safeNum(r.hourly_rate_used) : 0
  const billableHours = (r) => {
    if (!isPresent(r)) return 0
    if (r.daily_billable_hours !== undefined && r.daily_billable_hours !== null) {
      return Math.max(0, safeNum(r.daily_billable_hours))
    }
    return Math.min(7, Math.max(0, safeNum(r.real_work_hour)))
  }
  const baseSalary = (r) => Math.round(billableHours(r) * hourlyRate(r))

  const otFirst  = (r) => isPresent(r) ? safeNum(r.overtime_first_amount)  : 0
  const otSecond = (r) => isPresent(r) ? safeNum(r.overtime_second_amount) : 0
  const otTotal  = (r) => isPresent(r) ? safeNum(r.overtime_total_amount)  : 0
  const otHours  = (r) => isPresent(r) ? safeNum(r.overtime_hours)         : 0

  const presenceDaily = (r) => isPresent(r) ? safeNum(r.presence_premium_daily) : 0

  const totalSalary = (r) => {
    if (r.daily_total_amount !== undefined && r.daily_total_amount !== null && Number.isFinite(Number(r.daily_total_amount))) {
      return Number(r.daily_total_amount)
    }
    return isPresent(r) ? (baseSalary(r) + otTotal(r)) : 0
  }

  // search actions
  const search = (e) => {
    e.preventDefault()
    router.get('/attendance', { q, from, to, branch_id: branch, per_page: perPage }, {
      preserveState: true, replace: true, preserveScroll: true
    })
  }
  const resetFilters = (e) => {
    e.preventDefault()
    router.get('/attendance', { q: '', branch_id: branch, per_page: perPage }, { replace: true, preserveScroll: true })
  }

  // export
  const exportUrl = (fmt) =>
    `/attendance/export?format=${fmt}&from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}&branch_id=${encodeURIComponent(branch)}&q=${encodeURIComponent(q)}`
  const confirmExport = async (fmt) => {
    const nice = { csv: 'CSV', xlsx: 'Excel', pdf: 'PDF' }[fmt] || fmt.toUpperCase()
    try {
      const Swal = (await import('sweetalert2')).default
      const totalRows = (rows?.total ?? rows?.meta?.total ?? 0)
      const res = await Swal.fire({
        icon: 'question',
        title: 'Export confirmation',
        html: `
          <div style="text-align:left">
            <div><b>Format:</b> ${nice}</div>
            <div><b>Rows:</b> ${totalRows.toLocaleString('en-US')}</div>
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
      const totalRows = (rows?.total ?? rows?.meta?.total ?? 0)
      if (window.confirm(`You’re about to export ${totalRows} row(s)\nfor Period ${from} → ${to}, as ${nice}.\nProceed?`)) {
        window.location.href = exportUrl(fmt)
      }
    }
  }

  // pagination
  const current = rows?.current_page ?? rows?.meta?.current_page ?? 1
  const last    = rows?.last_page    ?? rows?.meta?.last_page    ?? 1
  const total   = rows?.total        ?? rows?.meta?.total        ?? 0
  const makePages = (cur, max) => {
    if (max <= 7) return Array.from({ length: max }, (_, i) => i + 1)
    if (cur <= 4) return [1, 2, 3, 4, '…', max]
    if (cur >= max - 3) return [1, '…', max - 3, max - 2, max - 1, max]
    return [1, '…', cur - 1, cur, cur + 1, '…', max]
  }
  const pages = makePages(current, last)
  const goto = (page) => {
    router.get('/attendance', { q, from, to, branch_id: branch, per_page: perPage, page }, {
      preserveState: true, preserveScroll: true, replace: true
    })
  }

  // group per employee
  const groups = useMemo(() => {
    const map = new Map(), order = []
    data.forEach((r, idx) => {
      const key = `${r.employee_id ?? ''}::${r.full_name ?? ''}`
      if (!map.has(key)) {
        map.set(key, {
          key, employee_id: r.employee_id, name: r.full_name, firstIndex: idx,
          items: [],
          monthlyOt: 0, monthlyBsott: 0, monthlyPresence: 0,
          bpjsTk: safeNum(r.bpjs_tk_deduction),
          bpjsKes: safeNum(r.bpjs_kes_deduction),
        })
        order.push(key)
      }
      const g = map.get(key)
      g.items.push(r)
      g.monthlyOt       += otTotal(r)
      g.monthlyBsott    += totalSalary(r)
      g.monthlyPresence += presenceDaily(r)
      if (!g.bpjsTk)  g.bpjsTk  = safeNum(r.bpjs_tk_deduction)
      if (!g.bpjsKes) g.bpjsKes = safeNum(r.bpjs_kes_deduction)
    })
    return order.map(k => map.get(k))
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
          {/* logo + title */}
          <div className="min-w-0 flex items-center gap-4 sm:gap-5">
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
              <p className="text-xs sm:text-sm opacity-90 truncate">
                <b>PT Kayu Mebel Indonesia (KMI)</b>
              </p>
            </div>
          </div>

          {/* desktop actions */}
          <div className="hidden md:flex items-center gap-2 shrink-0">
            <div className="flex overflow-hidden rounded-lg border border-white/30">
              <button onClick={() => confirmExport('csv')} className="px-2.5 py-1.5 text-sm bg-white/15 hover:bg-white/25">CSV</button>
              <button onClick={() => confirmExport('xlsx')} className="px-2.5 py-1.5 text-sm bg-white/15 hover:bg-white/25 border-l border-white/30">Excel</button>
              <button onClick={() => confirmExport('pdf')} className="px-2.5 py-1.5 text-sm bg-white/15 hover:bg-white/25 border-l border-white/30">PDF</button>
            </div>

            {/* logout */}
            <Link
              href="/logout"
              method="post"
              as="button"
              aria-label="Logout"
              className="p-2 rounded-md border border-white/40 bg-white/10 hover:bg-white/20"
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
                <path strokeLinecap="round" strokeLinejoin="round"
                  d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6A2.25 2.25 0 0 0 5.25 5.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15" />
                <path strokeLinecap="round" strokeLinejoin="round"
                  d="M12 9l3 3m0 0l-3 3m3-3H3" />
              </svg>
            </Link>
          </div>

          {/* mobile actions */}
          <div className="md:hidden flex items-center gap-2 shrink-0">
            <button onClick={() => setMobileFiltersOpen(v => !v)} className="px-2.5 py-1.5 text-sm bg-white/15 hover:bg-white/25 border border-white/30 rounded-md">Filters</button>
            <div className="relative">
              <button onClick={() => setMobileExportOpen(v => !v)} className="px-2.5 py-1.5 text-sm bg-white/15 hover:bg-white/25 border border-white/30 rounded-md">Export</button>
              {mobileExportOpen && (
                <div className="absolute right-0 mt-2 w-40 rounded-lg overflow-hidden border border-white/40 bg-white/90 text-slate-800 shadow-lg backdrop-blur">
                  <button onClick={() => { setMobileExportOpen(false); confirmExport('csv') }} className="block w-full text-left px-3 py-2 hover:bg-slate-100">CSV</button>
                  <button onClick={() => { setMobileExportOpen(false); confirmExport('xlsx') }} className="block w-full text-left px-3 py-2 hover:bg-slate-100 border-t">Excel</button>
                  <button onClick={() => { setMobileExportOpen(false); confirmExport('pdf') }} className="block w-full text-left px-3 py-2 hover:bg-slate-100 border-t">PDF</button>
                </div>
              )}
            </div>

            {/* logout icon mobile */}
            <Link
              href="/logout"
              method="post"
              as="button"
              aria-label="Logout"
              className="p-2 rounded-md border border-white/40 bg-white/10 hover:bg-white/20"
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

      {/* Period badge */}
      <div className="bg-slate-50">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-2">
          <div
            className="inline-flex items-center gap-1.5 rounded-full border border-sky-300 bg-white/90 backdrop-blur px-3 py-0.5 text-[10px] sm:text-[11px] text-sky-700 shadow ring-1 ring-sky-100/60"
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
        {/* Filters (Desktop) */}
        <form onSubmit={search} className="hidden md:block bg-white rounded-xl shadow border border-sky-200 p-3 md:p-4">
          <div className="grid grid-cols-1 md:grid-cols-5 gap-3 items-end">
            <div className="md:col-span-2">
              <label className="text-sm">From</label>
              <input
                type="date"
                className="w-full border border-slate-300 rounded-md px-3 py-1.5 text-sm outline-none focus:border-sky-400 focus:ring-1 focus:ring-sky-300"
                value={from}
                onChange={e => setFrom(e.target.value)}
              />
            </div>
            <div className="md:col-span-2">
              <label className="text-sm">To</label>
              <input
                type="date"
                className="w-full border border-slate-300 rounded-md px-3 py-1.5 text-sm outline-none focus:border-sky-400 focus:ring-1 focus:ring-sky-300"
                value={to}
                onChange={e => setTo(e.target.value)}
              />
            </div>
            <div className="md:col-span-1 flex gap-2">
              <button type="submit" className="w-full bg-sky-600 hover:bg-sky-700 text-white rounded-md py-1.5 text-sm border border-sky-700">Apply</button>
              <button onClick={resetFilters} className="w-full border border-slate-300 rounded-md py-1.5 text-sm hover:bg-slate-50">Reset</button>
            </div>
          </div>
          <div className="mt-3 flex items-center gap-3">
            <input
              placeholder="Search name / ID"
              className="flex-1 border border-slate-300 rounded-md px-3 py-1.5 text-sm outline-none focus:border-sky-400 focus:ring-1 focus:ring-sky-300"
              value={q}
              onChange={e => setQ(e.target.value)}
              onKeyDown={(e) => { if (e.key === 'Enter') e.preventDefault() }}
            />
            <span className="text-sm text-slate-500">{isSearching ? 'Searching…' : 'Type to search'}</span>
          </div>
        </form>

        {/* TABLE — includes centered Timeoff column */}
        <div className="bg-white rounded-2xl shadow border border-sky-200 overflow-x-auto" role="region" aria-label="Attendance table" tabIndex={0}>
          <table className="min-w-[2300px] text-xs md:text-sm table-fixed">
            <colgroup>
              {/* identity/meta */}
              <col className="w-[7rem]" />
              <col className="w-[7rem]" />
              <col className="w-[12rem]" />
              <col className="w-[6rem]" />
              <col className="w-[8rem]" />
              <col className="w-[14rem]" />
              <col className="w-[14rem]" />
              <col className="w-[12rem]" />
              {/* presence */}
              <col className="w-[6rem]" />
              <col className="w-[6rem]" />
              <col className="w-[12rem]" /> {/* Timeoff */}
              <col className="w-[7rem]" />
              {/* overtime */}
              <col className="w-[6.5rem]" />
              <col className="w-[8rem]" />
              <col className="w-[8rem]" />
              <col className="w-[8rem]" />
              {/* presence bonus */}
              <col className="w-[8rem]" />
              {/* calc */}
              <col className="w-[8rem]" />
              <col className="w-[6.5rem]" />
              <col className="w-[9rem]" />
              <col className="w-[9rem]" />
              {/* tenure */}
              <col className="w-[7rem]" />
            </colgroup>

            <thead className="bg-gradient-to-r from-sky-100 via-blue-100 to-emerald-100 border-b border-sky-200">
              <tr>
                <th className="px-3 py-3 font-semibold text-sky-900 text-center">Date</th>
                <th className="px-3 py-3 font-semibold text-sky-900 text-center">Employee ID</th>
                <th className="px-3 py-3 font-semibold text-sky-900">Name</th>

                <th className="px-3 py-3 font-semibold text-sky-900 text-center">Gender</th>
                <th className="px-3 py-3 font-semibold text-sky-900 text-center">Join Date</th>
                <th className="px-3 py-3 font-semibold text-sky-900">Branch Name</th>
                <th className="px-3 py-3 font-semibold text-sky-900">Organization</th>
                <th className="px-3 py-3 font-semibold text-sky-900">Job Position</th>

                <th className="px-3 py-3 font-semibold text-sky-900 text-center">In</th>
                <th className="px-3 py-3 font-semibold text-sky-900 text-center">Out</th>
                <th className="px-3 py-3 font-semibold text-sky-900 text-center">Timeoff</th>
                <th className="px-3 py-3 font-semibold text-sky-900 text-center">Work Hours</th>

                <th className="px-3 py-3 font-semibold text-sky-900 text-center">OT Hours</th>
                <th className="px-3 py-3 font-semibold text-sky-900 text-right">OT 1 (1.5×)</th>
                <th className="px-3 py-3 font-semibold text-sky-900 text-right">OT 2 (2×)</th>
                <th className="px-3 py-3 font-semibold text-sky-900 text-right">OT Total</th>
                <th className="px-3 py-3 font-semibold text-sky-900 text-right">Presence Daily</th>

                <th className="px-3 py-3 font-semibold text-sky-900 text-right">Hourly Rate</th>
                <th className="px-3 py-3 font-semibold text-sky-900 text-center">Billable H</th>
                <th className="px-3 py-3 font-semibold text-sky-900 text-right">Basic Salary</th>
                <th className="px-3 py-3 font-semibold text-sky-900 text-right">Daily Total</th>
                <th className="px-3 py-3 font-semibold text-sky-900 text-center">Tenure ≥1y</th>
              </tr>
            </thead>

            <tbody className="divide-y divide-gray-100">
              {data.length === 0 && (
                <tr><td className="px-4 py-6 text-center text-gray-500" colSpan={22}>No data</td></tr>
              )}

              {useMemo(() => groups, [groups]).map((g) => {
                const serverTotal = employeeTotals?.[g.employee_id] || null
                const totalMonthlyOT        = serverTotal?.monthly_ot        ?? g.monthlyOt
                const totalMonthlyBsott     = serverTotal?.monthly_bsott     ?? g.monthlyBsott
                const totalMonthlyPresence  = serverTotal?.monthly_presence  ?? g.monthlyPresence
                const workDays              = serverTotal?.work_days         ?? 0
                const bpjsTk = serverTotal?.bpjs_tk  ?? g.bpjsTk
                const bpjsKes= serverTotal?.bpjs_kes ?? g.bpjsKes

                return (
                  <React.Fragment key={g.key}>
                    {g.items.map((r) => {
                      const present = isPresent(r)
                      const base    = baseSalary(r)
                      const ot1     = otFirst(r)
                      const ot2     = otSecond(r)
                      const otTot   = otTotal(r)
                      const prem    = presenceDaily(r)
                      const totalDay= totalSalary(r)
                      const toName  = (r.timeoff_name && String(r.timeoff_name).trim() !== '') ? r.timeoff_name : '-'

                      return (
                        <tr key={r.id} className="odd:bg-white even:bg-slate-50 hover:bg-sky-50">
                          {/* identity */}
                          <td className="px-3 py-2 whitespace-nowrap font-mono text-center">{fmtDate(r.schedule_date)}</td>
                          <td className="px-3 py-2 whitespace-nowrap font-mono text-center">{r.employee_id}</td>
                          <td className="px-3 py-2 whitespace-nowrap"><div className="truncate">{r.full_name}</div></td>

                          <td className="px-3 py-2 whitespace-nowrap text-center">{r.gender ?? '-'}</td>
                          <td className="px-3 py-2 whitespace-nowrap text-center">{fmtDate(r.join_date)}</td>
                          <td className="px-3 py-2 whitespace-nowrap"><div className="truncate">{cleanBranchName(r.branch_name)}</div></td>
                          <td className="px-3 py-2 whitespace-nowrap"><div className="truncate">{r.organization_name ?? '-'}</div></td>
                          <td className="px-3 py-2 whitespace-nowrap"><div className="truncate">{r.job_position ?? '-'}</div></td>

                          {/* presence */}
                          <td className="px-3 py-2 whitespace-nowrap text-center">{fmtTime(r.clock_in)}</td>
                          <td className="px-3 py-2 whitespace-nowrap text-center">{fmtTime(r.clock_out)}</td>
                          {/* Timeoff — centered dash */}
                          <td className="px-3 py-2 whitespace-nowrap text-center">
                            <span className="inline-block w-full text-center">{toName || '-'}</span>
                          </td>

                          <td className="px-3 py-2 whitespace-nowrap text-center">
                            <span className={`px-2 py-0.5 rounded-full text-xs font-semibold ${safeNum(r.real_work_hour) >= 7 && present ? 'bg-emerald-100 text-emerald-700':'bg-slate-100 text-slate-700'}`}>
                              {present ? safeNum(r.real_work_hour) : 0} h
                            </span>
                          </td>

                          {/* overtime */}
                          <td className="px-3 py-2 whitespace-nowrap text-center"><span className="px-2 py-0.5 rounded-full text-xs font-semibold bg-blue-100 text-blue-700">{otHours(r)} h</span></td>
                          <td className="px-3 py-2 whitespace-nowrap text-right tabular-nums">{fmtIDR(ot1)}</td>
                          <td className="px-3 py-2 whitespace-nowrap text-right tabular-nums">{fmtIDR(ot2)}</td>
                          <td className={`px-3 py-2 whitespace-nowrap font-bold ${otTot>0?'text-emerald-700':'text-slate-500'} text-right tabular-nums`}>{fmtIDR(otTot)}</td>

                          {/* presence & calc */}
                          <td className="px-3 py-2 whitespace-nowrap text-right tabular-nums">{fmtIDR(prem)}</td>
                          <td className="px-3 py-2 whitespace-nowrap text-right tabular-nums">{fmtIDR(hourlyRate(r))}</td>
                          <td className="px-3 py-2 whitespace-nowrap text-center">{billableHours(r)}</td>
                          <td className="px-3 py-2 whitespace-nowrap text-right tabular-nums">{fmtIDR(base)}</td>
                          <td className="px-3 py-2 whitespace-nowrap text-right tabular-nums font-bold">{fmtIDR(totalDay)}</td>
                          <td className="px-3 py-2 whitespace-nowrap text-center">{fmtBoolBadge(!!r.tenure_ge_1y, 'Yes', 'No')}</td>
                        </tr>
                      )
                    })}

                    {/* per-employee subtotal */}
                    <tr className="bg-amber-50/60">
                      <td className="px-3 py-2 text-amber-700 font-semibold whitespace-nowrap text-right pr-4" colSpan={16}>
                        Grand Total ({g.name})
                      </td>
                      <td className="px-3 py-2 whitespace-nowrap text-right tabular-nums font-extrabold text-amber-700" colSpan={3}>
                        OT: {fmtIDR(totalMonthlyOT)}
                      </td>
                      <td className="px-3 py-2 whitespace-nowrap text-right tabular-nums font-extrabold text-amber-700" colSpan={3}>
                        Daily Total: {fmtIDR(totalMonthlyBsott)}
                      </td>
                    </tr>

                    {/* presence, BPJS & work days */}
                    <tr className="bg-emerald-50/50">
                      <td className="px-3 py-2 whitespace-nowrap text-right tabular-nums" colSpan={22}>
                        <div className="flex flex-wrap items-center justify-end gap-x-6 gap-y-1 text-emerald-800">
                          <span><b>{g.name}</b> — Presence Monthly: <b>{fmtIDR(totalMonthlyPresence)}</b></span>
                          <span>Work Days: <b>{workDays}</b> days</span>
                          <span>BPJS Employment: <b>{fmtIDR(bpjsTk)}</b></span>
                          <span>BPJS Health: <b>{fmtIDR(bpjsKes)}</b></span>
                        </div>
                      </td>
                    </tr>
                  </React.Fragment>
                )
              })}
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
          {groups.map((g) => {
            const serverTotal = employeeTotals?.[g.employee_id] || null
            const totalMonthlyOT        = serverTotal?.monthly_ot        ?? g.monthlyOt
            const totalMonthlyBsott     = serverTotal?.monthly_bsott     ?? g.monthlyBsott
            const totalMonthlyPresence  = serverTotal?.monthly_presence  ?? g.monthlyPresence
            const workDays              = serverTotal?.work_days         ?? 0
            const bpjsTk = serverTotal?.bpjs_tk  ?? g.bpjsTk
            const bpjsKes= serverTotal?.bpjs_kes ?? g.bpjsKes

            return (
              <div key={g.key} className="space-y-3">
                {g.items.map((r) => {
                  const base = baseSalary(r)
                  const ot   = otTotal(r)
                  const bsott= totalSalary(r)
                  const prem = presenceDaily(r)
                  const toName = (r.timeoff_name && String(r.timeoff_name).trim() !== '') ? r.timeoff_name : '-'
                  return (
                    <div key={r.id} className="bg-white rounded-2xl shadow border border-sky-200 p-4">
                      <div className="flex items-start justify-between gap-3">
                        <div className="min-w-0">
                          <div className="text-sm font-semibold">{fmtDate(r.schedule_date)}</div>
                          <div className="text-xs text-slate-500 font-mono">{r.employee_id}</div>
                          <div className="text-base font-semibold truncate">{r.full_name}</div>
                        </div>
                        <div className="text-right shrink-0">
                          <div className="text-xs text-slate-500">Daily Total</div>
                          <div className="text-sm font-extrabold text-slate-800">{fmtIDR(bsott)}</div>
                          <div className="text-xs text-slate-500 mt-1">OT Total</div>
                          <div className={`text-sm font-bold ${ot>0?'text-emerald-700':'text-slate-600'}`}>{fmtIDR(ot)}</div>
                        </div>
                      </div>

                      <div className="mt-3 grid grid-cols-2 gap-2 text-xs">
                        <div className="rounded-lg bg-sky-50 px-2 py-2">In<br /><span className="font-semibold text-sky-700">{fmtTime(r.clock_in)}</span></div>
                        <div className="rounded-lg bg-sky-50 px-2 py-2">Out<br /><span className="font-semibold text-sky-700">{fmtTime(r.clock_out)}</span></div>
                        <div className="rounded-lg bg-sky-50 px-2 py-2 col-span-2">Timeoff<br /><span className="font-semibold text-slate-700">{toName}</span></div>
                        <div className="rounded-lg bg-emerald-50 px-2 py-2">Work Hours<br /><span className="font-semibold text-emerald-700">{isPresent(r)? safeNum(r.real_work_hour): 0} h</span></div>
                        <div className="rounded-lg bg-blue-50 px-2 py-2">OT Hours<br /><span className="font-semibold text-blue-700">{otHours(r)} h</span></div>
                        <div className="rounded-lg bg-amber-50 px-2 py-2 col-span-2">Basic Salary<br /><span className="font-semibold text-amber-700">{fmtIDR(base)}</span></div>
                        <div className="rounded-lg bg-amber-50/60 px-2 py-2 col-span-2">OT 1 / OT 2<br /><span className="font-semibold text-amber-700">{fmtIDR(otFirst(r))} • {fmtIDR(otSecond(r))}</span></div>
                        <div className="rounded-lg bg-emerald-50/60 px-2 py-2 col-span-2">Presence Daily<br /><span className="font-semibold text-emerald-700">{fmtIDR(prem)}</span></div>
                      </div>

                      <div className="mt-3 text-[11px] text-emerald-800 border-t pt-2">
                        <div><b>{g.name}</b> — Presence Monthly: <b>{fmtIDR(totalMonthlyPresence)}</b></div>
                        <div>Work Days: <b>{workDays}</b> days</div>
                        <div>OT: <b>{fmtIDR(totalMonthlyOT)}</b> • Daily Total: <b>{fmtIDR(totalMonthlyBsott)}</b></div>
                        <div>BPJS Employment: <b>{fmtIDR(bpjsTk)}</b> • BPJS Health: <b>{fmtIDR(bpjsKes)}</b></div>
                      </div>
                    </div>
                  )
                })}
              </div>
            )
          })}
        </div>
      </main>
    </div>
  )
}
