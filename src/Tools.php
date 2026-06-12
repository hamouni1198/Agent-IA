<?php
/**
 * Définition des tools que l'agent peut appeler.
 *
 * Calque direct du pattern $tools / $fonctions vu en cours :
 *  - $tools  : schéma JSON exposé à OpenAI (description + paramètres)
 *  - $fonctions : implémentations PHP, appelées quand l'IA décide d'utiliser un outil
 *
 * On garde les 2 outils mémoire du cours (memoriser, rappeler) et on ajoute
 * 4 outils métier voyage (chercher_destinations, chercher_vols,
 * chercher_attractions, creer_itineraire).
 */

declare(strict_types=1);

namespace TripMate;

use TripMate\Apis\Amadeus;
use TripMate\Apis\OpenTripMap;

class Tools
{
    private Memoire $memoire;
    private Amadeus $amadeus;
    private OpenTripMap $tripMap;
    private Rag $rag;

    public function __construct(Memoire $memoire, $openAIClient)
    {
        $this->memoire = $memoire;
        $this->amadeus = new Amadeus();
        $this->tripMap = new OpenTripMap();
        $this->rag = new Rag($openAIClient);
    }

    /** Schémas des outils exposés à OpenAI (équivalent du tableau $tools du cours). */
    public function getSchemas(): array
    {
        return [
            // --- Outils mémoire (repris du cours) ---
            [
                'type' => 'function',
                'function' => [
                    'name' => 'memoriser',
                    'description' => "Sauvegarde une information importante sur l'utilisateur (préférence de voyage, ville de départ habituelle, budget habituel, allergies, etc.) pour s'en souvenir lors des prochaines conversations.",
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'information' => [
                                'type' => 'string',
                                'description' => "L'information à mémoriser, formulée clairement (ex: 'aime les voyages culturels', 'budget habituel 1500€', 'part toujours de Paris').",
                            ],
                        ],
                        'required' => ['information'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'rappeler',
                    'description' => "Cherche dans la mémoire les informations sauvegardées sur l'utilisateur lors de précédentes conversations.",
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'recherche' => [
                                'type' => 'string',
                                'description' => "Le mot-clé à rechercher dans la mémoire (ex: 'budget', 'départ', 'allergie').",
                            ],
                        ],
                        'required' => ['recherche'],
                    ],
                ],
            ],
            // 👇 AJOUTE CE BLOC ICI 👇
            [
                'type' => 'function',
                'function' => [
                    'name' => 'rechercher_connaissances',
                    'description' => "Recherche sémantique (RAG) dans la base de connaissances voyage de TripMate : formalités (visa, vaccins), meilleure période, budget, sécurité, transports, culture, conseils pratiques. À utiliser dès que l'utilisateur pose une question pratique ou factuelle sur une destination ou l'organisation d'un voyage.",
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'question' => [
                                'type' => 'string',
                                'description' => "La question ou le sujet à rechercher (ex: 'visa pour le Japon', 'meilleure période Maroc', 'quand réserver son vol', 'budget Bali').",
                            ],
                        ],
                        'required' => ['question'],
                    ],
                ],
            ],
            // 👆 FIN DU BLOC À AJOUTER 👆
            [
                'type' => 'function',
                'function' => [
                    'name' => 'chercher_destinations',
                    'description' => "Suggère des destinations de voyage selon des critères (type de voyage, saison, budget). Utilise une base de connaissances de l'agent — pas d'API externe.",
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'type_voyage' => [
                                'type' => 'string',
                                'description' => "Type de voyage souhaité : 'plage', 'culture', 'aventure', 'gastronomie', 'nature', 'ville'.",
                            ],
                            'saison' => [
                                'type' => 'string',
                                'description' => "Saison du voyage : 'hiver', 'printemps', 'été', 'automne'.",
                            ],
                            'budget_max' => [
                                'type' => 'integer',
                                'description' => 'Budget maximum total en euros par personne (vol + hébergement + activités).',
                            ],
                        ],
                        'required' => ['type_voyage'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'chercher_vols',
                    'description' => "Cherche des vols entre deux villes à une date donnée via l'API Amadeus. Retourne les meilleures offres avec prix, durée, escales.",
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'origine' => [
                                'type' => 'string',
                                'description' => "Ville ou aéroport de départ (ex: 'Paris' ou 'CDG').",
                            ],
                            'destination' => [
                                'type' => 'string',
                                'description' => "Ville ou aéroport d'arrivée (ex: 'Tokyo' ou 'NRT').",
                            ],
                            'date' => [
                                'type' => 'string',
                                'description' => 'Date du vol au format YYYY-MM-DD.',
                            ],
                            'adultes' => [
                                'type' => 'integer',
                                'description' => 'Nombre de passagers adultes (défaut 1).',
                            ],
                        ],
                        'required' => ['origine', 'destination', 'date'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'chercher_attractions',
                    'description' => "Cherche les principales attractions touristiques d'une ville via l'API OpenTripMap (monuments, musées, lieux notables).",
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'ville' => [
                                'type' => 'string',
                                'description' => 'Nom de la ville.',
                            ],
                            'limite' => [
                                'type' => 'integer',
                                'description' => "Nombre d'attractions souhaité (défaut 10, max 30).",
                            ],
                        ],
                        'required' => ['ville'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'creer_itineraire',
                    'description' => "Construit un itinéraire jour par jour pour un voyage à partir d'une liste d'attractions et d'une durée. À utiliser APRÈS avoir appelé chercher_attractions.",
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'destination' => [
                                'type' => 'string',
                                'description' => 'Ville de destination.',
                            ],
                            'jours' => [
                                'type' => 'integer',
                                'description' => 'Nombre de jours du voyage.',
                            ],
                            'attractions' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                                'description' => 'Liste des attractions à répartir sur les jours.',
                            ],
                        ],
                        'required' => ['destination', 'jours', 'attractions'],
                    ],
                ],
            ],
        ];
    }

    /**
     * Implémentations des outils (équivalent du tableau $fonctions du cours).
     * Retourne un dict [nom_outil => callable].
     */
    public function getImplementations(): array
    {
        return [
            // --- Outils mémoire (repris du cours) ---
            'memoriser' => function (array $args): array {
                $memoire = $this->memoire->charger();
                $memoire['faits'][] = [
                    'info' => $args['information'],
                    'date' => date('c'),
                ];
                $this->memoire->sauvegarder($memoire);
                return ['succes' => true, 'message' => 'Information mémorisée.'];
            },

            'rappeler' => function (array $args): array {
                $memoire = $this->memoire->charger();
                $resultats = array_filter(
                    $memoire['faits'],
                    fn($f) => stripos($f['info'], $args['recherche']) !== false
                );
                return [
                    'resultats' => !empty($resultats)
                        ? array_values($resultats)
                        : 'Aucune information trouvée.',
                ];
            },

            'rechercher_connaissances' => function (array $args): array {
                return $this->rag->rechercher($args['question'], 3);
            },

            // --- Outils métier voyage ---
            'chercher_destinations' => function (array $args): array {
                return $this->suggererDestinations(
                    $args['type_voyage'],
                    $args['saison'] ?? null,
                    $args['budget_max'] ?? null
                );
            },

            'chercher_vols' => function (array $args): array {
                return $this->amadeus->chercherVols(
                    $args['origine'],
                    $args['destination'],
                    $args['date'],
                    $args['adultes'] ?? 1
                );
            },

            'chercher_attractions' => function (array $args): array {
                $limite = min((int)($args['limite'] ?? 10), 30);
                return $this->tripMap->chercherAttractions($args['ville'], $limite);
            },

            'creer_itineraire' => function (array $args): array {
                return $this->construireItineraire(
                    $args['destination'],
                    (int) $args['jours'],
                    $args['attractions']
                );
            },
        ];
    }

    /**
     * Suggestions de destinations basées sur une petite base de connaissances interne.
     * On garde ça simple et déterministe : pas besoin d'une API externe pour ça.
     */
    private function suggererDestinations(string $type, ?string $saison, ?int $budget): array
    {
        $catalogue = [
            'plage' => [
                ['ville' => 'Bali', 'pays' => 'Indonésie', 'budget' => 1800, 'meilleures_saisons' => ['printemps', 'été']],
                ['ville' => 'Crète', 'pays' => 'Grèce', 'budget' => 900, 'meilleures_saisons' => ['été', 'printemps']],
                ['ville' => 'Agadir', 'pays' => 'Maroc', 'budget' => 700, 'meilleures_saisons' => ['printemps', 'automne', 'hiver']],
                ['ville' => 'Phuket', 'pays' => 'Thaïlande', 'budget' => 1500, 'meilleures_saisons' => ['hiver', 'printemps']],
            ],
            'culture' => [
                ['ville' => 'Kyoto', 'pays' => 'Japon', 'budget' => 2200, 'meilleures_saisons' => ['printemps', 'automne']],
                ['ville' => 'Rome', 'pays' => 'Italie', 'budget' => 800, 'meilleures_saisons' => ['printemps', 'automne']],
                ['ville' => 'Marrakech', 'pays' => 'Maroc', 'budget' => 600, 'meilleures_saisons' => ['printemps', 'automne', 'hiver']],
                ['ville' => 'Istanbul', 'pays' => 'Turquie', 'budget' => 700, 'meilleures_saisons' => ['printemps', 'automne']],
            ],
            'aventure' => [
                ['ville' => 'Reykjavik', 'pays' => 'Islande', 'budget' => 2000, 'meilleures_saisons' => ['été', 'hiver']],
                ['ville' => 'Cusco', 'pays' => 'Pérou', 'budget' => 2500, 'meilleures_saisons' => ['été', 'automne']],
                ['ville' => 'Queenstown', 'pays' => 'Nouvelle-Zélande', 'budget' => 3000, 'meilleures_saisons' => ['hiver', 'printemps']],
            ],
            'gastronomie' => [
                ['ville' => 'Lyon', 'pays' => 'France', 'budget' => 600, 'meilleures_saisons' => ['printemps', 'automne']],
                ['ville' => 'Bologne', 'pays' => 'Italie', 'budget' => 700, 'meilleures_saisons' => ['printemps', 'automne']],
                ['ville' => 'Bangkok', 'pays' => 'Thaïlande', 'budget' => 1400, 'meilleures_saisons' => ['hiver']],
                ['ville' => 'Lisbonne', 'pays' => 'Portugal', 'budget' => 700, 'meilleures_saisons' => ['printemps', 'automne']],
            ],
            'nature' => [
                ['ville' => 'Banff', 'pays' => 'Canada', 'budget' => 2200, 'meilleures_saisons' => ['été', 'hiver']],
                ['ville' => 'Patagonie', 'pays' => 'Argentine', 'budget' => 2800, 'meilleures_saisons' => ['été']],
                ['ville' => 'Faroe', 'pays' => 'Îles Féroé', 'budget' => 1800, 'meilleures_saisons' => ['été']],
            ],
            'ville' => [
                ['ville' => 'New York', 'pays' => 'USA', 'budget' => 2000, 'meilleures_saisons' => ['printemps', 'automne']],
                ['ville' => 'Tokyo', 'pays' => 'Japon', 'budget' => 2300, 'meilleures_saisons' => ['printemps', 'automne']],
                ['ville' => 'Berlin', 'pays' => 'Allemagne', 'budget' => 600, 'meilleures_saisons' => ['printemps', 'été', 'automne']],
                ['ville' => 'Barcelone', 'pays' => 'Espagne', 'budget' => 700, 'meilleures_saisons' => ['printemps', 'automne']],
            ],
        ];

        $type = strtolower($type);
        if (!isset($catalogue[$type])) {
            return ['erreur' => "Type de voyage inconnu : '$type'. Types valides : " . implode(', ', array_keys($catalogue))];
        }

        $resultats = $catalogue[$type];

        if ($saison) {
            $resultats = array_filter($resultats, fn($d) => in_array(strtolower($saison), $d['meilleures_saisons']));
        }
        if ($budget !== null) {
            $resultats = array_filter($resultats, fn($d) => $d['budget'] <= $budget);
        }

        return array_values($resultats) ?: ['message' => 'Aucune destination ne correspond à ces critères.'];
    }

    /** Répartit les attractions sur les jours du voyage de manière équilibrée. */
    private function construireItineraire(string $destination, int $jours, array $attractions): array
    {
        if ($jours < 1) {
            return ['erreur' => 'Le nombre de jours doit être >= 1'];
        }
        if (empty($attractions)) {
            return ['erreur' => 'Liste d\'attractions vide'];
        }

        $parJour = (int) ceil(count($attractions) / $jours);
        $itineraire = [];
        for ($j = 0; $j < $jours; $j++) {
            $itineraire[] = [
                'jour' => $j + 1,
                'activites' => array_slice($attractions, $j * $parJour, $parJour),
            ];
        }
        return [
            'destination' => $destination,
            'duree_jours' => $jours,
            'itineraire' => $itineraire,
        ];
    }
}
