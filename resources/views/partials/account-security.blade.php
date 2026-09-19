@php
    $securityAccent = $securityAccent ?? 'text-cyan-400';
    $securityBorder = $securityBorder ?? 'border-cyan-500/20';
    $securityButton = $securityButton ?? '';
@endphp

<section id="sec-security" class="content-section hidden">
    <div class="portal-frame !p-6 md:!p-10 {{ $securityBorder }}">
        <div class="mb-8">
            <h2 class="text-xl font-orbitron font-bold uppercase">
                <i class="fas fa-shield-halved mr-2"></i> Account <span class="{{ $securityAccent }}">Security</span>
            </h2>
            <p class="text-xs text-slate-500 mt-3">Manage the email address and password used to sign in to MathVerse.</p>
        </div>

        <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
            <form method="POST" action="/change-email" class="space-y-5 rounded-lg border border-white/10 bg-white/[0.025] p-5 md:p-6" autocomplete="off">
                @csrf
                <div>
                    <h3 class="font-orbitron text-sm font-bold uppercase"><i class="fas fa-envelope mr-2 {{ $securityAccent }}"></i> Change Email Address</h3>
                </div>
                <div class="form-group">
                    <label class="input-label">Current Email</label>
                    <div class="relative">
                        <i class="fas fa-at input-icon"></i>
                        <input type="email" value="{{ $user['email'] ?? '' }}" readonly class="input-mobile-ultra !bg-white/5 text-slate-500 cursor-not-allowed">
                    </div>
                </div>
                <div class="form-group">
                    <label for="security-new-email" class="input-label">New Email</label>
                    <div class="relative">
                        <i class="fas fa-envelope-open input-icon"></i>
                        <input type="email" id="security-new-email" name="new_email" value="{{ old('new_email') }}" autocomplete="off" placeholder="name@example.com" class="input-mobile-ultra" required>
                    </div>
                </div>
                <div class="form-group">
                    <label for="security-confirm-email" class="input-label">Confirm New Email</label>
                    <div class="relative">
                        <i class="fas fa-envelope-circle-check input-icon"></i>
                        <input type="email" id="security-confirm-email" name="new_email_confirmation" autocomplete="off" placeholder="Repeat the new email" class="input-mobile-ultra" required>
                    </div>
                </div>
                <div class="form-group">
                    <label for="security-email-password" class="input-label text-orange-400">Current Password</label>
                    <div class="relative">
                        <i class="fas fa-lock input-icon"></i>
                        <input type="password" id="security-email-password" name="current_password" autocomplete="off" placeholder="Confirm your identity" class="input-mobile-ultra pr-12" required>
                        <button type="button" data-action="tglPass" data-action-args='["security-email-password","security-email-password-icon"]' class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-500" aria-label="Show or hide current password">
                            <i id="security-email-password-icon" class="fas fa-eye-slash"></i>
                        </button>
                    </div>
                </div>
                <button type="submit" class="btn-rect-primary {{ $securityButton }}"><i class="fas fa-paper-plane mr-2"></i> Send Confirmation</button>
            </form>

            <form method="POST" action="/change-password" class="space-y-5 rounded-lg border border-white/10 bg-white/[0.025] p-5 md:p-6" autocomplete="off">
                @csrf
                <div>
                    <h3 class="font-orbitron text-sm font-bold uppercase"><i class="fas fa-key mr-2 {{ $securityAccent }}"></i> Change Password</h3>
                    <p class="text-[10px] text-slate-500 mt-2 leading-4">Use at least 8 characters with an uppercase letter, lowercase letter, number, and symbol.</p>
                </div>
                <div class="form-group">
                    <label for="security-current-password" class="input-label text-orange-400">Current Password</label>
                    <div class="relative">
                        <i class="fas fa-unlock-alt input-icon"></i>
                        <input type="password" id="security-current-password" name="current_password" autocomplete="off" placeholder="Enter current password" class="input-mobile-ultra pr-12" required>
                        <button type="button" data-action="tglPass" data-action-args='["security-current-password","security-current-password-icon"]' class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-500" aria-label="Show or hide current password">
                            <i id="security-current-password-icon" class="fas fa-eye-slash"></i>
                        </button>
                    </div>
                </div>
                <div class="form-group">
                    <label for="security-new-password" class="input-label">New Password</label>
                    <div class="relative">
                        <i class="fas fa-key input-icon"></i>
                        <input type="password" id="security-new-password" name="new_password" autocomplete="off" minlength="8" maxlength="128" pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{8,}" title="Use 8 or more characters with uppercase, lowercase, a number, and a symbol." autocomplete="new-password" placeholder="Create a new password" class="input-mobile-ultra pr-12" required>
                        <button type="button" data-action="tglPass" data-action-args='["security-new-password","security-new-password-icon"]' class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-500" aria-label="Show or hide new password">
                            <i id="security-new-password-icon" class="fas fa-eye-slash"></i>
                        </button>
                    </div>
                </div>
                <div class="form-group">
                    <label for="security-confirm-password" class="input-label">Confirm New Password</label>
                    <div class="relative">
                        <i class="fas fa-shield-halved input-icon"></i>
                        <input type="password" id="security-confirm-password" name="new_password_confirmation" autocomplete="off" minlength="8" maxlength="128" pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{8,}" title="Use 8 or more characters with uppercase, lowercase, a number, and a symbol." autocomplete="new-password" placeholder="Repeat the new password" class="input-mobile-ultra pr-12" required>
                        <button type="button" data-action="tglPass" data-action-args='["security-confirm-password","security-confirm-password-icon"]' class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-500" aria-label="Show or hide password confirmation">
                            <i id="security-confirm-password-icon" class="fas fa-eye-slash"></i>
                        </button>
                    </div>
                </div>
                <button type="submit" class="btn-rect-primary {{ $securityButton }}"><i class="fas fa-save mr-2"></i> Update Password</button>
            </form>

            @if(($user['role'] ?? '') !== 'admin')
            <div class="space-y-5 rounded-lg border border-white/10 bg-white/[0.025] p-5 md:p-6 xl:col-span-2 flex flex-col md:flex-row md:items-center md:justify-between gap-5">
                <div>
                    <h3 class="font-orbitron text-sm font-bold uppercase"><i class="fas fa-bell mr-2 {{ $securityAccent }}"></i> Browser Alerts</h3>
                    <p data-push-status class="text-[10px] text-slate-500 mt-2 leading-4">Checking whether this device supports Web Push…</p>
                    <p class="text-[10px] text-slate-600 mt-1 leading-4">Receive operating-system alerts for MathVerse events that are not sent by email.</p>
                </div>
                <button type="button" data-push-toggle
                        data-subscription-url="/push-subscription"
                        data-vapid-key="{{ config('services.web_push.public_key') }}"
                        class="browser-alert-button btn-rect-secondary !py-3 !px-5 !w-auto shrink-0">
                    Enable Browser Alerts
                </button>
            </div>
            @endif
        </div>
    </div>
</section>
