<?php
/**
 * Bootstrap : autoload + chargement du .env + client OpenAI partagé.
 * Inclus en début de chaque entrée HTTP (chat.php).
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// Chargement des variables d'environnement
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

// Vérification de la clé OpenAI (les autres clés sont vérifiées au moment de l'appel API)
if (empty($_ENV['OPENAI_API_KEY'])) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'OPENAI_API_KEY manquante dans .env']);
    exit;
}

// Client OpenAI partagé (équivalent du $client = OpenAI::client(...) du cours)
$GLOBALS['openai_client'] = OpenAI::client($_ENV['OPENAI_API_KEY']);
