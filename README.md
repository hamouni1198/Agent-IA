# TripMate — Assistant IA de planification de voyage

TripMate est un **agent IA conversationnel** qui aide l'utilisateur à organiser ses voyages : choix de destination, recherche de vols réels, attractions touristiques, itinéraires jour par jour et conseils pratiques. Il s'appuie sur une **boucle d'agent autonome** capable d'enchaîner plusieurs appels au LLM et d'utiliser des outils (tools) pour aller chercher de vraies informations.

---

##  Thématique choisie

**Voyage.** L'agent joue le rôle d'un assistant de planification de voyage francophone. Il ne réserve rien : il fournit de l'aide à la décision (suggestions, comparaisons, organisation) en s'appuyant sur des APIs réelles et une base de connaissances interne, jamais sur des informations inventées.

---

##  Fonctionnalités

- **Boucle d'agent autonome** avec limite de sécurité (`MAX_ITERATIONS = 8`) : le LLM peut enchaîner plusieurs appels et plusieurs outils avant de produire sa réponse finale.
- **7 outils** appelables de manière autonome par l'agent (voir plus bas).
- **Mémoire persistante** : l'agent retient les préférences de l'utilisateur entre les conversations (fichier JSON rechargé à chaque démarrage).
- **System prompt soigné** : identité, capacités, limites, ton et format clairement définis.
- **Interface web** (HTML / CSS / JS) : chat complet avec gestion de sessions, rendu markdown léger, indicateur de frappe et bouton « nouvelle conversation ».
- ** Bonus — Streaming des réponses** : la réponse de l'agent s'affiche **mot par mot** en temps réel (Server-Sent Events).
- ** Bonus — RAG (Retrieval-Augmented Generation)** : recherche **sémantique** (embeddings + similarité cosinus) dans une base de connaissances voyage, dont les extraits pertinents sont injectés dans le contexte du modèle.

---

##  Stack technique

| Côté | Technologies |
|------|--------------|
| Backend | PHP 8, architecture orientée objet (namespaces `TripMate`) |
| LLM | OpenAI `gpt-4o-mini` (chat + tool calling + streaming) |
| Embeddings (RAG) | OpenAI `text-embedding-3-small` |
| APIs externes | Amadeus (vols), OpenTripMap (attractions) |
| Dépendances | `openai-php/client`, `guzzlehttp/guzzle`, `vlucas/phpdotenv` (via Composer) |
| Frontend | HTML / CSS / JavaScript (vanilla, fetch + lecture de flux SSE) |
| Persistance | Fichiers JSON (`data/`) |

---

##  Prérequis

- **PHP 8.1+** (avec les extensions `curl` et `mbstring`)
- **Composer**
- Une clé API **OpenAI**
- (Optionnel selon les outils utilisés) des clés **Amadeus** et **OpenTripMap**

---

##  Installation

```bash

cd agant-IA


composer install
```

### Configuration des clés API

Créez un fichier **`.env`** à la racine du projet :

```env
OPENAI_API_KEY=sk-xxxxxxxxxxxxxxxxxxxx

# Pour l'outil de recherche de vols (API Amadeus Self-Service, environnement test)
AMADEUS_API_KEY=xxxxxxxxxxxxxxxx
AMADEUS_API_SECRET=xxxxxxxxxxxxxxxx

# Pour l'outil de recherche d'attractions (API OpenTripMap)
OPENTRIPMAP_API_KEY=xxxxxxxxxxxxxxxx
```

> Seule `OPENAI_API_KEY` est indispensable au démarrage. Les autres clés sont vérifiées au moment de l'appel à l'outil correspondant.

### Construire l'index RAG (obligatoire pour l'outil de recherche de connaissances)

Le RAG s'appuie sur les fichiers du dossier `data/knowledge/`. Il faut les indexer **une fois** (et à chaque modification de ces fichiers) :

```bash
php indexer-rag.php
```

Vous devez voir ` X chunks indexés → data/rag-index.json`.

---

## ▶️ Lancement

### Option A — Serveur intégré PHP (recommandé, le streaming fonctionne sans config)

```bash
php -S localhost:8000 -t public
```

Puis ouvrez **http://localhost:8000** dans votre navigateur.

### Option B — MAMP / Apache

Placez le projet dans `htdocs/` et accédez à `http://localhost:<port>/agant-IA/public/`.
*(Note : Apache bufferise parfois la sortie ; si le streaming s'affiche d'un coup, désactivez la compression gzip pour cette route.)*

---

##  Liste des outils implémentés

L'agent dispose de **7 outils** qu'il décide d'appeler de manière autonome selon le besoin :

| Outil | Description |
|-------|-------------|
| `memoriser` | Sauvegarde une information durable sur l'utilisateur (préférences de voyage, ville de départ habituelle, budget, allergies…) dans la mémoire persistante. |
| `rappeler` | Recherche dans la mémoire une information précédemment sauvegardée sur l'utilisateur. |
| `chercher_destinations` | Suggère des destinations selon le **type de voyage** (plage, culture, aventure, gastronomie, nature, ville), la **saison** et le **budget**, à partir d'un catalogue interne. |
| `chercher_vols` | Recherche des **vols réels** entre deux villes à une date donnée via l'**API Amadeus** (prix, durée, escales, compagnies). Convertit automatiquement les noms de villes en codes IATA. |
| `chercher_attractions` | Trouve les principales **attractions touristiques** d'une ville (monuments, musées, lieux notables) via l'**API OpenTripMap**. |
| `creer_itineraire` | Construit un **itinéraire jour par jour** en répartissant une liste d'attractions sur la durée du séjour. À utiliser après `chercher_attractions`. |
| `rechercher_connaissances` | **(RAG)** Recherche **sémantique** (embeddings + similarité cosinus) dans la base de connaissances voyage : formalités/visa, vaccins, meilleure période, budget, sécurité, transports, culture, conseils pratiques. |

---

##  Structure du projet

```
agant-IA/
├── public/
│   ├── index.html          # Interface web
│   ├── style.css           # Styles
│   ├── script.js           # Logique front + lecture du flux SSE
│   └── chat.php            # Endpoint API (POST streaming / GET historique / DELETE reset)
├── src/
│   ├── Agent.php           # Boucle d'agent + system prompt (versions normale et streaming)
│   ├── Tools.php           # Schémas + implémentations des 7 outils
│   ├── Rag.php             # Moteur RAG (indexation + recherche sémantique)
│   ├── Memoire.php         # Mémoire persistante (faits utilisateur)
│   ├── Session.php         # Historique de conversation par session
│   ├── bootstrap.php       # Autoload + .env + client OpenAI partagé
│   └── Apis/
│       ├── Amadeus.php     # Client API vols
│       └── OpenTripMap.php # Client API attractions
├── data/
│   ├── knowledge/          # Base de connaissances RAG (.md)
│   ├── rag-index.json      # Index d'embeddings (généré par indexer-rag.php)
│   ├── memoire-agent.json  # Mémoire persistante (généré au fil de l'usage)
│   └── sessions/           # Historiques de conversation
├── indexer-rag.php         # Script CLI d'indexation du RAG
├── composer.json
└── .env                    # Clés API (à créer)
```

---

## 👥 Membres du projet

| Nom | Rôle |
|-----|------|
| *À compléter* | *À compléter* |
| *À compléter* | *À compléter* |



---

## 📝 Notes

- L'agent ne réserve **rien** et n'invente jamais de prix : il utilise toujours les outils pour les données réelles.
- La mémoire et les sessions sont stockées en clair dans `data/` (projet pédagogique).
- Pensez à **régénérer l'index RAG** (`php indexer-rag.php`) si vous modifiez les fichiers de `data/knowledge/`.# agent-ia-
