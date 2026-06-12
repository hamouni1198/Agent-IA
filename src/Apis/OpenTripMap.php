<?php
/**
 * Client minimal pour l'API OpenTripMap.
 * Doc : https://opentripmap.io/docs
 */

declare(strict_types=1);

namespace TripMate\Apis;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class OpenTripMap
{
    private const BASE_URL = 'https://api.opentripmap.com/0.1/fr';

    private Client $http;

    public function __construct()
    {
        $this->http = new Client([
            'base_uri' => self::BASE_URL,
            'timeout' => 15.0,
        ]);
    }

    private function getKey(): string
    {
        $key = $_ENV['OPENTRIPMAP_API_KEY'] ?? null;
        if (!$key) {
            throw new \RuntimeException('OPENTRIPMAP_API_KEY manquante dans .env');
        }
        return $key;
    }

    /**
     * Cherche les attractions populaires d'une ville.
     *
     * @param string $ville Nom de la ville
     * @param int    $limite Nombre d'attractions à retourner (défaut 10)
     * @return array Liste d'attractions ou message d'erreur
     */
    public function chercherAttractions(string $ville, int $limite = 10): array
    {
        try {
            // 1. Géocodage de la ville pour obtenir lat/lon
            $geoResp = $this->http->get('/places/geoname', [
                'query' => ['name' => $ville, 'apikey' => $this->getKey()],
            ]);
            $geo = json_decode((string) $geoResp->getBody(), true);
            if (empty($geo['lat']) || empty($geo['lon'])) {
                return ['erreur' => "Ville '$ville' introuvable"];
            }

            // 2. Recherche des attractions dans un rayon de 5km
            $radiusResp = $this->http->get('/places/radius', [
                'query' => [
                    'radius' => 5000,
                    'lon' => $geo['lon'],
                    'lat' => $geo['lat'],
                    'rate' => '2',                  // notées 2 étoiles minimum
                    'kinds' => 'interesting_places',
                    'format' => 'json',
                    'limit' => $limite,
                    'apikey' => $this->getKey(),
                ],
            ]);
            $lieux = json_decode((string) $radiusResp->getBody(), true);

            // 3. Simplification pour le LLM
            $resultats = [];
            foreach ($lieux as $lieu) {
                $resultats[] = [
                    'nom' => $lieu['name'] ?? '?',
                    'categorie' => $lieu['kinds'] ?? '',
                    'note' => $lieu['rate'] ?? 0,
                ];
            }
            return $resultats;
        } catch (GuzzleException $e) {
            return ['erreur' => 'Erreur OpenTripMap : ' . $e->getMessage()];
        }
    }
}
