<?php
/**
 * Gestion de l'historique d'une conversation.
 * Une session = un fichier JSON dans data/sessions/{session_id}.json.
 * L'historique contient tous les messages (system, user, assistant, tool) pour préserver
 * le contexte des tool_calls entre requêtes HTTP.
 */

declare(strict_types=1);

namespace TripMate;

class Session
{
    private string $sessionId;
    private string $dir;

    public function __construct(string $sessionId)
    {
        // Sécurité : on autorise seulement [a-z0-9-] pour éviter les path traversal
        if (!preg_match('/^[a-z0-9-]{8,64}$/', $sessionId)) {
            throw new \InvalidArgumentException('session_id invalide');
        }
        $this->sessionId = $sessionId;
        $this->dir = __DIR__ . '/../data/sessions';
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0755, true);
        }
    }

    private function path(): string
    {
        return $this->dir . '/' . $this->sessionId . '.json';
    }

    /** Charge l'historique de la session, ou un tableau vide si nouvelle session. */
    public function chargerMessages(): array
    {
        if (!file_exists($this->path())) {
            return [];
        }
        $contenu = file_get_contents($this->path());
        if ($contenu === false) {
            return [];
        }
        $data = json_decode($contenu, true);
        return is_array($data) ? $data : [];
    }

    /** Sauvegarde l'historique complet. */
    public function sauvegarderMessages(array $messages): void
    {
        file_put_contents(
            $this->path(),
            json_encode($messages, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }

    /** Vide la session (utile pour le bouton "nouvelle conversation"). */
    public function reset(): void
    {
        if (file_exists($this->path())) {
            unlink($this->path());
        }
    }
}
