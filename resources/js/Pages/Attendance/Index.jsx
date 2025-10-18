import React, { useState, useMemo } from 'react'
import { Link, router } from '@inertiajs/react'

const LOGO_KMI = '/img/logo-kmi.png'
const PER_PAGE = 10

export default function Index({ rows, filters, organizations = [], employees = [], employeeTotals = {} }) {
  const [q, setQ] = useState(filters.q || '')
  const [from, setFrom] = useState(filters.from)
  const [to, setTo] = useState(filters.to)
  const [branch] = useState(filters.branch_id)
  const [orgId, setOrgId] = useState(filters.organization_id ?? 'all')
  const [perPage] = useState(PER_PAGE)

  const [mobileFiltersOpen, setMobileFiltersOpen] = useState(false)
  const [mobileExportOpen, setMobileExportOpen] = useState(false)
  const [isSearching, setIsSearching] = useState(false)
  const searchDelayMs = 500
  const _searchTimer = React.useRef(null)

  const [typed, setTyped] = useState('')
  React.useEffect(() => {
    const full = 'Attendance Payroll System'
    let i = 0
    const t = setInterval(() => {
      setTyped(full.slice(0, i + 1))
      i++
      if (i >= full.length) clearInterval(t)
    }, 40)
    return () => clearInterval(t)
  }, [])

  React.useEffect(() => {
    if (_searchTimer.current) clearTimeout(_searchTimer.current)
    _searchTimer.current = setTimeout(() => {
      setIsSearching(true)
      router.get('/attendance',
        { q, from, to, branch_id: branch, per_page: perPage, organization_id: orgId },
        { preserveState: true, replace: true, preserveScroll: true, only: ['rows','filters','employeeTotals'] }
      )
      setIsSearching(false)
    }, searchDelayMs)
    return () => { if (_searchTimer.current) clearTimeout(_searchTimer.current) }
  }, [q])

  const data = rows?.data ?? []

  const fmtTime = (val) => {
    if (!val) return <span className="inline-block w-full text-center">-</span>
    const m = String(val).match(/\b(\d{2}:\d{2})/)
    return m ? m[1] : val
  }
  const fmtIDR = (n) => `Rp ${Number(n || 0).toLocaleString('id-ID')}`
  const safeNum = (v) => (Number.isFinite(Number(v)) ? Number(v) : 0)
  const fmtBoolBadge = (v, yes='Yes', no='No') => (
    <span className={`px-2 py-0.5 rounded-full text-xs font-semibold inline-block text-center ${v ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-700'}`}>{v ? yes : no}</span>
  )
  const fmtDate = (v) => (v ? String(v).substring(0,10) : '-')
  const cleanBranchName = (name) => !name ? '-' : String(name).split(/\s[–—-]\s/)[0]

  const isPresent = (r) => !!(r.clock_in && r.clock_out && Number(r.real_work_hour) > 0)
  const hourlyRate = (r) => isPresent(r) ? safeNum(r.hourly_rate_used) : 0
  const billableHours = (r) => {
    if (!isPresent(r)) return 0
    if (r.daily_billable_hours !== undefined && r.daily_billable_hours !== null) return Math.max(0, safeNum(r.daily_billable_hours))
    return Math.min(7, Math.max(0, safeNum(r.real_work_hour)))
  }
  const baseSalary = (r) => Math.round(billableHours(r) * hourlyRate(r))
  const otFirst  = (r) => isPresent(r) ? safeNum(r.overtime_first_amount)  : 0
  const otSecond = (r) => isPresent(r) ? safeNum(r.overtime_second_amount) : 0
  const otTotal  = (r) => isPresent(r) ? safeNum(r.overtime_total_amount)  : 0
  const otHours  = (r) => isPresent(r) ? safeNum(r.overtime_hours)         : 0
  const presenceDaily = (r) => isPresent(r) ? safeNum(r.presence_premium_daily) : 0
  const totalSalary = (r) => {
    const v = Number(r.daily_total_amount)
    if (r.daily_total_amount !== undefined && r.daily_total_amount !== null && Number.isFinite(v)) return v
    return isPresent(r) ? (baseSalary(r) + otTotal(r)) : 0
  }

  const search = (e) => {
    e.preventDefault()
    router.get('/attendance',
      { q, from, to, branch_id: branch, per_page: perPage, organization_id: orgId },
      { preserveState: true, replace: true, preserveScroll: true }
    )
  }
  const resetFilters = (e) => {
    e.preventDefault()
    setQ('')
    setOrgId('all')
    router.get('/attendance',
      { q: '', branch_id: branch, per_page: perPage, organization_id: 'all' },
      { replace: true, preserveScroll: true }
    )
  }

  const exportUrl = (fmt) =>
    `/attendance/export?format=${fmt}&from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}&branch_id=${encodeURIComponent(branch)}&organization_id=${encodeURIComponent(orgId)}&q=${encodeURIComponent(q)}`
  const confirmExport = async (fmt) => {
    const nice = { csv: 'CSV', xlsx: 'Excel', pdf: 'PDF Rekap' }[fmt] || fmt.toUpperCase()
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
        width: 520,
      })
      if (res.isConfirmed) window.location.href = exportUrl(fmt)
    } catch {
      const totalRows = (rows?.total ?? rows?.meta?.total ?? 0)
      if (window.confirm(`Export ${totalRows} row(s)\nPeriod ${from} → ${to}\nFormat ${nice} ?`)) {
        window.location.href = exportUrl(fmt)
      }
    }
  }

  // ======= NEW: SweetAlert confirmation before LOGOUT (English) =======
  const confirmLogout = async (e) => {
    if (e && typeof e.preventDefault === 'function') e.preventDefault()
    try {
      const Swal = (await import('sweetalert2')).default
      const res = await Swal.fire({
        title: 'Logout',
        text: 'Are you sure you want to log out?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, log out',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#dc2626',
        focusCancel: true,
        reverseButtons: true,
        width: 440,
      })
      if (res.isConfirmed) {
        router.post('/logout')
      }
    } catch {
      if (window.confirm('Are you sure you want to log out?')) {
        router.post('/logout')
      }
    }
  }

  // ======= MODAL PAYSLIP (Select2-like, wider modal) =======
  const askPayslipExport = async () => {
    const now = new Date()
    const ym = `${now.getFullYear()}-${String(now.getMonth()+1).padStart(2,'0')}`

    const optionsHTML = employees.map(e =>
      `<option data-id="${String(e.id).replace(/"/g,'&quot;')}"
               data-name="${String(e.name).replace(/"/g,'&quot;')}"
               value="${String(e.name).replace(/"/g,'&quot;')}">${e.name} — ${e.id}</option>`
    ).join('')

    try {
      const Swal = (await import('sweetalert2')).default
      const { value: formValues } = await Swal.fire({
        title: 'Export Payslip (PDF)',
        html: `
          <div style="text-align:left">
            <div style="display:flex; gap:16px; align-items:flex-start">
              <div style="min-width:16rem">
                <label style="font-size:12px;color:#334155;display:block;margin-bottom:6px">Choose month</label>
                <input id="slip-month" type="month" value="${ym}" class="swal2-input" style="width:15rem;margin:0;height:2.6rem;padding:.35rem .6rem" />
              </div>
              <div style="flex:1; min-width:22rem">
                <label style="font-size:12px;color:#334155;display:block;margin-bottom:6px">Employee name (optional)</label>
                <div class="swal2-input" style="margin:0;padding:.6rem;height:auto;box-sizing:border-box">
                  <div style="display:flex; gap:8px; align-items:center">
                    <input id="slip-emp-search" placeholder="Type name… (Enter = Export)" style="flex:1;border:1px solid #cbd5e1;border-radius:.375rem;padding:.5rem .6rem;font-size:14px" />
                    <button id="slip-emp-clear" type="button" style="border:1px solid #cbd5e1;border-radius:.375rem;padding:.45rem .7rem;background:#fff">Clear</button>
                  </div>
                  <select id="slip-employee" size="9" style="margin-top:.6rem;width:100%;border:1px solid #cbd5e1;border-radius:.375rem;padding:.3rem .4rem;background:#fff;max-height:18rem;overflow:auto">
                    ${optionsHTML}
                  </select>
                  <div id="slip-emp-selected" style="margin-top:.4rem;font-size:12px;color:#0f766e;display:none">
                    Selected: <b id="slip-emp-picked"></b>
                  </div>
                </div>
              </div>
            </div>
          </div>
        `,
        showCancelButton: true,
        confirmButtonText: 'Export PDF',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#6366f1',
        focusConfirm: false,
        width: 820,
        didOpen: () => {
          const search = document.getElementById('slip-emp-search')
          const select = document.getElementById('slip-employee')
          const clearBtn = document.getElementById('slip-emp-clear')
          const pickedWrap = document.getElementById('slip-emp-selected')
          const pickedText = document.getElementById('slip-emp-picked')

          const all = Array.from(select.querySelectorAll('option')).map(o => ({
            id: o.dataset.id, name: o.dataset.name, text: o.text
          }))

          const render = (q='') => {
            const qq = q.toLowerCase()
            select.innerHTML = ''
            all.forEach(o => {
              if (o.text.toLowerCase().includes(qq)) {
                const opt = document.createElement('option')
                opt.value = o.name
                opt.text = o.text
                opt.dataset.id = o.id
                opt.dataset.name = o.name
                select.appendChild(opt)
              }
            })
            if (select.options.length) select.selectedIndex = 0
          }

          const fillFromSelected = () => {
            const opt = select.options[select.selectedIndex]
            if (!opt) return
            search.value = opt.value
            pickedText.textContent = opt.value
            pickedWrap.style.display = 'block'
            document.querySelector('.swal2-confirm')?.focus()
          }

          render()
          search.focus()

          search.addEventListener('input', () => render(search.value))
          search.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowDown') { e.preventDefault(); select.focus(); }
            if (e.key === 'Enter') { document.querySelector('.swal2-confirm')?.click() }
          })

          select.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') { e.preventDefault(); fillFromSelected(); document.querySelector('.swal2-confirm')?.click() }
            if (e.key === 'Escape') search.focus()
          })
          select.addEventListener('click', () => fillFromSelected())
          select.addEventListener('dblclick', () => { fillFromSelected(); document.querySelector('.swal2-confirm')?.click() })
          clearBtn.addEventListener('click', () => { search.value=''; pickedWrap.style.display='none'; pickedText.textContent=''; render(''); search.focus() })
        },
        preConfirm: () => {
          const m = document.getElementById('slip-month')?.value
          const search = document.getElementById('slip-emp-search')
          if (!m) { Swal.showValidationMessage('Month is required'); return false }
          return { m, n: (search?.value || '').trim() } // empty name = all employees
        }
      })
      if (!formValues) return
      const m = formValues.m
      const name = formValues.n ?? ''
      const url = `/attendance/export-payslips?month=${encodeURIComponent(m)}&branch_id=${encodeURIComponent(branch)}&organization_id=${encodeURIComponent(orgId)}&name=${encodeURIComponent(name)}`
      window.location.href = url
    } catch {
      const m = prompt('Month (YYYY-MM):', ym); if (!m) return
      const name = prompt('Employee name (optional):', '') || ''
      const url = `/attendance/export-payslips?month=${encodeURIComponent(m)}&branch_id=${encodeURIComponent(branch)}&organization_id=${encodeURIComponent(orgId)}&name=${encodeURIComponent(name)}`
      window.location.href = url
    }
  }

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
    router.get('/attendance',
      { q, from, to, branch_id: branch, per_page: perPage, organization_id: orgId, page },
      { preserveState: true, preserveScroll: true, replace: true }
    )
  }

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
      g.monthlyOt       += (isPresent(r) ? safeNum(r.overtime_total_amount) : 0)
      g.monthlyBsott    += totalSalary(r)
      g.monthlyPresence += (isPresent(r) ? safeNum(r.presence_premium_daily) : 0)
      if (!g.bpjsTk)  g.bpjsTk  = safeNum(r.bpjs_tk_deduction)
      if (!g.bpjsKes) g.bpjsKes = safeNum(r.bpjs_kes_deduction)
    })
    return order.map(k => map.get(k))
  }, [data])

  return (
    <div className="min-h-dvh bg-slate-50" style={{ paddingTop:'env(safe-area-inset-top)', paddingBottom:'env(safe-area-inset-bottom)', paddingLeft:'env(safe-area-inset-left)', paddingRight:'env(safe-area-inset-right)' }}>
      {/* Header */}
      <header className="sticky top-0 z-30 bg-gradient-to-r from-sky-600 via-blue-600 to-emerald-600 text-white shadow">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-3 md:py-4 flex items-center justify-between gap-3">
          <div className="min-w-0 flex items-center gap-4 sm:gap-5">
            <div className="h-10 w-10 md:h-11 md:w-11 shrink-0 rounded-full bg-white p-1 ring-1 ring-white/50 shadow-sm">
              <img src={LOGO_KMI} alt="KMI Logo" className="h-full w-full object-contain" loading="eager" decoding="async" onError={(e) => (e.currentTarget.style.display = 'none')} />
            </div>
            <div className="min-w-0">
              <div className="text-[11px] opacity-90 -mb-0.5">Welcome —</div>
              <h1 className="text-lg sm:text-2xl font-extrabold truncate"><span>{typed}</span><span className="animate-pulse ml-1 align-middle">▍</span></h1>
              <p className="text-xs sm:text-sm opacity-90 truncate"><b>PT Kayu Mebel Indonesia (KMI)</b></p>
            </div>
          </div>

          <div className="hidden md:flex items-center gap-3 shrink-0">
            <div className="flex overflow-hidden rounded-xl border border-white/40 shadow-sm">
              <button onClick={() => confirmExport('csv')}  className="px-3 py-2 text-sm bg-white/15 hover:bg-white/25">CSV</button>
              <button onClick={() => confirmExport('xlsx')} className="px-3 py-2 text-sm bg-white/15 hover:bg-white/25 border-l border-white/30">Excel</button>
              <button onClick={() => confirmExport('pdf')}  className="px-3 py-2 text-sm bg-white/15 hover:bg-white/25 border-l border-white/30">PDF</button>
              <button onClick={askPayslipExport}            className="px-3 py-2 text-sm bg-white/15 hover:bg-white/25 border-l border-white/30">Payslip PDF</button>
            </div>
            {/* Logout (Desktop) with SweetAlert confirmation */}
            <Link
              href="/logout"
              method="post"
              as="button"
              onClick={confirmLogout}
              aria-label="Logout"
              className="px-3 py-2 rounded-xl border border-white/40 bg-white/10 hover:bg-white/20 flex items-center gap-2"
              title="Logout"
            >
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" className="h-5 w-5 text-red-300">
                <path strokeLinecap="round" strokeLinejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6A2.25 2.25 0 0 0 5.25 5.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15" />
                <path strokeLinecap="round" strokeLinejoin="round" d="M12 9l3 3m0 0l-3 3m3-3H3" />
              </svg>
              <span className="font-medium">Logout</span>
            </Link>
          </div>

          <div className="md:hidden flex items-center gap-2 shrink-0">
            <button onClick={() => setMobileFiltersOpen(v => !v)} className="px-2.5 py-1.5 text-sm bg-white/15 hover:bg-white/25 border border-white/30 rounded-md">Filters</button>
            <div className="relative">
              <button onClick={() => setMobileExportOpen(v => !v)} className="px-2.5 py-1.5 text-sm bg-white/15 hover:bg-white/25 border border-white/30 rounded-md">Export</button>
              {mobileExportOpen && (
                <div className="absolute right-0 mt-2 w-44 rounded-lg overflow-hidden border border-white/40 bg-white/90 text-slate-800 shadow-lg backdrop-blur">
                  <button onClick={() => { setMobileExportOpen(false); confirmExport('csv') }} className="block w-full text-left px-3 py-2 hover:bg-slate-100">CSV</button>
                  <button onClick={() => { setMobileExportOpen(false); confirmExport('xlsx') }} className="block w-full text-left px-3 py-2 hover:bg-slate-100 border-t">Excel</button>
                  <button onClick={() => { setMobileExportOpen(false); confirmExport('pdf') }} className="block w-full text-left px-3 py-2 hover:bg-slate-100 border-t">PDF</button>
                  <button onClick={() => { setMobileExportOpen(false); askPayslipExport() }} className="block w-full text-left px-3 py-2 hover:bg-slate-100 border-t">Payslip PDF</button>
                </div>
              )}
            </div>
            {/* Logout (Mobile) with SweetAlert confirmation */}
            <Link
              href="/logout"
              method="post"
              as="button"
              onClick={confirmLogout}
              aria-label="Logout"
              className="px-3 py-1.5 rounded-md border border-white/40 bg-white/10 hover:bg-white/20 flex items-center gap-1.5"
              title="Logout"
            >
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" className="h-5 w-5 text-red-300">
                <path strokeLinecap="round" strokeLinejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6A2.25 2.25 0 0 0 5.25 5.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15" />
                <path strokeLinecap="round" strokeLinejoin="round" d="M12 9l3 3m0 0l-3 3m3-3H3" />
              </svg>
              <span className="text-sm">Logout</span>
            </Link>
          </div>
        </div>
      </header>

      {/* Period badge directly UNDER the blue header, matching your screenshot */}
      <div className="bg-slate-50">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-2">
          <div className="inline-flex items-center gap-1.5 rounded-full border border-sky-300 bg-white px-3 py-0.5 text-[10px] sm:text-[11px] text-sky-700 shadow ring-1 ring-sky-100/60">
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
          <div className="grid grid-cols-1 md:grid-cols-6 gap-3 items-end">
            <div className="md:col-span-2">
              <label className="text-sm">From</label>
              <input type="date" className="w-full border border-slate-300 rounded-md px-2.5 py-1.5 text-sm outline-none focus:border-sky-400 focus:ring-1 focus:ring-sky-300" value={from} onChange={e => setFrom(e.target.value)} />
            </div>
            <div className="md:col-span-2">
              <label className="text-sm">To</label>
              <input type="date" className="w-full border border-slate-300 rounded-md px-2.5 py-1.5 text-sm outline-none focus:border-sky-400 focus:ring-1 focus:ring-sky-300" value={to} onChange={e => setTo(e.target.value)} />
            </div>
            <div className="md:col-span-1">
              <label className="text-sm">Organization</label>
              <select className="w-full border border-slate-300 rounded-md px-2.5 py-1.5 text-sm outline-none focus:border-sky-400 focus:ring-1 focus:ring-sky-300" value={orgId} onChange={(e) => setOrgId(e.target.value)}>
                <option value="all">All Organizations</option>
                {organizations.map(o => <option key={o.id} value={o.id}>{o.name}</option>)}
              </select>
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

        {/* TABLE */}
        <div className="bg-white rounded-2xl shadow border border-sky-200 overflow-x-auto" role="region" aria-label="Attendance table" tabIndex={0}>
          <table className="min-w-[2300px] text-xs md:text-sm table-fixed">
            <colgroup>
              <col className="w-[7rem]" /><col className="w-[7rem]" /><col className="w-[12rem]" />
              <col className="w-[6rem]" /><col className="w-[8rem]" />
              <col className="w-[14rem]" /><col className="w-[14rem]" /><col className="w-[12rem]" />
              <col className="w-[6rem]" /><col className="w-[6rem]" />
              <col className="w-[12rem]" />
              <col className="w-[7rem]" />
              <col className="w-[6.5rem]" /><col className="w-[8rem]" /><col className="w-[8rem]" /><col className="w-[8rem]" />
              <col className="w-[8rem]" />
              <col className="w-[8rem]" /><col className="w-[6.5rem]" /><col className="w-[9rem]" /><col className="w-[9rem]" />
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
              {data.length === 0 && (<tr><td className="px-4 py-6 text-center text-gray-500" colSpan={22}>No data</td></tr>)}
              {useMemo(() => groups, [groups]).map((g) => {
                const serverTotal = employeeTotals?.[g.employee_id] || null
                const totalMonthlyOT        = serverTotal?.monthly_ot        ?? g.monthlyOt
                const totalMonthlyBsott     = serverTotal?.monthly_bsott     ?? g.monthlyBsott
                const totalMonthlyPresence  = serverTotal?.monthly_presence  ?? g.monthlyPresence
                const bpjsTk = serverTotal?.bpjs_tk  ?? g.bpjsTk
                const bpjsKes= serverTotal?.bpjs_kes ?? g.bpjsKes
                const workDays = serverTotal?.work_days ?? 0

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
                          <td className="px-3 py-2 whitespace-nowrap font-mono text-center">{fmtDate(r.schedule_date)}</td>
                          <td className="px-3 py-2 whitespace-nowrap font-mono text-center">{r.employee_id}</td>
                          <td className="px-3 py-2 whitespace-nowrap"><div className="truncate">{r.full_name}</div></td>
                          <td className="px-3 py-2 whitespace-nowrap text-center">{r.gender ?? '-'}</td>
                          <td className="px-3 py-2 whitespace-nowrap text-center">{fmtDate(r.join_date)}</td>
                          <td className="px-3 py-2 whitespace-nowrap"><div className="truncate">{cleanBranchName(r.branch_name)}</div></td>
                          <td className="px-3 py-2 whitespace-nowrap"><div className="truncate">{r.organization_name ?? '-'}</div></td>
                          <td className="px-3 py-2 whitespace-nowrap"><div className="truncate">{r.job_position ?? '-'}</div></td>
                          <td className="px-3 py-2 whitespace-nowrap text-center">{fmtTime(r.clock_in)}</td>
                          <td className="px-3 py-2 whitespace-nowrap text-center">{fmtTime(r.clock_out)}</td>
                          <td className="px-3 py-2 whitespace-nowrap text-center"><span className="inline-block w-full text-center">{toName || '-'}</span></td>
                          <td className="px-3 py-2 whitespace-nowrap text-center">
                            <span className={`px-2 py-0.5 rounded-full text-xs font-semibold ${safeNum(r.real_work_hour) >= 7 && isPresent(r) ? 'bg-emerald-100 text-emerald-700':'bg-slate-100 text-slate-700'}`}>
                              {isPresent(r) ? safeNum(r.real_work_hour) : 0} h
                            </span>
                          </td>
                          <td className="px-3 py-2 whitespace-nowrap text-center"><span className="px-2 py-0.5 rounded-full text-xs font-semibold bg-blue-100 text-blue-700">{otHours(r)} h</span></td>
                          <td className="px-3 py-2 whitespace-nowrap text-right tabular-nums">{fmtIDR(ot1)}</td>
                          <td className="px-3 py-2 whitespace-nowrap text-right tabular-nums">{fmtIDR(ot2)}</td>
                          <td className={`px-3 py-2 whitespace-nowrap font-bold ${otTot>0?'text-emerald-700':'text-slate-500'} text-right tabular-nums`}>{fmtIDR(otTot)}</td>
                          <td className="px-3 py-2 whitespace-nowrap text-right tabular-nums">{fmtIDR(prem)}</td>
                          <td className="px-3 py-2 whitespace-nowrap text-right tabular-nums">{fmtIDR(hourlyRate(r))}</td>
                          <td className="px-3 py-2 whitespace-nowrap text-center">{billableHours(r)}</td>
                          <td className="px-3 py-2 whitespace-nowrap text-right tabular-nums">{fmtIDR(base)}</td>
                          <td className="px-3 py-2 whitespace-nowrap text-right tabular-nums font-bold">{fmtIDR(totalDay)}</td>
                          <td className="px-3 py-2 whitespace-nowrap text-center">{fmtBoolBadge(!!r.tenure_ge_1y, 'Yes', 'No')}</td>
                        </tr>
                      )
                    })}

                    <tr className="bg-amber-50/60">
                      <td className="px-3 py-2 text-amber-700 font-semibold whitespace-nowrap text-right pr-4" colSpan={16}>Grand Total ({g.name})</td>
                      <td className="px-3 py-2 whitespace-nowrap text-right tabular-nums font-extrabold text-amber-700" colSpan={3}>OT: {fmtIDR(totalMonthlyOT)}</td>
                      <td className="px-3 py-2 whitespace-nowrap text-right tabular-nums font-extrabold text-amber-700" colSpan={3}>Daily Total: {fmtIDR(totalMonthlyBsott)}</td>
                    </tr>

                    <tr className="bg-emerald-50/50">
                      <td className="px-3 py-2 whitespace-nowrap text-right tabular-nums" colSpan={22}>
                        <div className="flex flex-wrap items-center justify-end gap-x-6 gap-y-1 text-emerald-800">
                          <span><b>{g.name}</b> — Presence Monthly: <b>{fmtIDR(totalMonthlyPresence)}</b></span>
                          <span>Work Days: <b>{workDays}</b> days</span>
                          <span>BPJS TK: <b>{fmtIDR(bpjsTk)}</b></span>
                          <span>BPJS Kesehatan: <b>{fmtIDR(bpjsKes)}</b></span>
                        </div>
                      </td>
                    </tr>
                  </React.Fragment>
                )
              })}
            </tbody>
          </table>
        </div>

        <div className="flex items-center justify-between text-sm flex-wrap gap-3">
          <div>Total: {total}</div>
          <div className="flex items-center gap-2 flex-wrap">
            <button onClick={() => goto(Math.max(1, current - 1))} disabled={current === 1} className={`px-3 py-1 rounded border ${current === 1 ? 'bg-slate-100 text-slate-400 cursor-not-allowed' : 'bg-white hover:bg-slate-50'}`}>Prev</button>
            {makePages(current, last).map((p, i) => p === '…'
              ? <span key={`e-${i}`} className="px-2 text-slate-500 select-none">…</span>
              : <button key={p} onClick={() => goto(p)} className={`px-3 py-1 rounded border ${p === current ? 'bg-sky-600 text-white border-sky-600' : 'bg-white hover:bg-slate-50'}`}>{p}</button>
            )}
            <button onClick={() => goto(Math.min(last, current + 1))} disabled={current === last} className={`px-3 py-1 rounded border ${current === last ? 'bg-slate-100 text-slate-400 cursor-not-allowed' : 'bg-white hover:bg-slate-50'}`}>Next</button>
          </div>
        </div>
      </main>
    </div>
  )
}
