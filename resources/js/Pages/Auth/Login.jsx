import React, { useState } from 'react'
import { useForm, usePage } from '@inertiajs/react'

export default function Login() {
  const { data, setData, post, processing, errors, reset } = useForm({
    email: '',
    password: '',
    remember: false,
  })
  const { props } = usePage()
  const flashError = props?.flash?.error || props?.error

  const [showPassword, setShowPassword] = useState(false)
  const [capsLockOn, setCapsLockOn] = useState(false)

  const submit = (e) => {
    e.preventDefault()
    post('/login', { onFinish: () => reset('password') })
  }

  const baseInput =
    'w-full mt-1 px-3 py-2 rounded-lg border outline-none transition ' +
    'focus:ring-2 focus:ring-sky-400 focus:border-sky-400 placeholder:text-slate-400'
  const errorInput = 'border-red-300 bg-red-50/50'
  const normalInput = 'border-slate-300 bg-white/90'

  const copy = async (txt) => {
    try { await navigator.clipboard.writeText(txt) } catch {}
  }

  return (
    <div className="min-h-screen relative overflow-hidden">
      {/* Gradient background */}
      <div className="absolute inset-0 bg-gradient-to-br from-sky-200 via-indigo-200 to-emerald-200" />
      {/* Decorative blobs */}
      <div className="absolute -top-24 -left-24 h-72 w-72 rounded-full bg-sky-400/30 blur-3xl" />
      <div className="absolute -bottom-24 -right-24 h-72 w-72 rounded-full bg-emerald-400/30 blur-3xl" />

      {/* Centered content */}
      <div className="relative min-h-screen p-4 grid place-items-center">
        <div className="w-full max-w-md">
          <div className="rounded-2xl shadow-2xl border border-white/30 bg-white/60 backdrop-blur-md overflow-hidden">
            {/* Header: centered logo + texts */}
            <div className="px-6 pt-8 pb-6 text-center bg-gradient-to-r from-sky-600 via-blue-600 to-emerald-600 text-white">
              <div className="mx-auto h-16 w-16 rounded-2xl bg-white/20 ring-2 ring-white/30 grid place-items-center text-lg font-bold">
                AP
              </div>
              <h1 className="mt-3 text-2xl font-extrabold tracking-tight">Attendance Payroll</h1>
              <p className="text-sm/6 opacity-95">Sign in to continue</p>
            </div>

            {/* Body */}
            <div className="p-6 md:p-7">
              {(flashError || errors.email || errors.password) && (
                <div className="mb-4 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                  {flashError ||
                    errors.email ||
                    errors.password ||
                    'Something went wrong. Please check your credentials.'}
                </div>
              )}

              <form onSubmit={submit} className="space-y-4">
                {/* Email */}
                <div>
                  <label className="text-sm text-slate-800">Email</label>
                  <div className="relative">
                    <span className="pointer-events-none absolute inset-y-0 left-3 flex items-center text-slate-400">
                      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden>
                        <path d="M4 6h16v12H4V6Zm0 0l8 6 8-6" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round"/>
                      </svg>
                    </span>
                    <input
                      type="email"
                      autoComplete="username"
                      className={`${baseInput} ${errors.email ? errorInput : normalInput} pl-10`}
                      placeholder="you@company.com"
                      value={data.email}
                      onChange={(e) => setData('email', e.target.value)}
                    />
                  </div>
                </div>

                {/* Password */}
                <div>
                  <label className="text-sm text-slate-800">Password</label>
                  <div className="relative">
                    <span className="pointer-events-none absolute inset-y-0 left-3 flex items-center text-slate-400">
                      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden>
                        <path d="M7 10V8a5 5 0 1 1 10 0v2" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round"/>
                        <rect x="5" y="10" width="14" height="10" rx="2" stroke="currentColor" strokeWidth="1.6"/>
                      </svg>
                    </span>
                    <input
                      type={showPassword ? 'text' : 'password'}
                      autoComplete="current-password"
                      className={`${baseInput} ${errors.password ? errorInput : normalInput} pl-10 pr-10`}
                      placeholder="••••••••"
                      value={data.password}
                      onChange={(e) => setData('password', e.target.value)}
                      onKeyUp={(e) => setCapsLockOn(e.getModifierState && e.getModifierState('CapsLock'))}
                    />
                    <button
                      type="button"
                      onClick={() => setShowPassword((v) => !v)}
                      className="absolute inset-y-0 right-2 my-1 px-2 grid place-items-center rounded-md text-slate-600 hover:text-slate-800 hover:bg-white/60"
                      aria-label={showPassword ? 'Hide password' : 'Show password'}
                    >
                      {showPassword ? (
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden>
                          <path d="M3 3l18 18M10.6 10.6a3 3 0 004.24 4.24M9.88 4.1A9.53 9.53 0 0112 4c6 0 9.5 6 9.5 6a16.63 16.63 0 01-3.15 3.78M6.2 6.2C3.8 7.9 2.5 10 2.5 10s3.5 6 9.5 6c1.04 0 2.04-.18 2.98-.51" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round"/>
                        </svg>
                      ) : (
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden>
                          <path d="M2.5 10s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6S2.5 10 2.5 10Z" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round"/>
                          <circle cx="12" cy="10" r="3" stroke="currentColor" strokeWidth="1.6"/>
                        </svg>
                      )}
                    </button>
                  </div>
                  {capsLockOn && (
                    <div className="mt-1 text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded px-2 py-1 inline-block">
                      Caps Lock is ON
                    </div>
                  )}
                </div>

                {/* Remember + Submit */}
                <div className="flex items-center justify-between">
                  <label className="inline-flex items-center gap-2 text-sm text-slate-800">
                    <input
                      type="checkbox"
                      className="accent-sky-600"
                      checked={data.remember}
                      onChange={(e) => setData('remember', e.target.checked)}
                    />
                    Remember me
                  </label>

                  <button
                    type="submit"
                    disabled={processing}
                    className="inline-flex items-center gap-2 bg-sky-600 hover:bg-sky-700 disabled:opacity-60 text-white rounded-lg px-4 py-2 shadow"
                  >
                    {processing && (
                      <svg className="animate-spin h-4 w-4" viewBox="0 0 24 24" aria-hidden>
                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="white" strokeWidth="3" fill="none" />
                        <path className="opacity-75" fill="white" d="M4 12a8 8 0 018-8v3a5 5 0 00-5 5H4z" />
                      </svg>
                    )}
                    Sign in
                  </button>
                </div>
              </form>

              {/* Centered seeded user box */}
              <div className="mt-6 text-center">
                <div className="inline-flex items-center gap-2 rounded-lg bg-white/70 border border-white/60 px-3 py-2 text-xs text-slate-700">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" className="text-sky-600">
                    <path d="M12 5v6l4 2" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round"/>
                    <circle cx="12" cy="12" r="9" stroke="currentColor" strokeWidth="1.4"/>
                  </svg>
                  <span>Seeded user:</span>
                  <code className="bg-slate-100/70 rounded px-1 py-0.5">admin@attenpay.co.id</code>
                  <span>/</span>
                  <code className="bg-slate-100/70 rounded px-1 py-0.5">attenpay</code>

                  {/* quick copy buttons (optional, safe to keep) */}
                  <button onClick={() => copy('admin@attenpay.co.id')} className="ml-1 rounded px-1.5 py-0.5 text-slate-600 hover:bg-slate-100" title="Copy email">
                    ⧉
                  </button>
                  <button onClick={() => copy('attenpay')} className="rounded px-1.5 py-0.5 text-slate-600 hover:bg-slate-100" title="Copy password">
                    ⧉
                  </button>
                </div>
              </div>
            </div>
          </div>

          {/* Footer */}
          <p className="text-center text-[11px] text-slate-700 mt-4">
            © {new Date().getFullYear()} PT Kayu Mebel Indonesia — All Rights Reserved
          </p>
        </div>
      </div>
    </div>
  )
}
