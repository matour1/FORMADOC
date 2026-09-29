@extends('layouts.admin')

@section('title', $template ? 'Modifier '.$template->name : 'Nouveau gabarit')

{{--
    Formulaire de crÃ©ation / modification d'un gabarit.

    **Les unitÃ©s sont rappelÃ©es Ã  cÃ´tÃ© de chaque champ, et c'est indispensable.**
    Le moteur (PhpWord) travaille en POINTS pour les tailles et en twips pour les
    marges et espacements, pas en centimètres. Une marge de « 2.54 » donnerait un
    document illisible. Sans ces repères, le formulaire inviterait à saisir des
    valeurs fausses d'un facteur 1440.

    **Les bornes sont dans les rÃ¨gles de validation du contrÃ´leur**, pas
    seulement en attributs HTML : un `max` d'attribut se contourne, et un gabarit
    hors bornes n'affecte pas un document mais TOUS les suivants.
--}}

@section('content')
    @php
        // Valeurs courantes, extraites des params normalisÃ©s pour Ãªtre
        // utilisables directement dans les champs.
        $t = $params['tailles'] ?? [];
        $c = $params['couleurs'] ?? [];
        $e = $params['espacements'] ?? [];
        $m = $params['marges'] ?? [];
        $tab = $params['tableau'] ?? [];
    @endphp

    <div class="page-head">
        <div>
            <h1>{{ $template ? 'Modifier Â« '.$template->name.' Â»' : 'Nouveau gabarit' }}</h1>
            <p>
                Ces rÃ©glages s'appliquent aux documents mis en forme avec ce gabarit.
                Les valeurs sont prÃ©-remplies avec un gabarit acadÃ©mique classique :
                ajustez seulement ce qui diffÃ¨re.
            </p>
        </div>
        <a href="{{ route('admin.templates.index') }}" class="btn btn-secondary">
            <i data-lucide="arrow-left" style="width:16px;height:16px"></i>
            Retour
        </a>
    </div>

    {{-- Le layout admin affiche DÉJÀ les erreurs de validation (« Action
         refusée »). On ne les répète donc pas ici : deux blocs identiques
         donneraient l'impression de deux problèmes distincts. --}}

    <form method="POST"
          action="{{ $template ? route('admin.templates.update', $template) : route('admin.templates.store') }}">
        @csrf
        @if ($template)
            @method('PUT')
        @endif

        {{-- Identification --}}
        <div class="card">
            <h2 class="card-title">Identification</h2>

            <div class="form-group">
                <label for="name">Nom du gabarit <span style="color:var(--color-correction)">*</span></label>
                <input type="text" id="name" name="name" required maxlength="191"
                       value="{{ old('name', $template->name ?? '') }}"
                       placeholder="Ex. Rapport de stage â€” UniversitÃ© de Douala">
                <small>Le nom apparaÃ®t dans la liste proposÃ©e aux utilisateurs.</small>
            </div>

            <div class="form-group">
                <label for="description">Description</label>
                <textarea id="description" name="description" rows="2" maxlength="2000"
                          placeholder="Ex. Times New Roman 12, interligne 1,5, titres bleu foncÃ©.">{{ old('description', $template->description ?? '') }}</textarea>
            </div>

            <label class="check-card" for="is_public">
                <input type="checkbox" name="is_public" id="is_public" value="1"
                       @checked(old('is_public', $template->is_public ?? true))>
                <span>
                    <strong>Proposer ce gabarit aux utilisateurs</strong>
                    <small>
                        DÃ©cochÃ©, le gabarit reste enregistrÃ© mais n'apparaÃ®t plus dans les choix.
                        C'est la bonne faÃ§on de retirer un gabarit dÃ©jÃ  utilisÃ©, sans casser
                        les documents qui s'y rÃ©fÃ¨rent.
                    </small>
                </span>
            </label>
        </div>

        {{-- Police et texte --}}
        <div class="card" style="margin-top:1.2rem">
            <h2 class="card-title">Police et texte</h2>

            <div class="form-grid">
                <div class="form-group">
                    <label for="police">Police <span style="color:var(--color-correction)">*</span></label>
                    <input type="text" id="police" name="police" required maxlength="100"
                           value="{{ old('police', $params['police'] ?? 'Times New Roman') }}"
                           placeholder="Times New Roman">
                    <small>Le nom exact, tel qu'il apparaÃ®t dans Word.</small>
                </div>

                <div class="form-group">
                    <label for="interligne">Interligne <span style="color:var(--color-correction)">*</span></label>
                    <input type="number" id="interligne" name="interligne" required
                           step="0.05" min="0.5" max="3"
                           value="{{ old('interligne', $params['interligne'] ?? 1.5) }}">
                    <small>Multiple de la hauteur de ligne. 1 = simple, 1,5 = usuel, 2 = double.</small>
                </div>

                <div class="form-group">
                    <label for="alignement_titres">Alignement des titres <span style="color:var(--color-correction)">*</span></label>
                    <select id="alignement_titres" name="alignement_titres" required>
                        @foreach (['left' => 'Ã€ gauche', 'center' => 'CentrÃ©', 'right' => 'Ã€ droite', 'justify' => 'JustifiÃ©'] as $valeur => $libelle)
                            <option value="{{ $valeur }}"
                                    @selected(old('alignement_titres', $params['alignement_titres'] ?? 'left') === $valeur)>
                                {{ $libelle }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>

        {{-- Tailles --}}
        <div class="card" style="margin-top:1.2rem">
            <h2 class="card-title">Tailles de police</h2>
            <div class="banner banner-info" style="margin-bottom:1rem">
                <i data-lucide="info" style="width:16px;height:16px"></i>
                <div>
                    <strong>Valeurs en POINTS, comme dans Word.</strong>
                    <code>12</code> = 12 pt · <code>14</code> = 14 pt. C'est la valeur transmise
                    telle quelle au moteur de génération — il n'y a aucune conversion.
                    Bornes acceptées : 6 à 72 pt.
                </div>
            </div>

            <div class="form-grid">
                @foreach (['titre1' => 'Titre de niveau 1', 'titre2' => 'Titre de niveau 2', 'titre3' => 'Titre de niveau 3', 'corps' => 'Corps de texte'] as $cle => $libelle)
                    <div class="form-group">
                        <label for="tailles_{{ $cle }}">{{ $libelle }} <span style="color:var(--color-correction)">*</span></label>
                        <input type="number" id="tailles_{{ $cle }}" name="tailles[{{ $cle }}]" required
                               min="6" max="72" step="1"
                               value="{{ old('tailles.'.$cle, $t[$cle] ?? 12) }}">
                        <small>En points (pt)</small>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Couleurs --}}
        <div class="card" style="margin-top:1.2rem">
            <h2 class="card-title">Couleurs des titres et du texte</h2>
            <p style="color:var(--color-text-muted);font-size:.82rem;margin-bottom:1rem">
                Code hexadÃ©cimal Ã  six chiffres, <strong>sans le diÃ¨se</strong> â€”
                <code>1F3864</code> et non <code>#1F3864</code>. Format attendu par Word.
            </p>

            <div class="form-grid">
                @foreach (['titre1' => 'Titre 1', 'titre2' => 'Titre 2', 'titre3' => 'Titre 3', 'corps' => 'Corps'] as $cle => $libelle)
                    <div class="form-group">
                        <label for="couleurs_{{ $cle }}">{{ $libelle }} <span style="color:var(--color-correction)">*</span></label>
                        <div style="display:flex;align-items:center;gap:.5rem">
                            {{-- Un sÃ©lecteur de couleur nativement, pour Ã©viter de
                                 recopier un code Ã  la main. Le champ texte reste
                                 visible et modifiable : c'est lui qui est soumis. --}}
                            <input type="color" value="#{{ old('couleurs.'.$cle, $c[$cle] ?? '000000') }}"
                                   oninput="document.getElementById('couleurs_{{ $cle }}').value = this.value.slice(1).toUpperCase()"
                                   style="width:44px;height:38px;padding:2px;border:1px solid var(--color-border);border-radius:8px;cursor:pointer"
                                   aria-label="Choisir la couleur {{ $libelle }}">
                            <input type="text" id="couleurs_{{ $cle }}" name="couleurs[{{ $cle }}]" required
                                   maxlength="6" pattern="[0-9A-Fa-f]{6}"
                                   value="{{ old('couleurs.'.$cle, $c[$cle] ?? '000000') }}"
                                   style="font-family:var(--font-mono);text-transform:uppercase">
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Espacements --}}
        <div class="card" style="margin-top:1.2rem">
            <h2 class="card-title">Espacements</h2>
            <div class="banner banner-info" style="margin-bottom:1rem">
                <i data-lucide="info" style="width:16px;height:16px"></i>
                <div>
                    <strong>Valeurs en twips.</strong> <code>1440</code> = 2,54 cm = 1 pouce Â·
                    <code>240</code> â‰ˆ 4,2 mm (espacement courant avant un titre).
                </div>
            </div>

            <div class="form-grid">
                @foreach (['avant_titre' => 'Avant un titre', 'apres_titre' => 'AprÃ¨s un titre', 'apres_paragraphe' => 'AprÃ¨s un paragraphe'] as $cle => $libelle)
                    <div class="form-group">
                        <label for="espacements_{{ $cle }}">{{ $libelle }} <span style="color:var(--color-correction)">*</span></label>
                        <input type="number" id="espacements_{{ $cle }}" name="espacements[{{ $cle }}]" required
                               min="0" max="1440" step="10"
                               value="{{ old('espacements.'.$cle, $e[$cle] ?? 120) }}">
                        <small>Actuel : {{ number_format(($e[$cle] ?? 120) / 1440 * 2.54, 2, ',', ' ') }} cm</small>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Marges --}}
        <div class="card" style="margin-top:1.2rem">
            <h2 class="card-title">Marges de page</h2>
            <div class="banner banner-info" style="margin-bottom:1rem">
                <i data-lucide="info" style="width:16px;height:16px"></i>
                <div>
                    <strong>Valeurs en twips.</strong> <code>1440</code> = 2,54 cm.
                    Les marges d'en-tÃªte et de pied doivent rester INFÃ‰RIEURES aux marges
                    haute et basse : sinon l'en-tÃªte chevaucherait le texte.
                </div>
            </div>

            <div class="form-grid">
                @foreach (['top' => 'Haute', 'bottom' => 'Basse', 'left' => 'Gauche', 'right' => 'Droite', 'header' => 'En-tÃªte', 'footer' => 'Pied de page'] as $cle => $libelle)
                    <div class="form-group">
                        <label for="marges_{{ $cle }}">{{ $libelle }} <span style="color:var(--color-correction)">*</span></label>
                        <input type="number" id="marges_{{ $cle }}" name="marges[{{ $cle }}]" required
                               min="0" max="2880" step="10"
                               value="{{ old('marges.'.$cle, $m[$cle] ?? 1440) }}">
                        <small>Actuel : {{ number_format(($m[$cle] ?? 1440) / 1440 * 2.54, 2, ',', ' ') }} cm</small>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Tableaux --}}
        <div class="card" style="margin-top:1.2rem">
            <h2 class="card-title">Tableaux</h2>

            <div class="form-grid">
                <div class="form-group">
                    <label for="tableau_style">Style de bordure <span style="color:var(--color-correction)">*</span></label>
                    <input type="text" id="tableau_style" name="tableau[style]" required maxlength="60"
                           value="{{ old('tableau.style', $tab['style'] ?? 'TableGrid') }}">
                    <small>Nom du style Word. <code>TableGrid</code> = quadrillage complet.</small>
                </div>

                <div class="form-group">
                    <label for="tableau_header_couleur">Fond des en-tÃªtes <span style="color:var(--color-correction)">*</span></label>
                    <div style="display:flex;align-items:center;gap:.5rem">
                        <input type="color" value="#{{ old('tableau.header_couleur', $tab['header_couleur'] ?? '1F3864') }}"
                               oninput="document.getElementById('tableau_header_couleur').value = this.value.slice(1).toUpperCase()"
                               style="width:44px;height:38px;padding:2px;border:1px solid var(--color-border);border-radius:8px;cursor:pointer"
                               aria-label="Choisir la couleur de fond des en-tÃªtes">
                        <input type="text" id="tableau_header_couleur" name="tableau[header_couleur]" required
                               maxlength="6" pattern="[0-9A-Fa-f]{6}"
                               value="{{ old('tableau.header_couleur', $tab['header_couleur'] ?? '1F3864') }}"
                               style="font-family:var(--font-mono);text-transform:uppercase">
                    </div>
                </div>

                <div class="form-group">
                    <label for="tableau_header_texte">Texte des en-tÃªtes <span style="color:var(--color-correction)">*</span></label>
                    <div style="display:flex;align-items:center;gap:.5rem">
                        <input type="color" value="#{{ old('tableau.header_texte', $tab['header_texte'] ?? 'FFFFFF') }}"
                               oninput="document.getElementById('tableau_header_texte').value = this.value.slice(1).toUpperCase()"
                               style="width:44px;height:38px;padding:2px;border:1px solid var(--color-border);border-radius:8px;cursor:pointer"
                               aria-label="Choisir la couleur du texte des en-tÃªtes">
                        <input type="text" id="tableau_header_texte" name="tableau[header_texte]" required
                               maxlength="6" pattern="[0-9A-Fa-f]{6}"
                               value="{{ old('tableau.header_texte', $tab['header_texte'] ?? 'FFFFFF') }}"
                               style="font-family:var(--font-mono);text-transform:uppercase">
                    </div>
                    {{-- Le contraste est un critÃ¨re d'accessibilitÃ©, pas une
                         prÃ©fÃ©rence : un en-tÃªte blanc sur fond blanc rend le
                         tableau illisible. --}}
                    <small>Doit contraster avec le fond, sinon le tableau est illisible.</small>
                </div>
            </div>

            <label class="check-card" for="tableau_bordure">
                <input type="checkbox" name="tableau[bordure]" id="tableau_bordure" value="1"
                       @checked(old('tableau.bordure', $tab['bordure'] ?? true))>
                <span>
                    <strong>Bordures visibles</strong>
                    <small>DÃ©cochÃ©, les tableaux sont sans quadrillage (sÃ©parÃ©s par des espaces).</small>
                </span>
            </label>
        </div>

        <div style="display:flex;justify-content:flex-end;gap:.65rem;margin-top:1.5rem">
            <a href="{{ route('admin.templates.index') }}" class="btn btn-secondary">Annuler</a>
            <button type="submit" class="btn btn-primary">
                <i data-lucide="check" style="width:16px;height:16px"></i>
                {{ $template ? 'Enregistrer les modifications' : 'CrÃ©er le gabarit' }}
            </button>
        </div>
    </form>
@endsection
