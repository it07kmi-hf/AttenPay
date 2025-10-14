import React, { useState } from 'react'
import { useForm } from '@inertiajs/react'

export default function Login({ locale = 'en' }) {
  const { data, setData, post, processing, errors } = useForm({ email:'', password:'', remember:false })
  const [lang, setLang] = useState(locale)

  const t = (en, id) => lang === 'id' ? id : en

  const submit = (e) => {
    e.preventDefault()
    post('/login')
  }

  return (
    <div className="min-h-screen grid place-items-center bg-gradient-to-b from-sky-50 to-emerald-50">
      <div className="w-full max-w-sm bg-white rounded-2xl shadow p-6 space-y-4">
        <div className="flex items-center justify-between">
          <h1 className="text-xl font-bold">Attendance Payroll</h1>
          <select value={lang} onChange={e=>setLang(e.target.value)} className="text-sm border rounded px-2 py-1">
            <option value="en">EN</option>
            <option value="id">ID</option>
          </select>
        </div>
        <form onSubmit={submit} className="space-y-3">
          <div>
            <label className="text-sm">{t('Email','Email')}</label>
            <input className="w-full mt-1 border rounded px-3 py-2" value={data.email}
                   onChange={e=>setData('email', e.target.value)} type="email" />
            {errors.email && <div className="text-xs text-red-600 mt-1">{errors.email}</div>}
          </div>
          <div>
            <label className="text-sm">{t('Password','Kata sandi')}</label>
            <input className="w-full mt-1 border rounded px-3 py-2" value={data.password}
                   onChange={e=>setData('password', e.target.value)} type="password" />
          </div>
          <label className="inline-flex items-center gap-2 text-sm">
            <input type="checkbox" checked={data.remember} onChange={e=>setData('remember', e.target.checked)} />
            {t('Remember me','Ingat saya')}
          </label>
          <button className="w-full bg-sky-600 hover:bg-sky-700 text-white rounded-lg py-2" disabled={processing}>
            {t('Sign in','Masuk')}
          </button>
        </form>
        <p className="text-xs text-gray-500">{t('Seeded user','User seeder')}: <b>admin@attenpay.co.id / attenpay</b></p>
      </div>
    </div>
  )
}
