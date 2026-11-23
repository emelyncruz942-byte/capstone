@extends('layouts.app')

@section('title', 'Interactive Mathematics Learning Platform')
@section('description', 'MathVerse is an online math learning platform for students and teachers in the Philippines with VR quiz bees, classroom quizzes, games, and progress tracking.')
@section('body-class', 'auth-page')

@section('content')
<header class="public-brand-bar">
    <a href="{{ route('login') }}" class="public-brand" aria-label="MathVerse home">
        <img src="{{ asset('logo-180.png') }}" width="40" height="40" alt="" aria-hidden="true">
        <span>Math<span class="public-brand-accent">Verse</span></span>
    </a>
    <a href="{{ route('vr-math-quiz-bee') }}" class="public-header-link">Explore MathVerse <i class="fas fa-compass" aria-hidden="true"></i></a>
</header>
<main class="auth-shell" id="main-content">
    <section class="auth-introduction" aria-labelledby="welcome-title">
        <p class="public-eyebrow"><span class="public-eyebrow-icon" aria-hidden="true"><i class="fas fa-star"></i></span> A new way to learn mathematics</p>
        <h1 id="welcome-title">Small discoveries.<br><span>A world of possibilities.</span></h1>
        <p class="auth-introduction-copy">Make room for curiosity. Practice your skills, take on classroom challenges, and explore the world of MathVerse.</p>
        <div class="learning-preview" aria-hidden="true">
            <div class="learning-preview-heading"><span class="learning-preview-icon"><i class="fas fa-shapes"></i></span><div><strong>Your next discovery</strong><span>Learn at your own pace</span></div><span class="learning-preview-spark">✦</span></div>
            <div class="learning-preview-equation"><span>12</span><b>+</b><span>8</span><b>=</b><span class="learning-preview-answer">20</span></div>
            <div class="learning-preview-footer"><span><i class="fas fa-circle-check"></i> Keep exploring</span><span>One step at a time</span></div>
        </div>
        <ul class="auth-feature-list">
            <li><span class="auth-feature-icon"><i class="fas fa-vr-cardboard" aria-hidden="true"></i></span><div><strong>Play together</strong><span>Join your class in VR or screen mode.</span></div></li>
            <li><span class="auth-feature-icon"><i class="fas fa-lightbulb" aria-hidden="true"></i></span><div><strong>Build confidence</strong><span>Practice, discover, and track your progress.</span></div></li>
        </ul>
    </section>
    <section class="auth-panel" aria-label="Account access">
    <div class="portal-frame auth-card relative">

        <div class="p-6">

            {{-- LOGIN PANEL --}}
            <div id="loginMod" class="module module-active">
                <div class="auth-panel-heading">
                    <span class="auth-panel-icon" aria-hidden="true"><i class="fas fa-door-open"></i></span>
                    <h2>Welcome back</h2>
                    <p>Sign in to continue your learning journey.</p>
                </div>

                <div class="auth-role-note">
                    <p>
                        <i class="fas fa-user-graduate" aria-hidden="true"></i> Students
                        <span aria-hidden="true">·</span>
                        <i class="fas fa-chalkboard-teacher" aria-hidden="true"></i> Teachers
                    </p>
                </div>

                {{-- Laravel form: POST to /login --}}
                <form id="loginForm" method="POST" action="/login" class="space-y-5"
                      autocomplete="on" data-login-form>
                    @csrf
                    <div class="form-group">
                        <label for="lEmail" class="input-label">Email Address</label>
                        <div class="relative">
                            <i class="fas fa-envelope input-icon"></i>
                            <input type="email" id="lEmail" name="email" required
                                   value="{{ old('email') }}"
                                   class="input-mobile-ultra" placeholder="Enter email"
                                   autocomplete="username" autocapitalize="none" spellcheck="false" inputmode="email"
                                   data-login-credential="username">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="lPass" class="input-label">Password</label>
                        <div class="relative">
                            <i class="fas fa-lock input-icon"></i>
                            <input type="password" id="lPass" name="password" required
                                   class="input-mobile-ultra pr-12" placeholder="Enter password"
                                   autocomplete="current-password" data-login-credential="password">
                            <button type="button" data-action="tglPass" data-action-args='["lPass","lIcon"]'
                                    aria-label="Show or hide password"
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

                <div class="auth-register-link mt-8 text-center border-t border-white/5 pt-6">
                    <p>New to MathVerse?</p>
                    <button type="button" data-action="swMod" data-action-args='["reg"]'
                            class="text-slate-300 text-[10px] font-black uppercase tracking-[0.2em] border border-white/20 px-6 py-2 hover:bg-white/5 transition-all">
                        Create an account
                    </button>
                </div>
            </div>

            {{-- REGISTER PANEL --}}
            <div id="regMod" class="module">
                <div class="auth-panel-heading">
                    <span class="auth-panel-icon" aria-hidden="true"><i class="fas fa-user-plus"></i></span>
                    <h2>Start your adventure</h2>
                    <p>Create your account and discover what comes next.</p>
                </div>

                <form method="POST" action="/register" class="space-y-4" enctype="multipart/form-data">
                    @csrf
                    <div class="grid grid-cols-2 gap-2 mb-2">
                        <label class="cursor-pointer">
                            <input type="radio" name="role" value="student" class="auth-role-input peer" checked data-change-action="toggleGradeLevel">
                            <div class="role-card peer-checked:border-cyan-500 peer-checked:bg-cyan-500/10">
                                <i class="fas fa-user-graduate mb-1 text-sm block"></i>
                                <span class="text-[9px] font-bold uppercase">Student</span>
                            </div>
                        </label>
                        <label class="cursor-pointer">
                            <input type="radio" name="role" value="pending_teacher" class="auth-role-input peer" data-change-action="toggleGradeLevel">
                            <div class="role-card peer-checked:border-purple-500 peer-checked:bg-purple-500/10">
                                <i class="fas fa-chalkboard-teacher mb-1 text-sm block"></i>
                                <span class="text-[9px] font-bold uppercase">Teacher</span>
                            </div>
                        </label>
                    </div>

                    <div class="form-group">
                        <label class="input-label">Profile Picture</label>
                        <div class="flex items-center gap-4">
                            @include('partials.user-avatar', ['avatarUser' => ['first_name' => old('first_name', ''), 'last_name' => old('last_name', '')], 'avatarSize' => 64, 'avatarPreview' => true, 'avatarRegistration' => true])
                            <div class="flex-1">
                                <label for="avatar-input"
                                    class="cursor-pointer block w-full text-center border border-white/10 bg-white/5 hover:bg-white/10 transition-all rounded px-4 py-3 text-[10px] font-bold uppercase tracking-widest text-slate-400 hover:text-white">
                                    <i class="fas fa-upload mr-2"></i> Choose Photo
                                </label>
                                <input type="file" id="avatar-input" name="avatar"
                                    accept="image/jpeg,image/png,image/webp" class="hidden">
                                <p class="text-[9px] text-slate-600 mt-1 text-center">JPG, PNG, WebP • 2 MB or less</p>
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-2">
                        <div class="form-group">
                            <label for="rFirstName" class="input-label">First Name</label>
                            <div class="relative">
                                <i class="fas fa-user input-icon"></i>
                                <input type="text" id="rFirstName" name="first_name" placeholder="First Name" required autocomplete="given-name"
                                       class="input-mobile-ultra">
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="rLastName" class="input-label">Last Name</label>
                            <div class="relative">
                                <i class="fas fa-id-card input-icon"></i>
                                <input type="text" id="rLastName" name="last_name" placeholder="Last Name" required autocomplete="family-name"
                                       class="input-mobile-ultra">
                            </div>
                        </div>
                    </div>

                    <div class="form-group" id="grade-level-field">
                        <label for="rGrade" class="input-label">Grade Level</label>
                        <div class="relative">
                            <i class="fas fa-graduation-cap input-icon"></i>
                            <select id="rGrade" name="grade_level" class="input-mobile-ultra bg-slate-900 text-white">
                                @for($g = 1; $g <= 6; $g++)
                                    <option value="{{ $g }}">Grade {{ $g }}</option>
                                @endfor
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="rEmail" class="input-label">Email Address</label>
                        <div class="relative">
                            <i class="fas fa-at input-icon"></i>
                            <input type="email" id="rEmail" name="email" placeholder="Enter email" required autocomplete="email" autocapitalize="none" spellcheck="false" inputmode="email"
                                   class="input-mobile-ultra">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="rPass" class="input-label">Password</label>
                        <div class="relative">
                            <i class="fas fa-key input-icon"></i>
                            <input type="password" id="rPass" name="password" minlength="8" maxlength="128"
                                   pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{8,}"
                                   title="Use 8 or more characters with uppercase, lowercase, a number, and a symbol."
                                   autocomplete="new-password" placeholder="Create password" required
                                   class="input-mobile-ultra pr-12">
                            <button type="button" data-action="tglPass" data-action-args='["rPass","rIco"]'
                                    aria-label="Show or hide password"
                                    class="absolute right-2 top-1/2 -translate-y-1/2 w-10 h-8 flex items-center justify-center text-slate-500">
                                <i id="rIco" class="fas fa-eye-slash"></i>
                            </button>
                        </div>
                        <p class="text-[9px] text-slate-500 mt-2 leading-4">8+ characters with uppercase, lowercase, a number, and a symbol.</p>
                    </div>

                    <div class="form-group">
                        <label for="rcPass" class="input-label">Confirm Password</label>
                        <div class="relative">
                            <i class="fas fa-shield-alt input-icon"></i>
                            <input type="password" id="rcPass" name="password_confirmation" minlength="8" maxlength="128"
                                   pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{8,}"
                                   title="Use 8 or more characters with uppercase, lowercase, a number, and a symbol."
                                   autocomplete="new-password" placeholder="Re-type password" required class="input-mobile-ultra pr-12">
                            <button type="button" data-action="tglPass" data-action-args='["rcPass","rcIco"]'
                                    aria-label="Show or hide password confirmation"
                                    class="absolute right-2 top-1/2 -translate-y-1/2 w-10 h-8 flex items-center justify-center text-slate-500">
                                <i id="rcIco" class="fas fa-eye-slash"></i>
                            </button>
                        </div>
                    </div>

                    <p class="text-[10px] leading-5 text-slate-500">
                        By creating an account, you acknowledge the
                        <a href="{{ route('terms-and-conditions') }}" class="text-cyan-400 underline underline-offset-2 hover:text-white">Terms and Conditions</a>
                        and have read the
                        <a href="{{ route('privacy-policy') }}" class="text-cyan-400 underline underline-offset-2 hover:text-white">Privacy Policy</a>.
                    </p>

                    <button type="submit" class="btn-mobile-ultra mt-2">Create account</button>
                </form>

                <button type="button" data-action="swMod" data-action-args='["login"]'
                        class="mt-6 w-full text-slate-500 text-[9px] font-bold uppercase tracking-widest">
                    Already have an account? Sign in
                </button>
            </div>

        </div>
    </div>

    </section>
</main>
@include('partials.public-footer')

{{-- Forgot Password Modal --}}
<div id="forgotModal" class="auth-recovery-modal fixed inset-0 bg-black/40 z-[3000] flex items-center justify-center p-6 hidden" role="dialog" aria-modal="true" aria-labelledby="recovery-title">
    <div class="portal-frame w-full max-w-xs p-8">
        <div class="auth-panel-heading">
            <span class="auth-panel-icon" aria-hidden="true"><i class="fas fa-key"></i></span>
            <h2 id="recovery-title">Forgot your password?</h2>
            <p>Enter your email and we'll send a reset link.</p>
        </div>
        <form method="POST" action="/forgot-password">
            @csrf
            <div class="form-group mb-6">
                <label for="recoveryEmail" class="input-label">Registered Email</label>
                <div class="relative">
                    <i class="fas fa-envelope input-icon"></i>
                    <input type="email" id="recoveryEmail" name="email" placeholder="Enter your email" class="input-mobile-ultra" required autocomplete="email" autocapitalize="none" spellcheck="false" inputmode="email">
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
<script defer nonce="{{ request()->attributes->get('csp_nonce') }}" src="{{ asset('js/auth.js') }}?v={{ filemtime(public_path('js/auth.js')) }}"></script>
@endpush
