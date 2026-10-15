# SISEP — instructions de travail

## Contexte
Plateforme de suivi-évaluation du projet PHASAOC (ANSD, Sénégal).
Brief complet : `docs/SISEP-brief-technique.md`. Maquette de référence :
`docs/sisep-template-bleu.html`.

Backend Symfony 7.4 + Doctrine ORM + MySQL 8 (`apps/api`), front React 19 +
Vite + TypeScript (`apps/web`), monorepo pnpm (`packages/shared`).

## Règles non négociables
- Montants en FCFA entiers (`BIGINT UNSIGNED`). Jamais de flottant sur un
  montant — utiliser `App\ValueObject\Montant` et le type Doctrine
  `montant`, jamais un `int`/`string` brut dans le code métier.
- Dépense rattachée à un engagement, engagement rattaché à une dotation.
  Contrôles de plafond côté serveur, en transaction avec verrou pessimiste
  sur la ligne de dotation/engagement concernée (§ 5.1 du brief).
- Suppression logique (`supprime_le`) sur toute entité budgétaire ou
  historique. Suppression physique interdite sur activités, engagements,
  dépenses, valeurs d'indicateurs.
- Séparer saisie et validation : jamais le même rôle ne détient
  `budget.*.saisir` et `budget.*.valider` (§ 7.2). **Deux dérogations
  assumées**, décidées après la réunion du 22/09/2026 : les rôles
  `administrateur` et `responsable_suivi_evaluation` cumulent les deux.
  Trois contreparties, toutes en place : le serveur refuse qu'une même
  personne valide l'écriture qu'elle a saisie, quelles que soient ses
  permissions (`DepenseValidationService`, `EngagementValidationService`) ;
  `journal_activite` intercepte toute écriture sur dotations, engagements et
  dépenses avec l'état avant et après ; et ce fil est consultable depuis
  l'écran du budget (`GET /journal/budget`), pas seulement dans
  l'administration. Aucun autre rôle ne peut cumuler les deux : le
  gestionnaire budgétaire saisit sans valider, le coordonnateur valide sans
  saisir.
- Portée par structure en plus de la permission (`UtilisateurRole.structure`),
  appliquée comme filtre de requête (Doctrine SQL Filter) et non comme
  contrôle après lecture.
- Interface, libellés et noms de colonnes en français.
- Chiffres en police tabulaire (IBM Plex Mono), colonnes numériques alignées
  à droite.
- Grandeurs dérivées (disponible, taux d'exécution, vigilance) jamais
  stockées, toujours calculées à la demande. Division par zéro → `null`,
  jamais `0`.

## Ce qu'il ne faut pas faire
- Pas de bibliothèque de composants UI généraliste (MUI, Ant Design, Chakra).
- Pas de JWT en localStorage — session par cookie `httpOnly` + `Secure` +
  `SameSite=Lax` (Symfony Security).
- Pas de CDN externe en production, polices auto-hébergées (`@fontsource/*`).
- Ne pas paginer l'endpoint `/arbre`.
- Ne pas utiliser MySQL `ENUM` natif pour les statuts métier : `VARCHAR` +
  enum PHP natif (`enumType:` sur la colonne) + contrainte `CHECK` ajoutée à
  la main dans la migration (décision actée, § 4 du brief).
- Ne pas réintroduire la saisie de réalisations (retirée au CR du
  22/09/2026) : l'avancement se renseigne étape par étape
  (`EtapeActivite`). Depuis les retours des RSE (octobre 2026), chaque ligne
  du PTBA (sous-activité) porte ses propres étapes : progression d'une ligne
  = moyenne de ses étapes ; progression d'une activité = moyenne de ses
  blocs (chaque ligne structurée, plus ses étapes propres comptées comme un
  bloc) — `App\Activite\ProgressionActiviteCalculator` sur une entité, la
  même règle en SQL dans `ArbreReader`. `sous_activite.avancement`,
  `Realisation` et `RealisationListener` ne subsistent que pour les données
  historiques : rien n'y écrit plus. La sous-activité (« ligne
  d'activité » à l'écran) est la maille budgétaire du PTBA, créée par l'UGP
  (`activites.creer`).

## Commandes

```
pnpm dev / pnpm test / pnpm build / pnpm lint     # apps/web
docker compose up                                  # MySQL + API + web

cd apps/api
composer install
php bin/console doctrine:migrations:migrate        # migrations
php bin/console app:db:seed [--force]               # jeu de données de démo
php bin/phpunit                                     # tests
php vendor/bin/phpstan analyse --memory-limit=512M  # types
php vendor/bin/php-cs-fixer fix                     # lint (--dry-run --diff pour vérifier sans corriger)
```

## Avant de proposer un commit
`php-cs-fixer`, PHPStan et PHPUnit passent côté `apps/api` ; `pnpm lint`,
`pnpm build` (type-check) et `pnpm test` passent côté `apps/web`. Toute
règle métier nouvelle est couverte par un test. Toute écriture sensible
(budget, droits) est journalisée (`journal_activite`).
