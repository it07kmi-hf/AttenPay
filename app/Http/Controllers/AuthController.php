<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class AuthController extends Controller
{
    public function showLogin()
    {
        return Inertia::render('Auth/Login', ['locale' => app()->getLocale()]);
    }

    public function login(Request $req)
    {
        $creds = $req->validate([
            'email' => ['required','email'],
            'password' => ['required'],
        ]);
        if (Auth::attempt($creds, $req->boolean('remember'))) {
            $req->session()->regenerate();
            return redirect()->intended(route('attendance.index'));
        }
        return back()->withErrors(['email' => 'Invalid credentials.'])->onlyInput('email');
    }

    public function logout(Request $req)
    {
        Auth::logout();
        $req->session()->invalidate();
        $req->session()->regenerateToken();
        return redirect()->route('login');
    }
}
