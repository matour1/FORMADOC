<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
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
}
