@extends('layouts.app')

@section('title', 'Academic Portal')

@section('content')
<div class="w-full max-w-sm z-20 transition-all duration-500" id="main-content">

    <div class="text-center mb-8">
        <h1 class="font-orbitron text-4xl font-black tracking-tighter text-white glitch-title leading-none">
            MATH<span class="text-cyan-400">VERSE</span>
        </h1>
        <p class="text-slate-400 uppercase text-[9px] tracking-[0.4em] mt-2 font-bold">Official Academic Gateway</p>
    </div>

    <div class="portal-frame relative">
        <div class="corner top-0 left-0 border-r-0 border-b-0"></div>
        <div class="corner top-0 right-0 border-l-0 border-b-0"></div>
        <div class="corner bottom-0 left-0 border-r-0 border-t-0"></div>
        <div class="corner bottom-0 right-0 border-l-0 border-t-0"></div>

        <div class="p-6">

            {{-- LOGIN PANEL --}}
            <div id="loginMod" class="module module-active">
                <div class="flex justify-between items-center mb-6 border-b border-cyan-500/30 pb-4">
                    <h2 class="text-2xl font-orbitron font-black text-white tracking-tighter uppercase">
                        Login <span class="text-cyan-400">Account</span>
                    </h2>
                    <i class="fas fa-user-shield text-2xl text-cyan-500/40"></i>
                </div>

                <div class="text-center mb-6 bg-white/5 border border-white/10 rounded py-2">
                    <p class="text-[9px] text-slate-400 uppercase tracking-widest font-bold">
                        <i class="fas fa-microchip text-red-500 mr-1"></i> Admin &nbsp;|&nbsp;
                        <i class="fas fa-chalkboard-teacher text-purple-400 mr-1"></i> Teacher &nbsp;|&nbsp;
                        <i class="fas fa-user-graduate text-cyan-400 mr-1"></i> Student
                    </p>
                </div>

                {{-- Laravel form: POST to /login --}}
                <form method="POST" action="/login" class="space-y-5" autocomplete="off">
                    @csrf
                    <div class="form-group">
                        <label class="input-label">Email Address</label>
                        <div class="relative">
                            <i class="fas fa-envelope input-icon"></i>
                            <input type="email" name="email" required
                                   value="{{ old('email') }}"
                                   class="input-mobile-ultra" placeholder="Enter email" autocomplete="off">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="input-label">Password</label>
                        <div class="relative">
                            <i class="fas fa-lock input-icon"></i>
                            <input type="password" id="lPass" name="password" required
                                   class="input-mobile-ultra pr-12" placeholder="Enter password" autocomplete="off">
                            <button type="button" data-action="tglPass" data-action-args='["lPass","lIcon"]'
                                    class="absolute right-2 top-1/2 -translate-y-1/2 w-10 h-8 flex items-center justify-center text-slate-500">
                                <i id="lIcon" class="fas fa-eye-slash"></i>
                            </button>
                        </div>
                    </div>

                    <div class="flex justify-end">
                        <button type="button" data-action="openForgotModal"
                                class="text-cyan-500 text-[10px] font-bold uppercase tracking-wider hover:text-white transition-all">
                            Forgot Password?
                        </button>
                    </div>

                    <button type="submit" class="btn-mobile-ultra">Sign In</button>
                </form>

                <div class="mt-8 text-center border-t border-white/5 pt-6">
                    <button type="button" data-action="swMod" data-action-args='["reg"]'
                            class="text-slate-300 text-[10px] font-black uppercase tracking-[0.2em] border border-white/20 px-6 py-2 hover:bg-white/5 transition-all">
                        Create New Account
                    </button>
                </div>
            </div>

            {{-- REGISTER PANEL --}}
            <div id="regMod" class="module">
                <div class="flex justify-between items-center mb-6 border-b border-purple-500/30 pb-4">
                    <h2 class="text-2xl font-orbitron font-black text-white tracking-tighter uppercase">
                        Register <span class="text-purple-500">Account</span>
                    </h2>
                    <i class="fas fa-user-plus text-2xl text-purple-500/40"></i>
                </div>

                <form method="POST" action="/register" class="space-y-4" enctype="multipart/form-data">
                    @csrf
                    <div class="grid grid-cols-2 gap-2 mb-2">
                        <label class="cursor-pointer">
                            <input type="radio" name="role" value="student" class="hidden peer" checked data-change-action="toggleGradeLevel">
                            <div class="role-card peer-checked:border-cyan-500 peer-checked:bg-cyan-500/10">
                                <i class="fas fa-user-graduate mb-1 text-sm block"></i>
                                <span class="text-[9px] font-bold uppercase">Student</span>
                            </div>
                        </label>
                        <label class="cursor-pointer">
                            <input type="radio" name="role" value="pending_teacher" class="hidden peer" data-change-action="toggleGradeLevel">
                            <div class="role-card peer-checked:border-purple-500 peer-checked:bg-purple-500/10">
                                <i class="fas fa-chalkboard-teacher mb-1 text-sm block"></i>
                                <span class="text-[9px] font-bold uppercase">Teacher</span>
                            </div>
                        </label>
                    </div>

                    <div class="form-group">
                        <label class="input-label">Profile Picture</label>
                        <div class="flex items-center gap-4">
                            {{-- Preview circle --}}
                            <div class="w-16 h-16 rounded-full border-2 border-white/10 bg-white/5 flex items-center justify-center overflow-hidden shrink-0" id="avatar-preview-wrap" data-avatar-preview-wrap>
                                <i class="fas fa-user text-2xl text-slate-600" id="avatar-placeholder" data-avatar-placeholder aria-hidden="true"></i>
                                <img id="avatar-preview" data-avatar-preview hidden alt="Selected profile picture" class="w-full h-full object-cover">
                            </div>
                            <div class="flex-1">
                                <label for="avatar-input"
                                    class="cursor-pointer block w-full text-center border border-white/10 bg-white/5 hover:bg-white/10 transition-all rounded px-4 py-3 text-[10px] font-bold uppercase tracking-widest text-slate-400 hover:text-white">
                                    <i class="fas fa-upload mr-2"></i> Choose Photo
                                </label>
                                <input type="file" id="avatar-input" name="avatar"
                                    accept="image/jpeg,image/png,image/webp" class="hidden">
                                <p class="text-[9px] text-slate-600 mt-1 text-center">JPG, PNG • 2 MB or less</p>
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-2">
                        <div class="form-group">
                            <label class="input-label">First Name</label>
                            <div class="relative">
                                <i class="fas fa-user input-icon"></i>
                                <input type="text" name="first_name" placeholder="First Name" required
                                       class="input-mobile-ultra">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="input-label">Last Name</label>
                            <div class="relative">
                                <i class="fas fa-id-card input-icon"></i>
                                <input type="text" name="last_name" placeholder="Last Name" required
                                       class="input-mobile-ultra">
                            </div>
                        </div>
                    </div>

                    <div class="form-group" id="grade-level-field">
                        <label class="input-label">Grade Level</label>
                        <div class="relative">
                            <i class="fas fa-graduation-cap input-icon"></i>
                            <select name="grade_level" class="input-mobile-ultra bg-slate-900 text-white">
                                @for($g = 1; $g <= 6; $g++)
                                    <option value="{{ $g }}">Grade {{ $g }}</option>
                                @endfor
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="input-label">Email Address</label>
                        <div class="relative">
                            <i class="fas fa-at input-icon"></i>
                            <input type="email" name="email" placeholder="Enter email" required
                                   class="input-mobile-ultra">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="input-label">Password</label>
                        <div class="relative">
                            <i class="fas fa-key input-icon"></i>
                            <input type="password" id="rPass" name="password" minlength="8" maxlength="128"
                                   pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{8,}"
                                   title="Use 8 or more characters with uppercase, lowercase, a number, and a symbol."
                                   autocomplete="new-password" placeholder="Create password" required
                                   class="input-mobile-ultra pr-12">
                            <button type="button" data-action="tglPass" data-action-args='["rPass","rIco"]'
                                    class="absolute right-2 top-1/2 -translate-y-1/2 w-10 h-8 flex items-center justify-center text-slate-500">
                                <i id="rIco" class="fas fa-eye-slash"></i>
                            </button>
                        </div>
                        <p class="text-[9px] text-slate-500 mt-2 leading-4">8+ characters with uppercase, lowercase, a number, and a symbol.</p>
                    </div>

                    <div class="form-group">
                        <label class="input-label">Confirm Password</label>
                        <div class="relative">
                            <i class="fas fa-shield-alt input-icon"></i>
                            <input type="password" id="rcPass" name="password_confirmation" minlength="8" maxlength="128"
                                   pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{8,}"
                                   title="Use 8 or more characters with uppercase, lowercase, a number, and a symbol."
                                   autocomplete="new-password" placeholder="Re-type password" required class="input-mobile-ultra pr-12">
                            <button type="button" data-action="tglPass" data-action-args='["rcPass","rcIco"]'
                                    class="absolute right-2 top-1/2 -translate-y-1/2 w-10 h-8 flex items-center justify-center text-slate-500">
                                <i id="rcIco" class="fas fa-eye-slash"></i>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="btn-mobile-ultra mt-2">Register</button>
                </form>

                <button type="button" data-action="swMod" data-action-args='["login"]'
                        class="mt-6 w-full text-slate-500 text-[9px] font-bold uppercase tracking-widest">
                    <i class="fas fa-arrow-left mr-1"></i> Return to Login
                </button>
            </div>

        </div>
    </div>
</div>

{{-- Forgot Password Modal --}}
<div id="forgotModal" class="fixed inset-0 bg-black/40 z-[3000] flex items-center justify-center p-6 hidden">
    <div class="portal-frame w-full max-w-xs p-8 shadow-[0_0_100px_rgba(0,0,0,1)]">
        <div class="flex justify-between items-center mb-6 border-b border-cyan-500/30 pb-4">
            <h2 class="text-2xl font-orbitron font-black text-white tracking-tighter uppercase">
                Account <span class="text-cyan-400">Recovery</span>
            </h2>
        </div>
        <form method="POST" action="/forgot-password">
            @csrf
            <div class="form-group mb-6">
                <label class="input-label">Registered Email</label>
                <div class="relative">
                    <i class="fas fa-envelope input-icon"></i>
                    <input type="email" name="email" placeholder="Enter your email" class="input-mobile-ultra" required>
                </div>
            </div>
            <button type="submit" class="btn-mobile-ultra !py-4 mb-4">Send Reset Link</button>
        </form>
        <button type="button" data-action="closeForgotModal" class="modal-cancel">
            Cancel
        </button>
    </div>
</div>
@endsection

@push('scripts')
<script nonce="{{ request()->attributes->get('csp_nonce') }}" src="{{ asset('js/auth.js') }}"></script>
@endpush
