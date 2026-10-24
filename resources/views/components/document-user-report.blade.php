@props([
    'report' => null,
])

@if (is_array($report) && ! empty($report))
    <section class="card" id="user-processing-report" style="margin-bottom:1.25rem">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;margin-bottom:.8rem">
            <div style="display:flex;align-items:center;gap:.5rem">
                <i data-lucide="clipboard-list" style="width:19px;height:19px;color:var(--color-primary)"></i>
                <h2 class="card-title">Rapport de traitement</h2>
            </div>

            <div style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:center">
                @if (! empty($report['original_url']))
                    <a href="{{ $report['original_url'] }}" target="_blank" rel="noopener" class="btn btn-ghost btn-sm">
                        <i data-lucide="file-symlink" style="width:15px;height:15px"></i>
                        Document original
                    </a>
                @endif
                @if (! empty($report['report_download_url']))
                    <a href="{{ $report['report_download_url'] }}" class="btn btn-secondary btn-sm">
                        <i data-lucide="file-down" style="width:15px;height:15px"></i>
                        Télécharger le rapport (JSON)
                    </a>
                @endif
            </div>
        </div>

        <p style="color:var(--color-text-secondary);font-size:.85rem;margin-bottom:1rem">
            Récapitulatif transparent des transformations appliquées à votre document et des points signalés pour vérification.
        </p>

        {{-- Corrections appliquées --}}
        <div style="margin-bottom:1.1rem">
            <p style="font-family:var(--font-mono);font-size:.7rem;text-transform:uppercase;letter-spacing:.05em;color:var(--color-text-muted);margin-bottom:.55rem">
                Corrections appliquées
            </p>
            <ul style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:.55rem;list-style:none;margin:0;padding:0">
                @foreach ($report['corrections'] ?? [] as $correction)
                    <li style="display:flex;align-items:center;justify-content:space-between;gap:.6rem;padding:.55rem .75rem;border:1px solid var(--color-border);border-radius:var(--radius-sm);background:var(--color-surface-2);font-size:.84rem">
                        <span style="color:var(--color-text-secondary)">{{ $correction['label'] }}</span>
                        <strong class="mono" style="font-size:.85rem;color:{{ ($correction['count'] ?? 0) > 0 ? 'var(--color-primary)' : 'var(--color-text-muted)' }}">
                            {{ $correction['count'] ?? 0 }}
                        </strong>
                    </li>
                @endforeach
            </ul>
        </div>

        {{-- Avertissements --}}
        <div>
            <p style="font-family:var(--font-mono);font-size:.7rem;text-transform:uppercase;letter-spacing:.05em;color:var(--color-text-muted);margin-bottom:.55rem">
                Avertissements et points d'attention
            </p>

            @if (! empty($report['warnings']))
                <div style="display:flex;flex-direction:column;gap:.6rem">
                    @foreach ($report['warnings'] as $warning)
                        <div style="display:flex;align-items:flex-start;gap:.65rem;padding:.75rem .9rem;border-radius:var(--radius-sm);border:1px solid var(--color-border);background:var(--color-surface-2)">
                            <i data-lucide="{{ ($warning['level'] ?? 'info') === 'warning' ? 'alert-triangle' : 'info' }}"
                               style="width:16px;height:16px;flex-shrink:0;margin-top:.15rem;color:{{ ($warning['level'] ?? 'info') === 'warning' ? '#b45309' : 'var(--color-primary)' }}"></i>
                            <div style="min-width:0;flex:1">
                                <p style="font-weight:600;font-size:.86rem;margin:0 0 .2rem 0">{{ $warning['title'] }}</p>
                                <p style="font-size:.82rem;color:var(--color-text-secondary);margin:0">{{ $warning['message'] }}</p>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <p style="font-size:.85rem;color:var(--color-text-secondary);margin:0">
                    Aucun avertissement particulier : toutes les structures détectées sont conformes.
                </p>
            @endif
        </div>
    </section>
@endif

