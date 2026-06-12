<?php
/**
 * Gestion de la mémoire persistante (faits sur l'utilisateur).
 * Calque direct des fonctions chargerMemoire() / sauvegarderMemoire() vues en cours.
 */

declare(strict_types=1);

namespace TripMate;

class Memoire
{
    private string $path;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? __DIR__ . '/../data/memoire-agent.json';
    }

    /** Lit la mémoire depuis le fichier JSON, ou retourne une structure vide au premier lancement. */
    public function charger(): array
    {
        if (!file_exists($this->path)) {
            return ['faits' => []];
        }
        $contenu = file_get_contents($this->path);
        if ($contenu === false) {
            return ['faits' => []];
        }
        $data = json_decode($contenu, true);
        return is_array($data) ? $data : ['faits' => []];
    }

    /** Écrit la mémoire mise à jour dans le fichier JSON. */
    public function sauvegarder(array $memoire): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents(
            $this->path,
            json_encode($memoire, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }
}
