@php
    $avatarData = \App\Support\UserAvatar::presentation($avatarUser ?? []);
    $avatarDimension = in_array($avatarSize ?? 40, [32, 36, 40, 44, 48, 64], true) ? ($avatarSize ?? 40) : 40;
    $isAvatarPreview = $avatarPreview ?? false;
    $isRegistrationAvatar = $avatarRegistration ?? false;
@endphp
<span class="user-avatar user-avatar--{{ $avatarDimension }} user-avatar--tone-{{ $avatarData['color'] }} {{ $avatarClass ?? '' }}"
      data-user-avatar data-avatar-name="{{ $avatarData['name'] }}"
      @if($isAvatarPreview) data-avatar-preview-wrap id="avatar-preview-wrap" @endif
      @if($isRegistrationAvatar) data-avatar-registration @endif
      @if($avatarDecorative ?? false) aria-hidden="true" @else role="img" aria-label="{{ $avatarData['name'] }} profile" @endif>
    <span data-avatar-initials @if($isAvatarPreview) data-avatar-placeholder id="avatar-placeholder" @endif
          aria-hidden="true" @if($avatarData['url']) hidden @endif>@if($avatarData['anonymous'])<i class="fas fa-user" data-avatar-anonymous></i>@else{{ $avatarData['initials'] }}@endif</span>
    @if($avatarData['url'] || $isAvatarPreview)
        <img @if($avatarData['url']) src="{{ $avatarData['url'] }}" @endif
             @if($isAvatarPreview) id="avatar-preview" data-avatar-preview @elseif($avatarCurrent ?? false) data-current-user-avatar @endif
             @unless($avatarData['url']) hidden @endunless
             alt="" aria-hidden="true" width="{{ $avatarDimension }}" height="{{ $avatarDimension }}"
             loading="lazy" decoding="async">
    @endif
</span>
