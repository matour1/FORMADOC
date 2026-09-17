<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Crée ou élève un compte administrateur.
 *
 * **Pourquoi une commande plutôt qu'une requête à la main.** Élever un compte
 * donne accès aux coûts, documents et statistiques de TOUS les utilisateurs :
 * c'est l'opération la plus sensible de l'application. Elle mérite d'être
 * traçable (une commande laisse une trace dans l'historique du shell et dans
 * Git) et de dire clairement ce qu'elle a fait, y compris quand elle n'a rien
 * fait.
 *
 * **Pourquoi elle crée aussi le compte.** `--password` a un défaut calculé, ce
 * qui évite le cas pénible du premier administrateur : élever un compte
 * inexistant ne sert à rien, et créer l'utilisateur à la main exige de choisir
 * un mot de passe hors de la commande, donc de le faire circuler autrement.
 *
 * **`is_admin` n'est pas « fillable ».** L'élévation passe donc par `forceFill`
 * : c'est exactement le but recherché — `is_admin` ne doit JAMAIS pouvoir être
 * renseigné par un formulaire (`MassAssignment`), sinon une requête d'inscription
 * contenant `is_admin=1` créerait un administrateur.
 */
#[Signature('user:make-admin
    {email : Adresse email du compte à créer ou à élever}
    {--name= : Nom affiché (uniquement à la création du compte)}
    {--password= : Mot de passe (uniquement à la création du compte)}')]
#[Description('Crée un compte administrateur, ou élève un compte existant (accès /admin)')]
class MakeAdmin extends Command
{
    public function handle(): int
    {
        $email = trim((string) $this->argument('email'));

        if ($email === '') {
            $this->error('Une adresse email est requise.');

            return self::FAILURE;
        }

        $utilisateur = User::where('email', $email)->first();

        if ($utilisateur === null) {
            return $this->creerAdministrateur($email);
        }

        return $this->eleverAdministrateur($utilisateur);
    }

    /**
     * Crée le compte puis l'élève.
     *
     * Le mot de passe est affiché et n'est **jamais** réaffichable : il est haché
     * en base, donc une seconde exécution ne peut pas le retrouver. Le dire
     * explicitement évite que l'utilisateur compte sur un futur rappel.
     */
    private function creerAdministrateur(string $email): int
    {
        $motDePasse = (string) ($this->option('password') ?: Str::password(24, symbols: false));
        $nom = (string) ($this->option('name') ?: Str::before($email, '@'));

        $utilisateur = new User;
        $utilisateur->name = $nom;
        $utilisateur->email = $email;
        // Le cast `hashed` du modèle hache la valeur : inutile d'appeler Hash::make,
        // qui serait redondant (et risquerait un double hachage).
        $utilisateur->password = $motDePasse;
        $utilisateur->save();

        // Méthode du modèle, qui renseigne le champ avec `freshTimestamp()` : un
        // compte d'exploitation n'a pas de boîte mail à confirmer.
        $utilisateur->markEmailAsVerified();

        $this->elever($utilisateur);

        $this->newLine();
        $this->line('Compte administrateur : <options=bold>'.$email.'</>');
        $this->line('  Nom           : '.$nom);
        $this->line('  État          : <fg=green>créé</>');
        $this->line('  Mot de passe  : <options=bold>'.$motDePasse.'</>');
        $this->newLine();
        $this->warn('Ce mot de passe ne sera PLUS jamais affiché : il est haché en base.');
        $this->line('Notez-le maintenant, ou changez-le depuis l\'application.');
        $this->line('Accès : '.(string) config('app.url').'/admin');

        return self::SUCCESS;
    }

    /**
     * Élève un compte existant, **sans toucher à son mot de passe**.
     *
     * Réinitialiser un mot de passe existant serait un effet de bord destructeur :
     * l'utilisateur se retrouverait déconnecté de son propre compte pour une
     * opération qui ne parle que de droits.
     */
    private function eleverAdministrateur(User $utilisateur): int
    {
        $dejaAdmin = (bool) $utilisateur->is_admin;

        if (! $dejaAdmin) {
            $this->elever($utilisateur);
        }

        $this->newLine();
        $this->line('Compte administrateur : <options=bold>'.$utilisateur->email.'</>');
        $this->line('  Nom           : '.$utilisateur->name);
        $this->line('  État          : '.($dejaAdmin ? 'déjà administrateur (inchangé)' : '<fg=green>élevé</>'));

        if (! $dejaAdmin) {
            $this->line('  Mot de passe  : <fg=yellow>inchangé</> (le compte existait déjà)');
        }

        $this->line('Accès : '.(string) config('app.url').'/admin');

        return self::SUCCESS;
    }

    /**
     * `forceFill` : `is_admin` est volontairement hors de la liste `Fillable`, donc
     * une affectation de masse ne peut pas le renseigner. L'élévation exige ce
     * contournement explicite — c'est la garantie qu'aucun formulaire ne peut
     * créer un administrateur.
     */
    private function elever(User $utilisateur): void
    {
        $utilisateur->forceFill(['is_admin' => true])->save();
    }
}
