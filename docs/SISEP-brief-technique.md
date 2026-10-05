# SISEP — Brief technique de mise en œuvre

**Système d'Information et de Suivi-Évaluation du PHASAOC**
Agence Nationale de la Statistique et de la Démographie (ANSD) — Dakar, Sénégal

Document destiné à Claude Code. Version 1 — août 2026.

---

## 0. À trancher avant de lancer le développement

Trois décisions ne sont pas prises dans ce document. Elles conditionnent la structure du dépôt et doivent être arbitrées avant la première commande.

| Décision | Recommandation de ce brief | Pourquoi ça compte |
|---|---|---|
| **Langage du backend** | NestJS (Node 22 + TypeScript) + Prisma | React et MySQL sont fixés, le backend ne l'est pas. NestJS partage le langage du front (une seule chaîne d'outils, types partagés). Si l'équipe DSID est plutôt PHP, Laravel 11 + Eloquent tient exactement le même contrat d'API — dans ce cas, remplacer § 6 et § 7 sans toucher au reste. |
| **Hébergement** | Datacenter souverain, conteneurs Docker | Conditionne la stratégie de sauvegarde et les exports. À confirmer avec Sénégal Numérique SA. |
| **Périmètre mono ou multi-projet** | Multi-projet dès le schéma (table `projets`), mono-projet dans l'IHM | Le coût est nul maintenant, très élevé plus tard. Le PHASAOC n'est pas le seul projet que l'ANSD aura à suivre. |

Le reste du document suppose la recommandation retenue.

---

## 1. Contexte

Le PHASAOC (Projet d'Harmonisation et d'Amélioration des Statistiques en Afrique de l'Ouest et du Centre) est un projet régional financé par la Banque mondiale, piloté au Sénégal par l'ANSD. Il finance plusieurs opérations, accompagne des structures partenaires et suit leurs activités.

SISEP est la plateforme de suivi-évaluation de ce projet. Elle doit permettre à la coordination, aux directions de l'ANSD et aux structures partenaires de suivre en temps réel :

- la structure du projet sur quatre niveaux : composante → sous-composante → activité → sous-activité ;
- l'exécution budgétaire : dotation notifiée, engagements, dépenses, solde disponible ;
- les indicateurs de performance du cadre de résultats ;
- les structures parties prenantes et leurs points focaux ;
- l'administration : référentiels, rôles et permissions, journal d'activité.

**Utilisateurs cibles.** Une soixantaine de comptes : coordination (2), suivi-évaluation (3), gestion budgétaire (4), points focaux de structures (≈18), lecteurs (≈37). Volumétrie faible — quelques milliers de lignes par table. La difficulté n'est pas la charge, c'est la justesse des règles de calcul et la traçabilité.

**Contraintes de contexte.** Connexions parfois lentes depuis les services régionaux : viser un premier rendu utile en moins de 3 s sur une connexion 3G, pagination systématique, pas de dépendance à des CDN externes en production (polices auto-hébergées).

---

## 2. Périmètre fonctionnel

Six modules, correspondant aux six écrans de la maquette fournie.

### 2.1 Tableau de bord
Vue consolidée : quatre indicateurs clés (notifié, engagé, exécuté, disponible), arbre d'exécution repliable, courbe de décaissement cumulé comparée à la trajectoire prévue, points d'attention, extrait du cadre de résultats, activité récente.

### 2.2 Composantes
Arbre complet sur quatre niveaux. Filtres : composante, statut, structure responsable, recherche plein texte. Actions : tout déplier / replier, créer une activité, ouvrir la fiche d'une activité. Le budget est porté par l'**activité** ; les sous-activités portent l'**avancement physique** (pourcentage) et l'échéance.

### 2.3 Budget
Synthèse par composante, table détaillée de toutes les activités (notifié, engagé, dépensé, disponible, taux d'exécution), filtre par composante, courbe de décaissement, classement des activités les moins exécutées. Actions : saisir un engagement, saisir une dépense, exporter.

### 2.4 Indicateurs
Liste des indicateurs d'objectif de développement (IOD) et de résultat intermédiaire (IRI), avec référence, valeur actuelle, cible, courbe d'évolution et taux d'atteinte. Fiche détaillée avec série historique et désagrégations. Actions : définir un indicateur, saisir une valeur périodique.

### 2.5 Parties prenantes
Fiches des structures (directions et cellules ANSD, partenaires nationaux et techniques, école de formation, bailleur), table des points focaux, état du reporting trimestriel. Actions : ajouter / modifier une structure, relancer les retardataires.

### 2.6 Administration
Quatre onglets : référentiel des indicateurs, CRUD des parties prenantes, matrice des rôles et permissions, journal d'activité.

---

## 3. Stack et conventions

### 3.1 Composants

| Couche | Choix | Version cible |
|---|---|---|
| Front | React + TypeScript, Vite | React 19, Vite 6 |
| Routage | React Router (data router) | v7 |
| État serveur | TanStack Query | v5 |
| Formulaires | react-hook-form + zod | — |
| Styles | Tailwind CSS v4, tokens en variables CSS | v4 |
| Graphiques | SVG maison pour barres et sparklines, Recharts pour la courbe | — |
| Tables | TanStack Table (tri, pagination, colonnes) | v8 |
| Backend | NestJS + Prisma | Nest 11, Prisma 6 |
| Base | MySQL | 8.0+, `utf8mb4_0900_ai_ci` |
| Auth | Session par cookie `httpOnly` + `SameSite=Lax` | — |
| Tests | Vitest (front), Jest + Supertest (API), Playwright (E2E) | — |

Ne pas introduire de bibliothèque de composants UI généraliste (MUI, Ant Design, Chakra). La maquette définit déjà son propre système ; une bibliothèque tierce le contredirait et alourdirait le bundle. Pour les primitives accessibles qui coûtent cher à écrire (dialogue modale, menu, onglets), utiliser Radix UI **sans styles**, habillé avec les tokens du § 9.

### 3.2 Structure du dépôt

```
sisep/
├── apps/
│   ├── api/                  # NestJS
│   │   ├── src/
│   │   │   ├── modules/      # un module par domaine métier
│   │   │   │   ├── auth/  projets/  structures/  composantes/
│   │   │   │   ├── activites/  budget/  indicateurs/
│   │   │   │   ├── rapports/  admin/  journal/
│   │   │   ├── common/       # guards, interceptors, filtres, pipes
│   │   │   └── prisma/
│   │   └── prisma/
│   │       ├── schema.prisma
│   │       ├── migrations/
│   │       └── seed.ts
│   └── web/                  # React
│       ├── src/
│       │   ├── pages/        # une page par module (§ 2)
│       │   ├── components/   # inventaire du § 9.4
│       │   ├── features/     # logique métier par domaine
│       │   ├── lib/          # client API, formatage, permissions
│       │   └── styles/
├── packages/
│   └── shared/               # types et schémas zod partagés API ↔ front
└── docker-compose.yml
```

### 3.3 Conventions

- **Langue.** Interface, libellés, messages d'erreur et commentaires en français. Noms de tables, colonnes, variables et fonctions en français aussi (`sous_composantes`, `montantEngage`) — le domaine est francophone, traduire crée des ambiguïtés. Mots-clés techniques en anglais (`createdAt` reste `createdAt` si Prisma l'impose, sinon `cree_le`).
- **Montants.** Toujours en **FCFA entiers**, jamais de flottant. Colonnes `BIGINT`. Le franc CFA n'a pas de subdivision utilisée. Formatage à l'affichage uniquement, via `Intl.NumberFormat('fr-FR')`.
- **Dates.** `DATE` pour les dates métier, `DATETIME(3)` UTC pour l'horodatage technique. Fuseau d'affichage `Africa/Dakar` (UTC+0, pas d'heure d'été — mais ne pas coder cette hypothèse en dur).
- **Identifiants.** Clé primaire `BIGINT UNSIGNED AUTO_INCREMENT` interne, plus un `uuid` `CHAR(36)` unique exposé dans les URL et l'API. Ne jamais exposer l'auto-increment.
- **Codes métier.** Les codes hiérarchiques (`C1`, `1.2`, `1.2.3`) sont des données saisies, pas des identifiants. Ils peuvent changer. Unicité par parent, pas globale.
- **Suppression.** Suppression logique (`supprime_le DATETIME NULL`) partout où une référence budgétaire ou historique existe. Suppression physique interdite sur activités, engagements, dépenses, valeurs d'indicateurs.

---

## 4. Modèle de données MySQL

### 4.1 Référentiel projet

```sql
projets (
  id, uuid, code, libelle, bailleur, devise DEFAULT 'XOF',
  date_debut DATE, date_fin DATE, actif BOOLEAN,
  cree_le, modifie_le
)

exercices (
  id, uuid, projet_id FK, annee SMALLINT,
  date_debut DATE, date_fin DATE,
  statut ENUM('ouvert','cloture') DEFAULT 'ouvert',
  UNIQUE (projet_id, annee)
)
```

L'exercice porte la clôture comptable. Une fois `cloture`, aucune écriture budgétaire n'est plus acceptée dessus — règle à implémenter côté API, pas seulement côté IHM.

### 4.2 Structure hiérarchique

```sql
composantes (
  id, uuid, projet_id FK, code VARCHAR(10), libelle VARCHAR(255),
  description TEXT, structure_pilote_id FK NULL, ordre SMALLINT,
  supprime_le DATETIME NULL,
  UNIQUE (projet_id, code, supprime_le)
)

sous_composantes (
  id, uuid, composante_id FK, code, libelle, description,
  structure_pilote_id FK NULL, ordre, supprime_le,
  UNIQUE (composante_id, code, supprime_le)
)

activites (
  id, uuid, sous_composante_id FK, code, libelle, description,
  structure_responsable_id FK,
  date_debut DATE NULL, date_fin_prevue DATE NULL, date_fin_reelle DATE NULL,
  statut ENUM('planifiee','en_cours','terminee','suspendue','annulee'),
  ordre, supprime_le,
  UNIQUE (sous_composante_id, code, supprime_le),
  INDEX (structure_responsable_id), INDEX (statut)
)

sous_activites (
  id, uuid, activite_id FK, libelle,
  structure_responsable_id FK NULL,
  date_debut DATE NULL, echeance DATE NULL,
  statut ENUM('planifiee','en_cours','terminee','suspendue','annulee'),
  avancement TINYINT UNSIGNED DEFAULT 0,   -- 0 à 100
  ordre, supprime_le,
  CHECK (avancement BETWEEN 0 AND 100)
)
```

**Point d'attention.** Le champ `statut` de la maquette mélange deux notions : un statut de cycle de vie saisi par l'utilisateur (planifiée, en cours, terminée) et un état de vigilance calculé (en retard, risque élevé). Le schéma sépare les deux : `statut` est saisi, la vigilance est calculée (§ 5.3) et n'est jamais stockée.

### 4.3 Budget

```sql
dotations (
  id, uuid, activite_id FK, exercice_id FK,
  montant BIGINT UNSIGNED,
  cree_par FK, cree_le, modifie_le,
  UNIQUE (activite_id, exercice_id)
)

engagements (
  id, uuid, activite_id FK, exercice_id FK,
  reference_marche VARCHAR(80) NULL,
  nature ENUM('travaux','fournitures','prestation_intellectuelle','fonctionnement'),
  montant BIGINT UNSIGNED,
  date_engagement DATE,
  statut ENUM('brouillon','valide','annule') DEFAULT 'brouillon',
  observations TEXT NULL,
  cree_par FK, valide_par FK NULL, valide_le DATETIME NULL,
  supprime_le DATETIME NULL,
  INDEX (activite_id, exercice_id, statut)
)

depenses (
  id, uuid, engagement_id FK,
  montant BIGINT UNSIGNED,
  date_depense DATE,
  reference_paiement VARCHAR(80) NULL,
  statut ENUM('brouillon','valide','annule') DEFAULT 'brouillon',
  cree_par FK, valide_par FK NULL, valide_le DATETIME NULL,
  supprime_le DATETIME NULL,
  INDEX (engagement_id, statut)
)
```

**Décision structurante.** La dépense est rattachée à l'**engagement**, pas directement à l'activité. C'est ce qui rend impossible de décaisser plus qu'on n'a engagé, et c'est le comportement attendu en gestion fiduciaire de projet Banque mondiale. Le chaînage dotation → engagement → dépense doit être respecté à l'écriture.

### 4.4 Indicateurs

```sql
indicateurs (
  id, uuid, projet_id FK, code VARCHAR(20), libelle VARCHAR(255),
  precision_libelle VARCHAR(255) NULL,
  type ENUM('IOD','IRI'),
  composante_id FK NULL,
  unite VARCHAR(40),
  sens ENUM('croissant','decroissant'),
  valeur_reference DECIMAL(18,4), date_reference DATE,
  valeur_cible DECIMAL(18,4), date_cible DATE,
  frequence ENUM('mensuelle','trimestrielle','semestrielle','annuelle'),
  source VARCHAR(160),
  structure_responsable_id FK NULL,
  actif BOOLEAN DEFAULT TRUE, supprime_le,
  UNIQUE (projet_id, code, supprime_le)
)

indicateur_valeurs (
  id, uuid, indicateur_id FK,
  periode DATE,                     -- premier jour de la période observée
  valeur DECIMAL(18,4),
  commentaire TEXT NULL,
  statut ENUM('brouillon','valide') DEFAULT 'brouillon',
  saisi_par FK, valide_par FK NULL, valide_le DATETIME NULL,
  UNIQUE (indicateur_id, periode)
)

indicateur_desagregations (
  id, indicateur_valeur_id FK,
  dimension VARCHAR(40),            -- ex. 'sexe', 'region'
  modalite VARCHAR(80),             -- ex. 'femmes'
  valeur DECIMAL(18,4)
)
```

`DECIMAL` et non `BIGINT` ici : un indicateur peut valoir 8,5 mois ou 98,2 %.

### 4.5 Acteurs, comptes et droits

```sql
structures (
  id, uuid, sigle VARCHAR(20), denomination VARCHAR(255),
  type ENUM('chef_de_file','direction_ansd','cellule_ansd','partenaire_national',
            'partenaire_technique','partenaire_formation','bailleur'),
  telephone VARCHAR(40) NULL, email VARCHAR(160) NULL, adresse VARCHAR(255) NULL,
  actif BOOLEAN, supprime_le,
  UNIQUE (sigle, supprime_le)
)

utilisateurs (
  id, uuid, email UNIQUE, mot_de_passe_hash,
  nom, prenom, fonction VARCHAR(120) NULL,
  structure_id FK NULL,
  actif BOOLEAN, doit_changer_mdp BOOLEAN DEFAULT TRUE,
  derniere_connexion DATETIME NULL,
  cree_le, modifie_le, supprime_le
)

points_focaux (
  id, uuid, structure_id FK, utilisateur_id FK NULL,
  nom_complet VARCHAR(160), fonction VARCHAR(160),
  telephone VARCHAR(40) NULL, email VARCHAR(160) NULL,
  principal BOOLEAN DEFAULT FALSE,
  date_designation DATE NULL, actif BOOLEAN
)

roles (id, code UNIQUE, libelle, description, systeme BOOLEAN)
permissions (id, code UNIQUE, groupe VARCHAR(60), libelle)
role_permissions (role_id FK, permission_id FK, PRIMARY KEY (role_id, permission_id))

utilisateur_roles (
  utilisateur_id FK, role_id FK,
  structure_id FK NULL,          -- portée : NULL = global
  PRIMARY KEY (utilisateur_id, role_id, structure_id)
)
```

**Le point que la maquette n'a pas traité.** La matrice rôle × permission ne suffit pas : un point focal de la DPEE doit pouvoir modifier les activités **de sa structure**, pas toutes. D'où la colonne `structure_id` sur `utilisateur_roles`, qui donne la portée. Voir § 7.2.

### 4.6 Suivi, pièces et traçabilité

```sql
realisations (
  id, uuid, sous_activite_id FK,
  avancement TINYINT UNSIGNED, date_constat DATE,
  commentaire TEXT NULL, cree_par FK, cree_le,
  INDEX (sous_activite_id, date_constat)
)

rapports_periodiques (
  id, uuid, structure_id FK, exercice_id FK,
  periode ENUM('T1','T2','T3','T4'),
  statut ENUM('attendu','transmis','valide','rejete'),
  date_echeance DATE, date_transmission DATETIME NULL,
  piece_jointe_id FK NULL, commentaire TEXT NULL,
  UNIQUE (structure_id, exercice_id, periode)
)

pieces_jointes (
  id, uuid, entite_type VARCHAR(40), entite_id BIGINT,
  nom_fichier VARCHAR(255), chemin VARCHAR(500),
  mime VARCHAR(120), taille_octets BIGINT,
  cree_par FK, cree_le,
  INDEX (entite_type, entite_id)
)

journal_activite (
  id, utilisateur_id FK NULL, action VARCHAR(60),
  entite_type VARCHAR(40), entite_id BIGINT NULL,
  avant JSON NULL, apres JSON NULL,
  adresse_ip VARCHAR(45), agent VARCHAR(255),
  cree_le DATETIME(3),
  INDEX (entite_type, entite_id), INDEX (cree_le)
)
```

`realisations` est l'historique ; `sous_activites.avancement` est la valeur courante, mise à jour par trigger applicatif à chaque insertion. Garder les deux : l'historique permet la courbe d'avancement, la valeur courante évite une agrégation à chaque affichage.

### 4.7 Vues d'agrégation

L'arbre affiche à chaque niveau la somme des niveaux inférieurs. Créer trois vues SQL plutôt que d'agréger en JavaScript :

```sql
CREATE VIEW v_execution_activite AS
SELECT a.id AS activite_id, d.exercice_id,
       COALESCE(d.montant,0) AS notifie,
       COALESCE(SUM(DISTINCT e.montant),0) AS engage,   -- attention, voir note
       COALESCE(dep.total,0) AS depense
FROM activites a ...
```

**Attention au piège classique.** Une jointure directe `activites → engagements → depenses` avec deux `SUM()` multiplie les montants d'engagement par le nombre de dépenses associées. Agréger les dépenses dans une sous-requête séparée, ou utiliser deux CTE distinctes puis les joindre. Écrire un test qui vérifie qu'une activité avec 1 engagement et 3 dépenses affiche bien le montant d'engagement une seule fois — c'est le bug qui passe inaperçu le plus longtemps.

Puis `v_execution_sous_composante` et `v_execution_composante` par remontée. N'inclure que les engagements et dépenses en statut `valide`.

---

## 5. Règles métier

### 5.1 Chaînage budgétaire

À la validation d'un engagement :

```
SOMME(engagements validés de l'activité pour l'exercice) + montant ≤ dotation de l'activité pour l'exercice
```

À la validation d'une dépense :

```
SOMME(dépenses validées de l'engagement) + montant ≤ montant de l'engagement
```

Ces deux contrôles sont **serveur**, dans une transaction avec verrou (`SELECT ... FOR UPDATE` sur la dotation, respectivement sur l'engagement). Les rejouer côté front pour le confort, jamais pour la sécurité.

Grandeurs dérivées, jamais stockées :

```
disponible        = notifie − engage
taux_engagement   = engage / notifie
taux_execution    = depense / notifie
taux_consommation = depense / engage        (montre les engagements dormants)
```

Division par zéro : retourner `null`, pas `0`. Une activité sans dotation n'a pas un taux de 0 %, elle n'a pas de taux. L'IHM affiche un tiret.

### 5.2 Taux d'atteinte d'un indicateur

```
sens croissant   : (actuel − reference) / (cible − reference)
sens décroissant : (reference − actuel) / (reference − cible)
```

Borner l'affichage à 100 % mais **conserver la valeur réelle** en base et dans l'API : un indicateur à 130 % de sa cible est une information de pilotage, pas une anomalie à écraser. Si `cible = reference`, retourner `null`.

### 5.3 Vigilance (calculée, non stockée)

```
taux_attendu = jours écoulés dans l'exercice / jours totaux de l'exercice
ecart        = taux_execution − taux_attendu

ecart ≥ −0,10                          → normal
−0,20 ≤ ecart < −0,10                  → en retard
ecart < −0,20                          → risque élevé
échéance dépassée et statut ≠ terminee → risque élevé (prioritaire sur le reste)
```

Les seuils sont des paramètres, pas des constantes : les mettre dans une table `parametres` éditable depuis l'administration. La coordination voudra les ajuster.

### 5.4 Avancement physique

L'avancement d'une activité est la moyenne des avancements de ses sous-activités. Pondérée si une pondération est saisie, simple sinon. Une activité sans sous-activité porte son avancement directement.

Ne pas confondre avec le taux d'exécution budgétaire : une activité peut être achevée à 100 % et décaissée à 60 % (factures en attente). Les afficher côte à côte, jamais fusionnés.

### 5.5 Reporting trimestriel

Génération automatique des lignes `rapports_periodiques` à l'ouverture de chaque trimestre, pour chaque structure active, avec `statut = 'attendu'` et une échéance paramétrable (par défaut J+15 après la fin du trimestre). Tâche planifiée quotidienne pour basculer en retard et alimenter les relances.

---

## 6. API REST

Base : `/api/v1`. JSON uniquement. Erreurs au format RFC 7807 (`application/problem+json`).

### 6.1 Conventions

- Pagination : `?page=1&taille=25`, réponse `{ donnees: [...], pagination: { page, taille, total, pages } }`.
- Tri : `?tri=code&ordre=asc`.
- Filtres : paramètres nommés (`?composante=C1&statut=en_cours&structure=DSID&q=recensement`).
- Toutes les mutations renvoient l'entité complète après écriture.
- Idempotence sur les créations sensibles (engagements, dépenses) via en-tête `Idempotency-Key`.

### 6.2 Points d'entrée

```
POST   /auth/connexion                 { email, motDePasse } → cookie de session
POST   /auth/deconnexion
GET    /auth/moi                       → profil + rôles + permissions effectives
POST   /auth/mot-de-passe

GET    /projets/:uuid/synthese?exercice=  → cartes du tableau de bord
GET    /projets/:uuid/arbre?exercice=&niveaux=3|4
                                          → arbre complet avec agrégats
GET    /projets/:uuid/decaissement?exercice=
                                          → série mensuelle réalisée + trajectoire
GET    /projets/:uuid/alertes             → points d'attention calculés

GET    /composantes?projet=&exercice=
POST   /composantes
GET    /composantes/:uuid
PATCH  /composantes/:uuid
DELETE /composantes/:uuid                 (logique)

... idem /sous-composantes, /activites, /sous-activites

GET    /activites/:uuid                   → fiche complète : dotation, engagements,
                                            dépenses, sous-activités, pièces jointes
POST   /sous-activites/:uuid/realisations { avancement, dateConstat, commentaire }

GET    /budget/execution?exercice=&composante=&niveau=activite|sous_composante|composante
POST   /dotations                        { activite, exercice, montant }
GET    /engagements?activite=&exercice=&statut=
POST   /engagements
POST   /engagements/:uuid/valider
POST   /engagements/:uuid/annuler
POST   /depenses
POST   /depenses/:uuid/valider

GET    /indicateurs?projet=&type=&composante=
POST   /indicateurs
PATCH  /indicateurs/:uuid
GET    /indicateurs/:uuid/valeurs
POST   /indicateurs/:uuid/valeurs        { periode, valeur, desagregations[] }
POST   /indicateurs/valeurs/:uuid/valider

GET    /structures
POST   /structures
PATCH  /structures/:uuid
GET    /structures/:uuid/activites
GET    /points-focaux
POST   /points-focaux

GET    /rapports?exercice=&periode=&statut=
POST   /rapports/:uuid/transmettre
POST   /rapports/relancer                { structures: [uuid] }

GET    /admin/roles
GET    /admin/permissions
PUT    /admin/roles/:uuid/permissions    { permissions: [code] }
GET    /admin/utilisateurs
POST   /admin/utilisateurs
PATCH  /admin/utilisateurs/:uuid
GET    /admin/parametres
PUT    /admin/parametres
GET    /journal?entite=&utilisateur=&du=&au=

GET    /exports/budget.xlsx?exercice=&composante=
GET    /exports/cadre-resultats.xlsx?exercice=
GET    /exports/rapport-trimestriel.pdf?exercice=&periode=
```

**Un endpoint mérite une attention particulière : `/arbre`.** Il renvoie l'arbre entier avec agrégats à chaque niveau. Tentant de le paginer — ne pas le faire, l'arbre complet fait quelques dizaines de kilo-octets et l'IHM en a besoin d'un bloc pour le filtrage instantané. Le mettre en cache côté serveur avec invalidation sur toute écriture budgétaire.

---

## 7. Authentification et droits

### 7.1 Authentification

Session serveur, cookie `httpOnly` + `Secure` + `SameSite=Lax`, durée 8 h glissante. Hachage Argon2id. Verrouillage après 8 échecs sur 15 min. Pas de JWT en localStorage.

Prévoir dès le départ un point d'extension pour un futur SSO ANSD (OIDC) : isoler la stratégie d'authentification derrière une interface.

### 7.2 Rôles, permissions et portée

Cinq rôles initiaux : `coordonnateur`, `responsable_suivi_evaluation`, `gestionnaire_budgetaire`, `point_focal_structure`, `lecteur`.

Permissions par code, groupées : `activites.consulter`, `activites.creer`, `activites.modifier`, `activites.supprimer`, `budget.engagement.saisir`, `budget.engagement.valider`, `budget.depense.saisir`, `budget.depense.valider`, `indicateurs.definir`, `indicateurs.saisir`, `indicateurs.valider`, `structures.gerer`, `points_focaux.gerer`, `admin.roles`, `admin.parametres`, `journal.consulter`, `exports.generer`.

**La portée s'ajoute à la permission.** Un garde `@RequierePermission('activites.modifier')` vérifie que l'utilisateur possède la permission ; un second niveau vérifie la portée :

```
si l'attribution du rôle a structure_id = NULL  → portée globale
sinon                                            → l'entité visée doit appartenir
                                                   à cette structure
```

Implémenter la portée comme un filtre injecté dans les requêtes Prisma, pas comme une vérification après lecture — sinon une liste paginée fuite des lignes que l'utilisateur ne devrait pas compter.

Séparer systématiquement `saisir` et `valider` : la personne qui saisit un engagement ne doit pas pouvoir le valider. C'est une exigence de contrôle interne sur un projet financé par bailleur.

### 7.3 Journal

Journaliser toute écriture sur : activités, dotations, engagements, dépenses, indicateurs, valeurs d'indicateurs, structures, rôles, permissions, comptes. Stocker `avant` et `apres` en JSON. Interception globale NestJS, pas d'appel manuel dans chaque service.

---

## 8. Front React

### 8.1 Routes

```
/                          → redirection vers /tableau-de-bord
/connexion
/tableau-de-bord
/composantes               ?composante=&statut=&structure=&q=
/composantes/activites/:uuid
/budget                    ?exercice=&composante=
/indicateurs               ?type=&composante=
/indicateurs/:uuid
/parties-prenantes
/parties-prenantes/:uuid
/administration/indicateurs
/administration/parties-prenantes
/administration/roles
/administration/journal
```

**Deux corrections par rapport à la maquette.** D'abord, l'état des filtres et des onglets vit dans l'URL, pas dans un `useState` : un lien vers « les activités à risque de la DSID » doit être partageable. Ensuite, la fiche d'activité devient une **route à part entière** et non un tiroir latéral — elle porte l'historique des engagements, les dépenses et les pièces jointes, ce que le tiroir ne peut pas tenir. Le tiroir reste pour les aperçus rapides (structure, indicateur).

### 8.2 Chargement et erreurs

- Squelettes de chargement, jamais de spinner plein écran après le premier rendu.
- Mise à jour optimiste sur les actions courtes (avancement, bascule de permission), avec retour arrière en cas d'échec.
- Erreur serveur : bandeau non bloquant + conservation de la saisie. Ne jamais vider un formulaire sur erreur.
- Toute action d'écriture confirme par la notification déjà présente dans la maquette.

### 8.3 Accessibilité

Cible RGAA niveau AA. Points concrets : navigation clavier complète sur l'arbre (flèches pour déplier/replier), `aria-expanded` sur les nœuds, `role="switch"` sur la matrice de permissions, contraste vérifié sur les barres d'exécution, focus visible conservé (la maquette le gère déjà), libellés associés à chaque champ.

---

## 9. Système de design

### 9.1 Fichier de référence

`sisep-template-bleu.html` — maquette navigable complète, six pages, déjà recolorée en bleu. C'est la source de vérité visuelle. Elle contient les six écrans, les états, les composants et les interactions attendues.

Deux bugs corrigés dans cette version, à ne pas réintroduire : les volets d'onglets sans règle `display:none`, et un appel de fonction avant l'initialisation de sa variable qui interrompait le script au chargement quand l'URL contenait une ancre.

### 9.2 Jetons de couleur

À porter tels quels dans `@theme` (Tailwind v4).

| Jeton | Valeur | Usage |
|---|---|---|
| `--bg` | `#F3EFE6` | Fond de page (sable) |
| `--surface` | `#FFFDF9` | Cartes, panneaux |
| `--border` | `#E3DCCC` | Filets, séparateurs |
| `--nested` | `#FCFAF5` | Fond des niveaux imbriqués de l'arbre |
| `--hover` | `#FAF7F0` | Survol de ligne |
| `--text` | `#0E1F2E` | Texte principal (bleu nuit) |
| `--muted` | `#6E6A60` | Texte secondaire |
| `--eyebrow` | `#B8802E` | Surtitres (ocre) |
| `--track` | `#DCD5C5` | Fond des barres de progression |
| `--accent` | `#155E93` | Bleu institutionnel — actions, dépensé |
| `--accent-d` | `#0F4C78` | Bleu survol |
| `--accent-bg` | `#E3EDF5` | Fond d'accent léger |
| `--accent-clair` | `#4A90C2` | Engagé non décaissé, dégradés |
| `--ok` / `--ok-bg` | `#155E93` / `#E3EDF5` | Statut favorable |
| `--warn` / `--warn-bg` | `#8A5E15` / `#FBF0DE` | Vigilance |
| `--bad` / `--bad-bg` | `#8C3A2B` / `#F7EAE6` | Alerte, repère de rythme attendu |

L'ocre des surtitres et la brique des alertes sont conservés : ils tranchent avec le bleu et portent le sens. Ne pas les bleuir.

### 9.3 Typographie et formes

| Rôle | Police | Usage |
|---|---|---|
| Titres | Bricolage Grotesque, 800, `-0.02em` | H1 à H3, valeurs des cartes |
| Texte | Public Sans, 400–700 | Corps, libellés, boutons |
| Chiffres | IBM Plex Mono, tabulaire | Montants, taux, codes, dates |

**Auto-héberger les trois polices** (woff2, `font-display: swap`). La maquette pointe vers Google Fonts ; c'est acceptable pour une maquette, pas pour une application institutionnelle.

Rayons : 8 px (contrôles), 10 px (cartes), 5 px (barres). Ombre unique : `0 1px 0 rgba(14,31,46,.05), 0 8px 22px -18px rgba(14,31,46,.5)`.

Tout montant, taux, code hiérarchique ou date s'affiche en chiffres tabulaires. Les colonnes de nombres s'alignent à droite. Sans exception — c'est ce qui rend les tables lisibles en diagonale.

### 9.4 Inventaire des composants

À extraire de la maquette, dans cet ordre (les premiers sont réutilisés partout) :

| Composant | Rôle | Extrait de |
|---|---|---|
| `BarreExecution` | Barre superposée dépensé / engagé / disponible + repère de rythme | `.bar` |
| `Pastille` | Statut coloré avec point | `.chip`, `.dot` |
| `CarteIndicateurCle` | Grand chiffre + libellé + variation | `.kpi` |
| `Carte` | Panneau avec en-tête et corps | `.card` |
| `TableDonnees` | Table triable, paginée, lignes cliquables | `.tbl` |
| `ArbreExecution` | Arbre 4 niveaux repliable, colonnes alignées | `.tree` |
| `BarreFiltres` | Groupe de filtres + compteur de résultats | `.filters` |
| `Tiroir` | Panneau latéral de détail | `.drawer` |
| `Modale` | Dialogue de saisie | `.modal` |
| `SousOnglets` | Onglets internes de page | `.subtabs` |
| `MatricePermissions` | Grille rôle × permission avec interrupteurs | `.matrix`, `.sw` |
| `Sparkline` / `CourbeCumul` | Séries d'indicateurs et de décaissement | `.spark`, `courbe()` |
| `Notification` | Confirmation d'action | `.toast` |
| `Alerte` | Point d'attention | `.alert` |

Chaque composant est développé avec ses états (vide, chargement, erreur, désactivé) avant d'être utilisé dans une page.

---

## 10. Phases de livraison

Chaque phase est livrable et démontrable. Ne pas passer à la suivante sans les critères d'acceptation.

**P0 — Fondations.** Monorepo, Docker Compose (MySQL + API + web), schéma Prisma complet, migrations, jeu de données de démonstration issu de la maquette (4 composantes, 9 sous-composantes, 29 activités, 78 sous-activités, 12 indicateurs, 12 structures), CI (lint, types, tests).
*Accepté quand* : `docker compose up` donne une base peuplée et deux applications qui démarrent.

**P1 — Authentification et droits.** Comptes, rôles, permissions, portée par structure, journal. Écran de connexion, garde de route, affichage conditionnel selon permissions.
*Accepté quand* : un point focal DPEE ne voit ni ne modifie les activités de la DSID, y compris en appelant l'API directement.

**P2 — Structure du projet.** CRUD composantes, sous-composantes, activités, sous-activités. Page Composantes complète avec arbre, filtres dans l'URL, fiche d'activité.
*Accepté quand* : l'arbre affiche les mêmes agrégats que la maquette sur le jeu de démonstration, au franc près.

**P3 — Budget.** Dotations, engagements, dépenses, workflow saisie/validation, contrôles de plafond, page Budget, courbe de décaissement.
*Accepté quand* : les tentatives d'engagement au-delà de la dotation et de dépense au-delà de l'engagement sont refusées côté API, avec un message explicite ; les vues d'agrégation passent le test du § 4.7.

**P4 — Indicateurs.** Référentiel, saisie périodique, désagrégations, taux d'atteinte, page Indicateurs et fiche.
*Accepté quand* : les deux sens de variation donnent le bon taux, y compris au-delà de 100 % et sur `cible = reference`.

**P5 — Tableau de bord.** Synthèse, arbre condensé, alertes calculées, extrait du cadre de résultats, activité récente.
*Accepté quand* : les alertes se recalculent après une écriture budgétaire, sans rechargement complet.

**P6 — Parties prenantes et administration.** Structures, points focaux, reporting trimestriel et relances, les quatre onglets d'administration, paramètres de seuils.
*Accepté quand* : modifier un seuil de vigilance change immédiatement les statuts affichés.

**P7 — Exports et finition.** Exports xlsx et pdf, accessibilité, performance, sauvegardes, documentation d'exploitation.
*Accepté quand* : le rapport trimestriel s'exporte en PDF conforme au format attendu par la coordination.

---

## 11. Hors périmètre de la version 1

À nommer explicitement pour éviter les malentendus avec la coordination : plan de passation des marchés détaillé, comptabilité analytique, gestion documentaire avancée (versionnage), application mobile, notifications par courriel automatisées au-delà des relances de reporting, tableau de bord public.

Le multi-projet est dans le schéma mais pas dans l'interface : une seule ligne dans `projets`, pas de sélecteur.

---

## 12. Fichier CLAUDE.md à placer à la racine

```markdown
# SISEP — instructions de travail

## Contexte
Plateforme de suivi-évaluation du projet PHASAOC (ANSD, Sénégal).
Brief complet : docs/brief-technique.md. Maquette de référence :
docs/sisep-template-bleu.html.

## Règles non négociables
- Montants en FCFA entiers (BIGINT). Jamais de flottant sur un montant.
- Dépense rattachée à un engagement, engagement rattaché à une dotation.
  Contrôles de plafond côté serveur, en transaction avec verrou.
- Suppression logique sur toute entité budgétaire ou historique.
- Séparer saisie et validation : jamais la même permission.
- Portée par structure en plus de la permission, appliquée comme filtre
  de requête et non comme contrôle après lecture.
- Interface, libellés et noms de colonnes en français.
- Chiffres en police tabulaire, colonnes numériques alignées à droite.

## Ce qu'il ne faut pas faire
- Pas de bibliothèque de composants UI généraliste.
- Pas de JWT en localStorage.
- Pas de CDN externe en production, polices auto-hébergées.
- Ne pas stocker les grandeurs dérivées (disponible, taux, vigilance).
- Ne pas paginer l'endpoint /arbre.

## Commandes
pnpm dev / pnpm test / pnpm lint / pnpm db:migrate / pnpm db:seed

## Avant de proposer un commit
Types, lint et tests passent. Toute règle métier nouvelle est couverte
par un test. Toute écriture est journalisée.
```

---

## 13. Prompt de démarrage

À coller dans Claude Code, avec `brief-technique.md` et `sisep-template-bleu.html` placés dans `docs/`.

> Lis `docs/brief-technique.md` en entier, puis ouvre `docs/sisep-template-bleu.html` pour comprendre le système de design et le comportement attendu de chaque écran.
>
> Commence par la phase P0 uniquement : monorepo pnpm avec `apps/api` (NestJS + Prisma) et `apps/web` (Vite + React + TypeScript), `docker-compose.yml` avec MySQL 8, schéma Prisma complet conforme au § 4, migration initiale, et script de seed reprenant intégralement le jeu de données de démonstration de la maquette.
>
> Avant d'écrire le schéma, liste-moi les points du § 4 sur lesquels tu as un doute ou une objection — notamment le chaînage dotation/engagement/dépense et la portée par structure. Je préfère trancher maintenant que migrer plus tard.
>
> Ne commence pas les écrans tant que P0 n'est pas validée.

Cette dernière consigne compte : le modèle de données est ce qui coûte le plus cher à corriger après coup.
