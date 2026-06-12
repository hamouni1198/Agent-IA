<?php
/**
 * Script d'indexation RAG (à lancer en CLI).
 * Usage : php indexer-rag.php
 * À relancer à chaque fois que tu modifies les fichiers de data/knowledge/.
 */

declare(strict_types=1);

require_once __DIR__ . '/src/bootstrap.php';

use TripMate\Rag;

echo "Indexation RAG en cours...\n";
try {
    $rag = new Rag($GLOBALS['openai_client']);
    $n = $rag->indexer();
    echo "✅ {$n} chunks indexés → data/rag-index.json\n";
} catch (\Throwable $e) {
    echo '❌ Erreur : ' . $e->getMessage() . "\n";
    exit(1);
}