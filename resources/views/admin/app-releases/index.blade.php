@extends('layouts.dashboard')

@php
    $activePage = 'app-releases';
@endphp

@section('title', 'Game Releases')
@section('sidebar-border', 'border-red-500/20')
@section('sidebar-subtitle', 'System Administrator')
@section('sidebar-subtitle-color', 'text-red-500/60')
@section('accent-color', 'text-red-500')
@section('mobile-title', 'Game Releases')

@section('sidebar-nav')
    @include('admin.partials.sidebar-nav')
@endsection

@section('dashboard-content')
<section class="max-w-6xl mx-auto">
    <div class="flex flex-col lg:flex-row lg:items-end justify-between gap-5 mb-7 border-b border-white/10 pb-5">
        <div>
            <p class="text-[10px] font-bold uppercase tracking-[0.3em] text-cyan-400">Mobile Distribution</p>
            <h2 class="mt-2 text-2xl md:text-3xl font-orbitron font-black uppercase">
                Game <span class="text-cyan-400">Releases</span>
            </h2>
            <p class="mt-3 max-w-2xl text-sm text-slate-400">
                Upload Android APK or iOS IPA builds. A completed upload becomes current for its platform; you can return to any earlier ready version.
            </p>
        </div>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-[minmax(0,0.8fr)_minmax(0,1.2fr)] gap-6 items-start">
        <form id="app-release-upload-form"
              class="portal-frame !p-6 space-y-5"
              data-initiate-url="{{ route('admin.app-releases.initiate') }}"
              data-complete-url-template="{{ route('admin.app-releases.complete', ['releaseId' => '__RELEASE_ID__']) }}"
              data-fail-url-template="{{ route('admin.app-releases.fail', ['releaseId' => '__RELEASE_ID__']) }}">
            @csrf
            <div>
                <h3 class="font-orbitron font-bold uppercase">Upload a new version</h3>
                <p class="mt-1 text-xs text-slate-500">Large builds upload directly to private Cloudflare R2 storage in retryable parts.</p>
            </div>

            @if(!($uploadsReady ?? false))
                <div class="rounded border border-amber-500/40 bg-amber-500/10 p-4 text-xs leading-relaxed text-amber-200" role="alert">
                    {{ $uploadSetupMessage ?? 'Game uploads are unavailable until Cloudflare R2 is configured.' }}
                </div>
            @endif

            <label class="block">
                <span class="form-label">Version</span>
                <input class="input-field" type="text" name="version" maxlength="50"
                       placeholder="1.2.0" autocomplete="off" required @disabled(!($uploadsReady ?? false))>
            </label>

            <label class="block">
                <span class="form-label">Android APK or iOS IPA</span>
                <input class="input-field file:mr-4 file:rounded file:border-0 file:bg-cyan-400 file:px-4 file:py-2 file:text-xs file:font-black file:text-black"
                       type="file" name="release_file" accept=".apk,.ipa" required @disabled(!($uploadsReady ?? false))>
            </label>

            <label class="block">
                <span class="form-label">Release notes</span>
                <textarea class="input-field min-h-28" name="release_notes" maxlength="5000"
                          placeholder="What changed in this version?" @disabled(!($uploadsReady ?? false))></textarea>
            </label>

            <div id="app-release-upload-progress" class="hidden" aria-live="polite">
                <div class="flex items-center justify-between text-[10px] font-bold uppercase tracking-wider text-slate-400">
                    <span id="app-release-upload-status">Preparing upload…</span>
                    <span id="app-release-upload-percent">0%</span>
                </div>
                <div class="mt-2 h-3 overflow-hidden rounded-full border border-cyan-400/20 bg-black/60">
                    <div id="app-release-upload-bar" class="h-full w-0 bg-gradient-to-r from-cyan-400 via-purple-500 to-pink-500 transition-[width] duration-150"></div>
                </div>
            </div>

            <button type="submit" class="btn-rect-primary disabled:cursor-not-allowed disabled:opacity-40" data-upload-submit @disabled(!($uploadsReady ?? false))>
                <i class="fas fa-cloud-arrow-up mr-2" aria-hidden="true"></i> Upload and publish
            </button>
        </form>

        <div>
            <h3 class="mb-4 font-orbitron font-bold uppercase">Version history</h3>

            @if(!$releasesReady)
                <div class="portal-frame !p-6 border-amber-500/40 text-center">
                    <p class="text-sm text-amber-300">Game releases are temporarily unavailable. Apply the required database migration, then try again.</p>
                </div>
            @elseif(empty($releases))
                <div class="portal-frame !p-6 text-center text-sm text-slate-400">No game versions have been uploaded.</div>
            @else
                <div class="space-y-4">
                    @foreach($releases as $release)
                        @php
                            $status = (string) ($release['status'] ?? 'uploading');
                            $isActive = (bool) ($release['is_active'] ?? false);
                            $deletionPending = !empty($release['deletion_requested_at']);
                            $platform = strtolower((string) ($release['platform'] ?? 'android'));
                            $sizeBytes = (int) ($release['size_bytes'] ?? $release['expected_size_bytes'] ?? 0);
                            $sizeLabel = $sizeBytes > 0 ? number_format($sizeBytes / 1048576, 1) . ' MB' : 'Pending';
                        @endphp
                        <article class="portal-frame !p-5 {{ $isActive ? 'border-green-400/50' : '' }}">
                            <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-4">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <i class="fas {{ $platform === 'ios' ? 'fa-mobile-screen' : 'fa-robot' }} text-cyan-400"
                                           aria-label="{{ $platform === 'ios' ? 'iOS build' : 'Android build' }}"></i>
                                        <h4 class="font-orbitron font-black">Version {{ $release['version'] ?? '—' }}</h4>
                                        @if($isActive)
                                            <span class="rounded-full border border-green-400/40 bg-green-400/10 px-2.5 py-1 text-[8px] font-black uppercase text-green-300">Current</span>
                                        @elseif($deletionPending)
                                            <span class="rounded-full border border-red-400/40 bg-red-400/10 px-2.5 py-1 text-[8px] font-black uppercase text-red-300">Deletion pending</span>
                                        @else
                                            <span class="rounded-full border border-white/10 bg-white/5 px-2.5 py-1 text-[8px] font-black uppercase text-slate-400">{{ $status }}</span>
                                        @endif
                                    </div>
                                    <p class="mt-2 text-[10px] font-bold uppercase tracking-wider text-slate-500">
                                        {{ $platform === 'ios' ? 'iOS · IPA' : 'Android · APK' }} &nbsp;•&nbsp; {{ $sizeLabel }}
                                    </p>
                                    @if(!empty($release['release_notes']))
                                        <p class="mt-3 whitespace-pre-line text-sm leading-5 text-slate-300">{{ $release['release_notes'] }}</p>
                                    @endif
                                    @if($status === 'failed' && !empty($release['failure_reason']))
                                        <p class="mt-3 rounded border border-red-500/20 bg-red-500/10 p-3 text-xs leading-5 text-red-200">
                                            {{ $release['failure_reason'] }}
                                        </p>
                                    @endif
                                    @if($deletionPending)
                                        <p class="mt-3 rounded border border-amber-500/20 bg-amber-500/10 p-3 text-xs leading-5 text-amber-200">
                                            The version is locked while its private file is being removed. Retry deletion to finish safely.
                                        </p>
                                    @endif
                                </div>

                                <div class="flex max-w-full flex-wrap gap-2 shrink-0 sm:justify-end">
                                @if(!$deletionPending && $status === 'ready')
                                        <a href="{{ route('admin.app-releases.download', ['releaseId' => $release['id']]) }}"
                                           download="{{ $release['download_name'] ?? '' }}" data-native-navigation
                                           class="btn-rect-secondary !w-auto !py-2 px-4 text-[10px]">
                                            <i class="fas fa-download mr-1" aria-hidden="true"></i> Download
                                        </a>
                                        @unless($isActive)
                                            <form method="POST" action="{{ route('admin.app-releases.activate', ['releaseId' => $release['id']]) }}">
                                                @csrf
                                                <button class="btn-rect-primary !w-auto !py-2 px-4 text-[10px]" type="submit">
                                                    Make current
                                                </button>
                                            </form>
                                        @endunless
                                @elseif(!$deletionPending && $status === 'uploading')
                                        <form method="POST" action="{{ route('admin.app-releases.complete', ['releaseId' => $release['id']]) }}">
                                            @csrf
                                            <button class="btn-rect-primary !w-auto !py-2 px-4 text-[10px]" type="submit">
                                                Finish publishing
                                            </button>
                                        </form>
                                        <form method="POST" action="{{ route('admin.app-releases.fail', ['releaseId' => $release['id']]) }}">
                                            @csrf
                                            <input type="hidden" name="reason" value="Discarded by an administrator after an interrupted upload.">
                                            <button class="btn-rect-secondary !w-auto !py-2 px-4 text-[10px]" type="submit">
                                                Discard upload
                                            </button>
                                        </form>
                                @elseif(!$deletionPending && $status === 'failed'
                                    && ($release['storage_provider'] ?? 'supabase') === 'r2'
                                    && !empty($release['provider_upload_id']))
                                    <form method="POST" action="{{ route('admin.app-releases.fail', ['releaseId' => $release['id']]) }}" class="shrink-0">
                                        @csrf
                                        <input type="hidden" name="reason" value="Storage cleanup retried by an administrator.">
                                        <button class="btn-rect-secondary !w-auto !py-2 px-4 text-[10px]" type="submit">
                                            Retry storage cleanup
                                        </button>
                                    </form>
                                @endif

                                @if($isActive)
                                    <button type="button" disabled
                                            title="Make another version current before deleting this release."
                                            class="btn-rect-secondary !w-auto !py-2 px-4 text-[10px] !border-red-500/20 text-red-300 opacity-40 disabled:cursor-not-allowed">
                                        <i class="fas fa-trash-can mr-1" aria-hidden="true"></i> Delete
                                    </button>
                                @else
                                    <button type="button"
                                            data-action="confirmReleaseDelete"
                                            data-action-args="{{ json_encode([$release['id'], $release['version'] ?? ''], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) }}"
                                            class="btn-rect-secondary !w-auto !py-2 px-4 text-[10px] !border-red-500/40 text-red-300 hover:!bg-red-500/15">
                                        <i class="fas fa-trash-can mr-1" aria-hidden="true"></i>
                                        {{ $deletionPending ? 'Retry delete' : 'Delete' }}
                                    </button>
                                @endif
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</section>

<div id="releaseDeleteModal" class="modal-overlay hidden" role="dialog" aria-modal="true" aria-labelledby="release-delete-title">
    <div class="portal-frame !p-8 w-full max-w-md text-center border-red-500/30">
        <i class="fas fa-trash-can text-4xl text-red-400 mb-4" aria-hidden="true"></i>
        <h3 id="release-delete-title" class="font-orbitron font-bold uppercase">Delete game version?</h3>
        <p id="release-delete-version" class="mt-3 text-sm text-slate-300"></p>
        <p class="mt-2 text-xs leading-5 text-slate-500">
            Its private APK or IPA file and version record will be permanently removed. The current version is always protected.
        </p>
        <form id="releaseDeleteForm" method="POST" class="mt-7">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn-rect-primary !bg-red-600 !text-white">
                Delete version
            </button>
        </form>
        <button type="button" data-action="closeModal" data-action-args='["releaseDeleteModal"]' class="modal-cancel">Cancel</button>
    </div>
</div>
@endsection

@section('modals')
@include('admin.partials.logout-modal')
@endsection
