<?php
/**
 * Endpoint API du chat.
 *
 * POST   /chat.php  body JSON { session_id, message }  → flux SSE (data: {delta}/{done})
 * GET    /chat.php?session_id=...                       → { messages: [...] }
 * DELETE /chat.php?session_id=...                       → { ok: true }
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use TripMate\Agent;
use TripMate\Memoire;
use TripMate\Session;
use TripMate\Tools;

try {
    $method = $_SERVER['REQUEST_METHOD'];

    // --- GET : historique d'une session ---
    if ($method === 'GET') {
        header('Content-Type: application/json; charset=utf-8');
        $sessionId = $_GET['session_id'] ?? '';
        if (!$sessionId) {
            http_response_code(400);
            echo json_encode(['error' => 'session_id requis']);
            exit;
        }
        $session = new Session($sessionId);
        $messages = $session->chargerMessages();
        $visibles = array_values(array_filter($messages, function ($m) {
            return in_array($m['role'] ?? '', ['user', 'assistant'])
                && !empty($m['content'])
                && empty($m['tool_calls']);
        }));
        echo json_encode(['messages' => $visibles]);
        exit;
    }


    if ($method === 'DELETE') {
        header('Content-Type: application/json; charset=utf-8');
        $sessionId = $_GET['session_id'] ?? '';
        if (!$sessionId) {
            http_response_code(400);
            echo json_encode(['error' => 'session_id requis']);
            exit;
        }
        (new Session($sessionId))->reset();
        echo json_encode(['ok' => true]);
        exit;
    }


    if ($method !== 'POST') {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(405);
        echo json_encode(['error' => 'Méthode non autorisée']);
        exit;
    }


    $body = json_decode(file_get_contents('php://input'), true);
    $sessionId = $body['session_id'] ?? '';
    $userMessage = trim($body['message'] ?? '');

    if (!$sessionId || $userMessage === '') {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(400);
        echo json_encode(['error' => 'session_id et message requis']);
        exit;
    }


    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
    while (ob_get_level() > 0) { @ob_end_flush(); }


    $emit = function (string $token): void {
        echo 'data: ' . json_encode(['delta' => $token], JSON_UNESCAPED_UNICODE) . "\n\n";
        flush();
    };

    // Initialisation des composants
    $memoire = new Memoire();
    $session = new Session($sessionId);
    $tools = new Tools($memoire, $GLOBALS['openai_client']);
    $agent = new Agent($GLOBALS['openai_client'], $tools, $memoire);

    // Historique + rafraîchissement du system prompt
    $messages = $session->chargerMessages();
    if (empty($messages)) {
        $messages = [['role' => 'system', 'content' => $agent->construireSystemPrompt()]];
    } else {
        $messages[0] = ['role' => 'system', 'content' => $agent->construireSystemPrompt()];
    }

    $messages[] = ['role' => 'user', 'content' => $userMessage];

    // Boucle agent en streaming : chaque token part via $emit
    $reponse = $agent->traiterMessageStream($messages, $emit);

    // Sauvegarde de la réponse complète en session
    $messages[] = ['role' => 'assistant', 'content' => $reponse];
    $session->sauvegarderMessages($messages);

    // Fin du flux
    echo 'data: ' . json_encode(['done' => true]) . "\n\n";
    flush();
} catch (\Throwable $e) {
    error_log('[chat.php] Exception : ' . $e->getMessage());
    if (headers_sent()) {
        // Déjà en plein stream : on émet l'erreur dans le flux SSE
        echo 'data: ' . json_encode(['error' => 'Erreur serveur : ' . $e->getMessage()], JSON_UNESCAPED_UNICODE) . "\n\n";
        flush();
    } else {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        echo json_encode(['error' => 'Erreur serveur', 'detail' => $e->getMessage()]);
    }
}