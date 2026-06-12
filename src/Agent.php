<?php
/**
 * Agent : boucle d'exécution qui enchaîne les appels au LLM jusqu'à obtenir
 * une réponse finale. Calque direct de la fonction traiterMessage() du cours,
 * avec en plus la sécurité MAX_ITERATIONS et la gestion d'erreurs.
 */

declare(strict_types=1);

namespace TripMate;

class Agent
{
    private const MODEL = 'gpt-4o-mini';
    private const MAX_ITERATIONS = 8;

    private $client;
    private Tools $tools;
    private Memoire $memoire;

    public function __construct($openAIClient, Tools $tools, Memoire $memoire)
    {
        $this->client = $openAIClient;
        $this->tools = $tools;
        $this->memoire = $memoire;
    }

    /**
     * Construit le system prompt en y injectant les faits déjà mémorisés.
     * Calque direct de construireSystemPrompt() du cours.
     */
    public function construireSystemPrompt(): string
    {
        $memoire = $this->memoire->charger();

        $prompt = <<<TXT
Tu es TripMate, un assistant de planification de voyage. Tu aides l'utilisateur à organiser ses voyages : choix de destination, recherche de vols, attractions touristiques, itinéraires jour par jour.

CE QUE TU SAIS FAIRE :
- Suggérer des destinations selon le type de voyage (plage, culture, aventure, gastronomie, nature, ville), la saison et le budget — utilise l'outil "chercher_destinations".
- Chercher des vols réels via l'API Amadeus — utilise l'outil "chercher_vols".
- Trouver les attractions touristiques d'une ville via OpenTripMap — utilise l'outil "chercher_attractions".
- Construire un itinéraire jour par jour — utilise l'outil "creer_itineraire" APRÈS avoir cherché des attractions.
- Mémoriser les préférences de l'utilisateur entre les conversations — utilise "memoriser" / "rappeler".

CE QUE TU NE FAIS PAS :
- Tu ne réserves rien (ni vols, ni hôtels) : tu fournis seulement de l'aide à la décision.
- Tu n'inventes jamais de prix de vol ou d'attraction : tu utilises toujours les outils.

TON ET FORMAT :
- Tu parles français, ton chaleureux et enthousiaste mais clair et structuré.
- Tu utilises des listes à puces et de courts paragraphes pour les itinéraires et propositions.
- Tu poses des questions de clarification si une information clé manque (date, ville de départ, budget).
- Quand l'utilisateur partage une préférence durable (budget habituel, ville de départ, allergies, style de voyage), tu utilises "memoriser" pour t'en souvenir.
- Avant de demander une info à l'utilisateur, vérifie si elle est déjà en mémoire (utilise "rappeler").
TXT;

        if (!empty($memoire['faits'])) {
            $prompt .= "\n\nINFORMATIONS DÉJÀ MÉMORISÉES SUR L'UTILISATEUR :";
            foreach ($memoire['faits'] as $f) {
                $date = date('d/m/Y', strtotime($f['date']));
                $prompt .= "\n- {$f['info']} ($date)";
            }
        }

        return $prompt;
    }

    /**
     * Version STREAMING de la boucle d'agent.
     * Identique à traiterMessage(), mais utilise createStreamed() : chaque
     * morceau de texte de la réponse finale est envoyé au client via $emit.
     * Les tool_calls sont accumulés morceau par morceau puis exécutés (silencieusement).
     *
     * @param array    $messages Historique (par référence, accumulé).
     * @param callable $emit     fn(string $token): void — envoie un token au client.
     * @return string Réponse finale complète (pour la sauvegarder en session).
     */
    public function traiterMessageStream(array &$messages, callable $emit): string
    {
        $schemas = $this->tools->getSchemas();
        $fonctions = $this->tools->getImplementations();

        for ($i = 0; $i < self::MAX_ITERATIONS; $i++) {
            try {
                $stream = $this->client->chat()->createStreamed([
                    'model' => self::MODEL,
                    'messages' => $messages,
                    'tools' => $schemas,
                ]);
            } catch (\Throwable $e) {
                error_log('[Agent] Erreur API OpenAI : ' . $e->getMessage());
                $msg = "Désolé, une erreur est survenue lors de l'appel au modèle.";
                $emit($msg);
                return $msg;
            }

            $contenu = '';
            $toolCalls = [];        // accumulés par index
            $finishReason = null;

            foreach ($stream as $chunk) {
                $choix = $chunk->choices[0];
                $delta = $choix->delta;

                // 1) Token de texte → on l'envoie direct au client
                if ($delta->content !== null && $delta->content !== '') {
                    $contenu .= $delta->content;
                    $emit($delta->content);
                }

                // 2) Morceaux de tool_calls (arrivent fragmentés)
                if (!empty($delta->toolCalls)) {
                    foreach ($delta->toolCalls as $tcDelta) {
                        $idx = $tcDelta->index;
                        if (!isset($toolCalls[$idx])) {
                            $toolCalls[$idx] = [
                                'id' => '',
                                'type' => 'function',
                                'function' => ['name' => '', 'arguments' => ''],
                            ];
                        }
                        if ($tcDelta->id) {
                            $toolCalls[$idx]['id'] = $tcDelta->id;
                        }
                        $fn = $tcDelta->function;
                        if ($fn !== null) {
                            if ($fn->name) {
                                $toolCalls[$idx]['function']['name'] = $fn->name;
                            }
                            if ($fn->arguments) {
                                $toolCalls[$idx]['function']['arguments'] .= $fn->arguments;
                            }
                        }
                    }
                }

                if ($choix->finishReason !== null) {
                    $finishReason = $choix->finishReason;
                }
            }

            if ($finishReason === 'tool_calls' && !empty($toolCalls)) {
                ksort($toolCalls);
                $toolCalls = array_values($toolCalls);

                // Message assistant avec ses tool_calls (content peut être null)
                $messages[] = [
                    'role' => 'assistant',
                    'content' => $contenu !== '' ? $contenu : null,
                    'tool_calls' => $toolCalls,
                ];

                // Exécution de chaque outil (comme dans traiterMessage)
                foreach ($toolCalls as $tc) {
                    $nom = $tc['function']['name'];
                    $args = json_decode($tc['function']['arguments'], true) ?? [];

                    if (!isset($fonctions[$nom])) {
                        $resultat = ['erreur' => "Outil inconnu : $nom"];
                    } else {
                        try {
                            $resultat = $fonctions[$nom]($args);
                        } catch (\Throwable $e) {
                            error_log("[Agent] Erreur outil $nom : " . $e->getMessage());
                            $resultat = ['erreur' => "Erreur lors de l'exécution de $nom : " . $e->getMessage()];
                        }
                    }

                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $tc['id'],
                        'content' => json_encode($resultat, JSON_UNESCAPED_UNICODE),
                    ];
                }
                // on continue la boucle → tour suivant
            } else {
                // Réponse finale : déjà streamée via $emit
                return $contenu !== '' ? $contenu : '(Réponse vide)';
            }
        }

        $msg = "Limite d'itérations atteinte (" . self::MAX_ITERATIONS . "). Reformule ta demande s'il te plaît.";
        $emit($msg);
        return $msg;
    }

    /**
     * Boucle d'agent : envoie les messages, exécute les outils demandés,
     * répète jusqu'à obtenir une réponse finale ou atteindre MAX_ITERATIONS.
     *
     * @param array $messages Historique passé par référence pour accumuler.
     * @return string Réponse finale de l'assistant.
     */
    public function traiterMessage(array &$messages): string
    {
        $schemas = $this->tools->getSchemas();
        $fonctions = $this->tools->getImplementations();

        for ($i = 0; $i < self::MAX_ITERATIONS; $i++) {
            try {
                $response = $this->client->chat()->create([
                    'model' => self::MODEL,
                    'messages' => $messages,
                    'tools' => $schemas,
                ]);
            } catch (\Throwable $e) {
                error_log('[Agent] Erreur API OpenAI : ' . $e->getMessage());
                return "Désolé, une erreur est survenue lors de l'appel au modèle : " . $e->getMessage();
            }

            $choix = $response->choices[0];

            if ($choix->finishReason === 'tool_calls') {
                // On ajoute le message de l'assistant (avec ses tool_calls) à l'historique
                $messages[] = $choix->message->toArray();

                // On exécute chaque outil demandé
                foreach ($choix->message->toolCalls as $tc) {
                    $nom = $tc->function->name;
                    $args = json_decode($tc->function->arguments, true) ?? [];

                    if (!isset($fonctions[$nom])) {
                        $resultat = ['erreur' => "Outil inconnu : $nom"];
                    } else {
                        try {
                            $resultat = $fonctions[$nom]($args);
                        } catch (\Throwable $e) {
                            error_log("[Agent] Erreur outil $nom : " . $e->getMessage());
                            $resultat = ['erreur' => "Erreur lors de l'exécution de $nom : " . $e->getMessage()];
                        }
                    }

                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $tc->id,
                        'content' => json_encode($resultat, JSON_UNESCAPED_UNICODE),
                    ];
                }
            } else {
                // L'IA a terminé : on retourne sa réponse textuelle finale
                $contenu = $choix->message->content ?? '';
                return $contenu !== '' ? $contenu : '(Réponse vide)';
            }
        }

        return "Limite d'itérations atteinte ({" . self::MAX_ITERATIONS . "}). Reformule ta demande s'il te plaît.";
    }
}
