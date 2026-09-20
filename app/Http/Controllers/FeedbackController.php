<?php

namespace App\Http\Controllers;

use App\Models\Feedback;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class FeedbackController extends Controller
{
    /**
     * Afficher le formulaire de feedback
     */
    public function showForm()
    {
        return view('feedback.form');
    }

    /**
     * Stocker le feedback et envoyer email au porteur du projet
     */
    public function store(Request $request)
    {
        try {
            // Honeypot anti-spam (P1-1) : si le champ caché "website" est
            // rempli, c'est un robot → on ignore silencieusement (200-like).
            if (! empty($request->input('website'))) {
                Log::info('Feedback honeypot triggered', ['ip' => $request->ip()]);

                return redirect()->back()
                    ->with('success', 'Merci pour votre avis ! Nous l\'avons bien reçu.');
            }

            // Validation
            $validated = $request->validate([
                'email' => 'required|email|max:255',
                'avis' => 'required|string|min:10|max:2000',
                'note' => 'required|integer|min:1|max:5',
                'recommander' => 'nullable|boolean',
                'problemes_rencontres' => 'nullable|string|max:1000',
            ]);

            // Créer le feedback
            $feedback = Feedback::create($validated);

            // Envoyer email au porteur du projet
            $this->sendFeedbackEmail($feedback);

            Log::info('Feedback received', ['feedback_id' => $feedback->id, 'email' => $feedback->email]);

            return redirect()->back()
                ->with('success', 'Merci pour votre avis ! Nous l\'avons bien reçu.');
        } catch (\Exception $e) {
            Log::error('Error storing feedback', ['error' => $e->getMessage()]);

            return redirect()->back()
                ->with('error', 'Une erreur s\'est produite. Veuillez réessayer.');
        }
    }

    /**
     * Envoyer un email avec le feedback au porteur du projet
     */
    private function sendFeedbackEmail(Feedback $feedback)
    {
        $ownerEmail = config('app.project_owner_email', 'owner@formadoc.dev');

        $emailContent = "Nouvel avis reçu sur FORMADOC\n\n";
        $emailContent .= "Email : {$feedback->email}\n";
        $emailContent .= "Note : {$feedback->note}/5 ⭐\n";
        $emailContent .= 'Recommande FORMADOC : '.($feedback->recommander ? 'Oui' : 'Non')."\n\n";
        $emailContent .= "Avis :\n{$feedback->avis}\n\n";

        if ($feedback->problemes_rencontres) {
            $emailContent .= "Problèmes rencontrés :\n{$feedback->problemes_rencontres}\n\n";
        }

        $emailContent .= 'Reçu le : '.$feedback->created_at->format('d/m/Y H:i')."\n";

        try {
            Mail::raw($emailContent, function ($msg) use ($ownerEmail, $feedback) {
                $msg->to($ownerEmail)
                    ->subject("Nouvel avis FORMADOC - Note {$feedback->note}/5 de {$feedback->email}");
            });
            Log::info('Feedback email sent', ['to' => $ownerEmail]);
        } catch (\Exception $e) {
            Log::error('Error sending feedback email', ['error' => $e->getMessage()]);
            // Ne pas lever l'erreur - le feedback est déjà sauvegardé
        }
    }
}
