@extends('layouts.admin')

@section('title', 'Configuration')

@section('content')
    <div class="page-head">
        <span class="eyebrow">Exploitation</span>
        <h1>Configuration</h1>
        <p>
            Valeurs qui pilotent la facturation et les encaissements. Modifier un réglage
            change le comportement dès la requête suivante, sans redéploiement.
        </p>
    </div>

    @if (session('success'))
        <div class="banner banner-success">
            <i data-lucide="check-circle"></i>
            <div>{{ session('success') }}</div>
        </div>
    @endif

    {{--
        Le coefficient de rentabilité n'est pas saisissable : il RÉSULTE de la marge
        et du coût d'infrastructure. L'afficher à part, avec sa formule, évite de le
        recalculer de tête au moment de décider d'une nouvelle marge — et évite
        surtout de le croire modifiable, ce qui ferait chercher un champ inexistant.
    --}}
    <section class="card" style="margin-bottom:1.5rem">
        <div style="display:flex;align-items:baseline;gap:1rem;flex-wrap:wrap">
            <div>
                <span class="stat-label">Coefficient de rentabilité appliqué</span>
                <span class="stat-value tabular">{{ number_format($coefficient, 2, ',', ' ') }}×</span>
            </div>
            <p style="margin:0;color:var(--color-text-secondary);font-size:.88rem;max-width:52ch">
                Chaque appel IA est facturé à son coût réel multiplié par ce coefficient.
                Il vaut <code>(1 + coût d'infrastructure) × (1 + marge)</code> : il n'est pas
                saisissable, il découle des deux réglages ci-dessous.
            </p>
        </div>
    </section>

    <form method="POST" action="{{ route('admin.settings.update') }}">
        @csrf
        @method('PUT')

        @foreach ($groupes as $slug => $titre)
            @php $definitions = \App\Services\Settings\SettingsCatalog::group($slug); @endphp

            @if ($definitions !== [])
                <section class="card" style="margin-bottom:1.5rem">
                    <h2 class="card-title" style="margin-bottom:.3rem">{{ $titre }}</h2>
                    <p style="font-size:.85rem;color:var(--color-text-secondary);margin:0 0 1.25rem">
                        @if ($slug === 'billing')
                            Ces valeurs décident du prix payé par l'utilisateur. Un écart avec le
                            coût réel se répercute directement sur la marge.
                        @elseif ($slug === 'payments')
                            Elles pilotent l'encaissement : montant accepté, devise, durée de vie
                            des liens transmis aux clients.
                        @else
                            Comportement général de la plateforme.
                        @endif
                    </p>

                    @if ($slug === 'payments')
                        {{-- Diagnostic des moyens de paiement.

                             **Pourquoi ce panneau, alors que les interrupteurs sont
                             juste en dessous.** Trois causes distinctes empêchent un
                             moyen d'apparaître : clé d'API absente, moyen désactivé,
                             moyen masqué. Les interrupteurs ne montrent que les deux
                             dernières — un moyen activé et visible mais sans clé ne
                             s'afficherait nulle part, et l'exploitant conclurait à un
                             défaut du code. Ce tableau nomme la cause exacte.

                             Il est calculé par `PaymentGatewayRegistry`, donc il
                             reflète ce que voit réellement l'interface d'achat. --}}
                        @php $moyens = app(\App\Services\Billing\PaymentGatewayRegistry::class)->etats(); @endphp

                        <div style="border:1px solid var(--color-border);border-radius:8px;padding:1rem;margin-bottom:1.5rem">
                            <h3 style="font-size:.9rem;font-weight:600;margin:0 0 .25rem">
                                État réel des moyens de paiement
                            </h3>
                            <p style="font-size:.8rem;color:var(--color-text-muted);margin:0 0 .9rem;max-width:70ch">
                                Ce que voit l'utilisateur au moment de payer. Un moyen doit être
                                <strong>configuré</strong>, <strong>activé</strong> et
                                <strong>affiché</strong> pour être proposé.
                            </p>

                            <div style="display:flex;flex-direction:column;gap:.7rem">
                                @foreach ($moyens as $cle => $moyen)
                                    <div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap;font-size:.85rem">
                                        <span style="font-weight:600;min-width:15rem">{{ $moyen['libelle'] }}</span>

                                        @if ($moyen['proposable'])
                                            <span class="badge badge-success">Proposé</span>
                                        @else
                                            <span class="badge badge-danger">Non proposé</span>
                                        @endif

                                        @unless ($moyen['configure'])
                                            <span class="badge badge-warning" title="Les clés d'API ne sont pas renseignées dans le fichier .env : ce moyen ne peut pas fonctionner, et le proposer mènerait le client vers une page d'erreur.">
                                                clés absentes
                                            </span>
                                        @endunless

                                        @unless ($moyen['actif'])
                                            <span class="badge badge-warning" title="Le moyen est désactivé : il est refusé partout, y compris pour les liens envoyés manuellement.">
                                                désactivé
                                            </span>
                                        @endunless

                                        @if ($moyen['actif'] && ! $moyen['visible'])
                                            <span class="badge badge-warning" title="Le moyen fonctionne mais n'est pas affiché : il reste utilisable pour les liens de paiement envoyés manuellement.">
                                                masqué
                                            </span>
                                        @endif
                                    </div>
                                @endforeach
                            </div>

                            <p style="font-size:.78rem;color:var(--color-text-muted);margin:.9rem 0 0;max-width:70ch">
                                <strong>Désactiver</strong> refuse le moyen partout.
                                <strong>Masquer</strong> le retire de la liste sans le couper : les liens
                                de paiement déjà transmis continuent de fonctionner.
                            </p>
                        </div>
                    @endif

                    <div style="display:flex;flex-direction:column;gap:1.4rem">
                        @foreach ($definitions as $nom => $definition)
                            @php $etat = $valeurs[$nom]; @endphp

                            <div>
                                <div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap;margin-bottom:.35rem">
                                    <label for="set-{{ $nom }}" style="font-weight:600;font-size:.9rem;margin:0">
                                        {{ $definition['label'] }}
                                    </label>

                                    @if ($etat['modifie'])
                                        {{-- Distinguer une valeur saisie d'un défaut évite le doute
                                             le plus fréquent en exploitation : « est-ce le réglage
                                             d'origine ou quelqu'un l'a-t-il changé ? » --}}
                                        <span class="badge badge-info">modifié</span>
                                    @else
                                        <span class="badge">valeur par défaut</span>
                                    @endif

                                    @if (isset($definition['unit']))
                                        <span class="mono" style="font-size:.72rem;color:var(--color-text-muted)">
                                            {{ $definition['unit'] }}
                                        </span>
                                    @endif
                                </div>

                                <p style="font-size:.8rem;color:var(--color-text-muted);margin:0 0 .5rem;max-width:70ch">
                                    {{ $definition['description'] }}
                                </p>

                                <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap">
                                    @if ($definition['type'] === 'bool')
                                        <label class="switch" style="margin:0">
                                            <input type="hidden" name="{{ $nom }}" value="0">
                                            <input type="checkbox" name="{{ $nom }}" value="1"
                                                   id="set-{{ $nom }}"
                                                   @checked((bool) $etat['valeur'])>
                                            <span class="track"></span>
                                            <span style="font-size:.85rem">
                                                {{ $etat['valeur'] ? 'Activé' : 'Désactivé' }}
                                            </span>
                                        </label>
                                    @else
                                        <input
                                            type="{{ $definition['type'] === 'string' ? 'text' : 'number' }}"
                                            id="set-{{ $nom }}"
                                            name="{{ $nom }}"
                                            value="{{ $etat['valeur'] }}"
                                            class="form-control"
                                            style="max-width:220px"
                                            @if (isset($definition['min'])) min="{{ $definition['min'] }}" @endif
                                            @if (isset($definition['max'])) max="{{ $definition['max'] }}" @endif
                                            @if (isset($definition['step'])) step="{{ $definition['step'] }}" @endif
                                        >
                                    @endif

                                    @if (isset($definition['min'], $definition['max']))
                                        <span class="mono" style="font-size:.72rem;color:var(--color-text-muted)">
                                            entre {{ $definition['min'] }} et {{ $definition['max'] }}
                                        </span>
                                    @endif
                                </div>

                                @error($nom)
                                    <p class="field-error">{{ $message }}</p>
                                @enderror

                                {{-- Nom de configuration affiché : c'est ce que lit le code.
                                     Sans lui, impossible de relier un réglage de l'écran à
                                     l'appel `config()` correspondant lors d'un diagnostic. --}}
                                <p class="mono" style="font-size:.68rem;color:var(--color-text-muted);margin:.45rem 0 0">
                                    {{ $definition['key'] }}
                                </p>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif
        @endforeach

        <div style="display:flex;gap:.75rem;align-items:center;flex-wrap:wrap">
            <button type="submit" class="btn btn-primary">
                <i data-lucide="save" style="width:15px;height:15px"></i> Enregistrer
            </button>
            <p style="margin:0;font-size:.8rem;color:var(--color-text-muted)">
                Les valeurs s'appliquent à la requête suivante. Les appels IA déjà facturés ne
                sont pas recalculés.
            </p>
        </div>
    </form>
@endsection
