<div id="logoutModal" class="modal-overlay hidden">
    <div class="portal-frame !p-10 w-full max-w-xs text-center shadow-[0_0_100px_rgba(0,0,0,1)]">
        <i class="fas fa-sign-out-alt text-4xl text-cyan-400 mb-6"></i>
        <h3 class="font-orbitron font-bold text-white mb-2 uppercase">Are you sure you want to log out?</h3>
        <p class="text-xs text-slate-500 uppercase mb-8">You will need to log in again to access your account.</p>
        <div class="space-y-3">
            <form id="logoutForm" method="POST" action="/logout" class="mt-10">
                @csrf
                <button type="submit" class="btn-rect-primary !py-3">Confirm Logout</button>
            </form>
            <button type="button" data-action="closeModal" data-action-args='["logoutModal"]' class="modal-cancel">Cancel</button>
        </div>
    </div>
</div>
