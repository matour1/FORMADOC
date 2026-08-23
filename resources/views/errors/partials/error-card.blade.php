@php
    // Partial : carte d'erreur conforme formadoc-template.html
    // Paramètres : code, title, message, icon (info|warn|danger|success), iconSvg,
    // detail (optionnel, auto depuis la requête), help (liste), actions (liste de boutons).
    $iconType = $icon ?? 'info';
    $iconColor = match ($iconType) {
        'danger' => 'var(--color-correction)',
        'warn' => 'var(--color-warning)',
        'success' => 'var(--color-secondary)',
        default => 'var(--color-primary)',
    };

    // Détail : préfère la valeur passée, sinon construit depuis la requête réelle.
    $detail = $detail ?? null;
    if (!$detail) {
        $method = request()?->method() ?? 'GET';
        $path = request()?->path() ?? '/';
        $ref = 'REF-' . ($code ?? '000') . '-' . strtoupper(substr(md5($path), 0, 6));
        $detail = $method . ' /' . $path . ' · ' . $ref;
    }
@endphp

<div class="error-page" data-code="{{ $code }}">
    <div class="err-icon {{ $iconType }}">
        <i data-lucide="{{ $iconSvg ?? 'alert-circle' }}" style="width:34px;height:34px"></i>
    </div>
    <div class="code" style="color:{{ $iconColor }}">{{ $code }}</div>
    <h2 style="font-size:1.2rem;font-weight:700;">{{ $title }}</h2>
    <p class="message">{!! $message !!}</p>

    @if (!empty($actions))
        <div class="error-actions">
            @foreach ($actions as $action)
                @if (!empty($action['url']))
                    <a href="{{ $action['url'] }}" class="btn {{ $action['primary'] ?? false ? 'btn-primary' : 'btn-secondary' }}"
                       @if (!empty($action['id'])) id="{{ $action['id'] }}" @endif>
                        {{ $action['label'] }}
                    </a>
                @else
                    <button type="button" class="btn {{ $action['primary'] ?? false ? 'btn-primary' : 'btn-secondary' }}"
                            @if (!empty($action['id'])) id="{{ $action['id'] }}" @endif
                            onclick="{{ $action['onclick'] ?? '' }}">{{ $action['label'] }}</button>
                @endif
            @endforeach
        </div>
    @endif

    @if (!empty($detail))
        <p class="error-detail">{{ $detail }}</p>
    @endif

    <div class="error-help">
        <div class="error-help-title">
            <i data-lucide="help-circle" style="width:14px;height:14px"></i> Suggestions pour résoudre le problème
        </div>
        <ul>
            @foreach ($help ?? [] as $hint)
                <li>{{ $hint }}</li>
            @endforeach
        </ul>
    </div>
</div>
