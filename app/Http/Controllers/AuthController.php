<?php

namespace App\Http\Controllers;

use App\Mail\EmailVerificationMail;
use App\Mail\WelcomeMail;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Authentification par session (login, register, logout).
 *
 * Le projet utilisait des tables users/sessions sans routes d'auth :
 * ce contrôleur comble le manque avec un flux classique Laravel
 * (guard web, sessions, CSRF) sans toucher à routes/web.php.
 */
class AuthController extends Controller
{
    /**
     * Affiche le formulaire de connexion.
     */
    public function showLogin(): View
    {
        return view('auth.login');
    }

    /**
     * Affiche le formulaire d'inscription.
     */
    public function showRegister(): View
    {
        return view('auth.register');
    }

    /**
     * Traite la connexion.
     */
    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            // --- Compte suspendu -------------------------------------------
            //
            // Le contrôle a lieu APRÈS `Auth::attempt()` et non avant : vérifier
            // l'état du compte demande de le charger, or l'authentification est
            // précisément ce qui prouve que l'appelant est bien son propriétaire.
            // Contrôler avant reviendrait à révéler « ce compte existe mais est
            // suspendu » à quiconque devine une adresse e-mail.
            //
            // La session est invalidée immédiatement : sans cela, l'utilisateur
            // serait authentifié pour la durée de la requête, et un contrôle
            // ultérieur manquant laisserait la session ouverte.
            $utilisateur = Auth::user();

            if ($utilisateur !== null && ! $utilisateur->canSignIn()) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return back()
                    ->withErrors(['email' => 'Ce compte est suspendu. Contactez le support pour le rétablir.'])
                    ->onlyInput('email');
            }

            $request->session()->regenerate();

            return redirect()->intended(route('account.index'))
                ->with('success', 'Bienvenue !');
        }

        return back()
            ->withErrors(['email' => 'Ces identifiants ne correspondent pas à nos enregistrements.'])
            ->onlyInput('email');
    }

    /**
     * Traite l'inscription (compte avec 0 crédit, prêt à acheter).
     */
    public function register(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'credits_balance' => 0,
        ]);

        Auth::login($user);
        $request->session()->regenerate();

        // Emails non bloquants : bienvenue + lien de confirmation d'adresse.
        // Un échec d'envoi ne doit JAMAIS empêcher l'inscription.
        try {
            Mail::to($user->email)->send(new WelcomeMail($user));
        } catch (\Throwable $e) {
            Log::error('AuthController::register : échec envoi email de bienvenue', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }

        $this->sendEmailVerification($user);

        return redirect()->route('onboarding')
            ->with('success', 'Compte créé. Bienvenue sur FORMADOC ! Faisons connaissance.');
    }

    /**
     * Déconnexion.
     */
    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')
            ->with('success', 'Vous êtes déconnecté.');
    }

    /**
     * Affiche le formulaire « mot de passe oublié ».
     */
    public function showForgotPassword(): View
    {
        return view('auth.forgot-password');
    }

    /**
     * Envoie le lien de réinitialisation.
     */
    public function sendResetLink(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $status = Password::sendResetLink($data);

        // On renvoie toujours le même message (évite l'énumération des emails).
        return $status === Password::RESET_LINK_SENT
            ? back()->with('status', 'Si cette adresse existe, un lien de réinitialisation vient de vous être envoyé.')
            : back()->withErrors(['email' => 'Impossible d\'envoyer le lien pour cette adresse.']);
    }

    /**
     * Affiche le formulaire de nouveau mot de passe.
     */
    public function showResetForm(string $token, Request $request): View
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => $request->query('email', ''),
        ]);
    }

    /**
     * Réinitialise le mot de passe.
     */
    public function reset(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $status = Password::reset(
            $data,
            function ($user, $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();
            }
        );

        return $status === Password::PASSWORD_RESET
            ? redirect()->route('login')->with('success', 'Votre mot de passe a été réinitialisé. Connectez-vous.')
            : back()->withErrors(['email' => 'Ce lien de réinitialisation est invalide ou a expiré.']);
    }

    /**
     * Envoie le lien signé de confirmation d'email (non bloquant).
     */
    private function sendEmailVerification(User $user): void
    {
        if ($user->hasVerifiedEmail()) {
            return;
        }

        try {
            $url = URL::temporarySignedRoute(
                'verification.verify',
                now()->addHours(24),
                ['user' => $user->id],
            );

            Mail::to($user->email)->send(new EmailVerificationMail($user, $url));
        } catch (\Throwable $e) {
            Log::error('AuthController::sendEmailVerification : échec envoi lien de confirmation', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Page « vérifie ton email » (accessible même sans vérification).
     */
    public function showVerificationNotice(): View|RedirectResponse
    {
        $user = Auth::user();

        if (! $user) {
            return redirect()->route('login');
        }

        if ($user->hasVerifiedEmail()) {
            return redirect()->route('account.index')
                ->with('success', 'Votre adresse email est déjà confirmée.');
        }

        return view('auth.verify-email');
    }

    /**
     * Confirme l'adresse via le lien signé.
     */
    public function verify(Request $request, User $user): RedirectResponse
    {
        // Lien invalide ou expiré
        if (! $request->hasValidSignature()) {
            return redirect()->route('verification.notice')
                ->with('error', 'Ce lien de confirmation est invalide ou a expiré. Demandez-en un nouveau.');
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        // Si un autre compte est connecté, on connecte l'utilisateur confirmé.
        if (Auth::id() !== $user->id) {
            Auth::login($user);
            $request->session()->regenerate();
        }

        return redirect()->route('account.index')
            ->with('success', 'Adresse email confirmée. Merci !');
    }

    /**
     * Renvoie le lien de confirmation (POST, non bloquant).
     */
    public function resendVerification(Request $request): RedirectResponse
    {
        $user = Auth::user();

        if (! $user) {
            return redirect()->route('login');
        }

        if ($user->hasVerifiedEmail()) {
            return redirect()->route('account.index')
                ->with('success', 'Votre adresse email est déjà confirmée.');
        }

        $this->sendEmailVerification($user);

        return back()
            ->with('status', 'Un nouveau lien de confirmation vient de vous être envoyé.');
    }
}
