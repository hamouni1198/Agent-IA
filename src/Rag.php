<?php
/**
 * Moteur RAG minimal.
 *  - indexer()   : lit data/knowledge/*.md, découpe en chunks, calcule les
 *                  embeddings via OpenAI, sauvegarde data/rag-index.json.
 *  - rechercher(): embed la question, similarité cosinus vs chaque chunk,
 *                  renvoie les top-k extraits les plus pertinents.
 */

declare(strict_types=1);

namespace TripMate;

class Rag
{
    private const EMBED_MODEL = 'text-embedding-3-small';

    private $client;
    private string $indexPath;
    private string $knowledgeDir;

    public function __construct($openAIClient, ?string $indexPath = null, ?string $knowledgeDir = null)
    {
        $this->client = $openAIClient;
        $this->indexPath = $indexPath ?? __DIR__ . '/../data/rag-index.json';
        $this->knowledgeDir = $knowledgeDir ?? __DIR__ . '/../data/knowledge';
    }

    /** Construit l'index : lit les docs, découpe, embed, sauvegarde. Renvoie le nb de chunks. */
    public function indexer(): int
    {
        if (!is_dir($this->knowledgeDir)) {
            throw new \RuntimeException("Dossier introuvable : {$this->knowledgeDir}");
        }

        $fichiers = glob($this->knowledgeDir . '/*.{md,txt}', GLOB_BRACE) ?: [];
        $chunks = [];
        foreach ($fichiers as $fichier) {
            $contenu = file_get_contents($fichier);
            if ($contenu === false) {
                continue;
            }
            foreach ($this->decouper($contenu) as $morceau) {
                $chunks[] = ['source' => basename($fichier), 'texte' => $morceau];
            }
        }

        if (empty($chunks)) {
            throw new \RuntimeException('Aucun contenu à indexer dans ' . $this->knowledgeDir);
        }

        // Embeddings en un seul appel batch (tous les chunks d'un coup)
        $textes = array_column($chunks, 'texte');
        $response = $this->client->embeddings()->create([
            'model' => self::EMBED_MODEL,
            'input' => $textes,
        ]);
        foreach ($response->embeddings as $emb) {
            $chunks[$emb->index]['embedding'] = $emb->embedding;
        }

        $dir = dirname($this->indexPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($this->indexPath, json_encode($chunks, JSON_UNESCAPED_UNICODE));

        return count($chunks);
    }

    /** Recherche sémantique : renvoie les $k extraits les plus proches de la question. */
    public function rechercher(string $question, int $k = 3): array
    {
        if (!file_exists($this->indexPath)) {
            return ['erreur' => "Index RAG introuvable. Lance d'abord : php indexer-rag.php"];
        }
        $index = json_decode((string) file_get_contents($this->indexPath), true);
        if (!is_array($index) || empty($index)) {
            return ['erreur' => 'Index RAG vide.'];
        }

        // 1) Embedding de la question
        $response = $this->client->embeddings()->create([
            'model' => self::EMBED_MODEL,
            'input' => $question,
        ]);
        $vecteurQuestion = $response->embeddings[0]->embedding;

        // 2) Similarité cosinus contre chaque chunk
        $scores = [];
        foreach ($index as $i => $chunk) {
            $scores[$i] = $this->cosinus($vecteurQuestion, $chunk['embedding']);
        }
        arsort($scores);
        $topIds = array_slice(array_keys($scores), 0, $k, true);

        // 3) On renvoie les extraits au LLM
        $resultats = [];
        foreach ($topIds as $i) {
            $resultats[] = [
                'source' => $index[$i]['source'],
                'texte' => $index[$i]['texte'],
                'score' => round($scores[$i], 3),
            ];
        }
        return ['extraits' => $resultats];
    }

    /** Découpe un texte en chunks d'environ $tailleMax caractères, en respectant les paragraphes. */
    private function decouper(string $texte, int $tailleMax = 600): array
    {
        $paragraphes = preg_split('/\n\s*\n/', trim($texte)) ?: [];
        $chunks = [];
        $courant = '';
        foreach ($paragraphes as $p) {
            $p = trim($p);
            if ($p === '') {
                continue;
            }
            if ($courant !== '' && mb_strlen($courant) + mb_strlen($p) > $tailleMax) {
                $chunks[] = $courant;
                $courant = $p;
            } else {
                $courant = $courant === '' ? $p : $courant . "\n\n" . $p;
            }
        }
        if ($courant !== '') {
            $chunks[] = $courant;
        }
        return $chunks;
    }

    /** Similarité cosinus entre deux vecteurs. */
    private function cosinus(array $a, array $b): float
    {
        $dot = 0.0;
        $na = 0.0;
        $nb = 0.0;
        $n = min(count($a), count($b));
        for ($i = 0; $i < $n; $i++) {
            $dot += $a[$i] * $b[$i];
            $na += $a[$i] * $a[$i];
            $nb += $b[$i] * $b[$i];
        }
        if ($na == 0.0 || $nb == 0.0) {
            return 0.0;
        }
        return $dot / (sqrt($na) * sqrt($nb));
    }
}