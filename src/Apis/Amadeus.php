<?php
/**
 * Client minimal pour l'API Amadeus Self-Service (environnement test).
 * Gère le token OAuth2 (mis en cache mémoire) et expose les endpoints utilisés.
 *
 * Doc : https://developers.amadeus.com/self-service
 */

declare(strict_types=1);

namespace TripMate\Apis;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class Amadeus
{
    private const BASE_URL = 'https://test.api.amadeus.com';

    private Client $http;
    private static ?string $tokenCache = null;
    private static int $tokenExpiresAt = 0;

    public function __construct()
    {
        $this->http = new Client([
            'base_uri' => self::BASE_URL,
            'timeout' => 15.0,
        ]);
    }


    private function getToken(): string
    {
        if (self::$tokenCache !== null && self::$tokenExpiresAt > time() + 30) {
            return self::$tokenCache;
        }

        $apiKey = $_ENV['AMADEUS_API_KEY'] ?? null;
        $apiSecret = $_ENV['AMADEUS_API_SECRET'] ?? null;
        if (!$apiKey || !$apiSecret) {
            throw new \RuntimeException('AMADEUS_API_KEY ou AMADEUS_API_SECRET manquant dans .env');
        }

        $resp = $this->http->post('/v1/security/oauth2/token', [
            'form_params' => [
                'grant_type' => 'client_credentials',
                'client_id' => $apiKey,
                'client_secret' => $apiSecret,
            ],
        ]);
        $data = json_decode((string) $resp->getBody(), true);
        self::$tokenCache = $data['access_token'];
        self::$tokenExpiresAt = time() + ($data['expires_in'] ?? 1799);
        return self::$tokenCache;
    }

    
    public function chercherCodeIATA(string $ville): ?string
    {
        try {
            $resp = $this->http->get('/v1/reference-data/locations', [
                'headers' => ['Authorization' => 'Bearer ' . $this->getToken()],
                'query' => [
                    'subType' => 'CITY',
                    'keyword' => $ville,
                    'page[limit]' => 1,
                ],
            ]);
            $data = json_decode((string) $resp->getBody(), true);
            return $data['data'][0]['iataCode'] ?? null;
        } catch (GuzzleException $e) {
            return null;
        }
    }

    /**
     * Cherche des offres de vol entre deux villes.
     *
     * @param string $origine     Nom de ville ou code IATA
     * @param string $destination Nom de ville ou code IATA
     * @param string $date        Date aller au format YYYY-MM-DD
     * @param int    $adultes     Nombre d'adultes (défaut 1)
     * @return array Liste simplifiée des offres ou message d'erreur
     */
    public function chercherVols(string $origine, string $destination, string $date, int $adultes = 1): array
    {

        $origineCode = strlen($origine) === 3 ? strtoupper($origine) : $this->chercherCodeIATA($origine);
        $destCode = strlen($destination) === 3 ? strtoupper($destination) : $this->chercherCodeIATA($destination);

        if (!$origineCode || !$destCode) {
            return ['erreur' => "Impossible de trouver le code IATA pour '$origine' ou '$destination'"];
        }

        try {
            $resp = $this->http->get('/v2/shopping/flight-offers', [
                'headers' => ['Authorization' => 'Bearer ' . $this->getToken()],
                'query' => [
                    'originLocationCode' => $origineCode,
                    'destinationLocationCode' => $destCode,
                    'departureDate' => $date,
                    'adults' => $adultes,
                    'max' => 5,
                    'currencyCode' => 'EUR',
                ],
            ]);
            $data = json_decode((string) $resp->getBody(), true);
            return $this->simplifierVols($data['data'] ?? []);
        } catch (GuzzleException $e) {
            return ['erreur' => 'Erreur Amadeus : ' . $e->getMessage()];
        }
    }

    /** Réduit la réponse Amadeus à l'essentiel pour ne pas saturer le contexte LLM. */
    private function simplifierVols(array $offres): array
    {
        $resultats = [];
        foreach ($offres as $offre) {
            $segments = [];
            foreach ($offre['itineraries'][0]['segments'] ?? [] as $seg) {
                $segments[] = [
                    'depart' => $seg['departure']['iataCode'] . ' ' . substr($seg['departure']['at'], 11, 5),
                    'arrivee' => $seg['arrival']['iataCode'] . ' ' . substr($seg['arrival']['at'], 11, 5),
                    'compagnie' => $seg['carrierCode'] ?? '?',
                    'numero_vol' => ($seg['carrierCode'] ?? '?') . ($seg['number'] ?? ''),
                ];
            }
            $resultats[] = [
                'prix' => $offre['price']['total'] . ' ' . $offre['price']['currency'],
                'duree' => $offre['itineraries'][0]['duration'] ?? '?',
                'escales' => count($segments) - 1,
                'segments' => $segments,
            ];
        }
        return $resultats;
    }
}
