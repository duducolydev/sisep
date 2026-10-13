#!/usr/bin/env bash
#
# Déploiement de SISEP en production.
#
# Le script est idempotent : relancé après une mise à jour du code, il
# reconstruit les images, applique les migrations manquantes et redémarre.
# Il ne charge les données que si la base est vide, et ne lance JAMAIS le jeu
# de démonstration.
#
#   ./deploy/deployer.sh            # déploiement ou mise à jour
#   ./deploy/deployer.sh --donnees  # + rechargement des classeurs sources
#
# À lancer depuis la racine du dépôt, sur le serveur.

set -euo pipefail

cd "$(dirname "$0")/.."

COMPOSE="docker compose -f docker-compose.prod.yml"
RECHARGER_DONNEES=0
[ "${1:-}" = "--donnees" ] && RECHARGER_DONNEES=1

CLASSEURS="docs/SISEP_-_Système_d’Information_et_de_Suivi-Évaluation_du_PHASAOC"
PTBA="$CLASSEURS/PTBA-2026_Révisé-soumis-DG_17 mars 2026 - v19-05-26.xlsx"
CONTACTS="$CLASSEURS/PHASAOC -  Contacts des Points Focaux 2026.xlsx"
PERSONNEL="$CLASSEURS/Situation du personnel en 2026.xlsx"

etape() { printf '\n\033[1;34m==> %s\033[0m\n' "$1"; }
echec() { printf '\n\033[1;31mERREUR : %s\033[0m\n' "$1" >&2; exit 1; }

# --- Vérifications préalables ---------------------------------------------
# Mieux vaut échouer ici, avant d'avoir rien construit, qu'au milieu d'une
# migration.
[ -f .env ] || echec "Fichier .env absent. Le créer depuis .env.prod.example et le remplir."

# Lit une valeur de .env sans exécuter le fichier. Le sourcer avec bash fait
# interpréter chaque ligne comme une commande : la première valeur contenant
# une espace (« SISEP — PHASAOC ») devient une commande inconnue et le
# script s'arrête. Docker Compose, lui, lit ce fichier comme des données ;
# on fait de même, guillemets englobants retirés comme il le fait.
valeur_env() {
    local ligne
    ligne=$(grep -E "^[[:space:]]*$1=" .env | tail -n 1) || true
    ligne=${ligne#*=}
    ligne=${ligne%$'\r'}
    if [[ ${#ligne} -ge 2 && ( $ligne == \"*\" || $ligne == \'*\' ) ]]; then
        ligne=${ligne:1:${#ligne}-2}
    fi
    printf '%s' "$ligne"
}

for variable in APP_SECRET MYSQL_PASSWORD MYSQL_ROOT_PASSWORD APP_URL_WEB; do
    [ -n "$(valeur_env "$variable")" ] || echec "$variable n'est pas renseignée dans .env."
done

MYSQL_ROOT_PASSWORD=$(valeur_env MYSQL_ROOT_PASSWORD)
APP_URL_WEB=$(valeur_env APP_URL_WEB)
PORT=$(valeur_env SISEP_PORT)
PORT=${PORT:-8080}
if command -v ss >/dev/null 2>&1 && ss -ltn "sport = :$PORT" 2>/dev/null | grep -q LISTEN; then
    if ! $COMPOSE ps --services --filter status=running 2>/dev/null | grep -q web; then
        echec "Le port $PORT est déjà occupé par un autre service. Changer SISEP_PORT dans .env."
    fi
fi

# --- Construction et démarrage --------------------------------------------
etape "Construction des images"
$COMPOSE build

etape "Démarrage des services"
$COMPOSE up -d

etape "Attente de la base"
for _ in $(seq 1 60); do
    if $COMPOSE exec -T db mysqladmin ping -h localhost -u root -p"$MYSQL_ROOT_PASSWORD" >/dev/null 2>&1; then
        break
    fi
    sleep 2
done

etape "Migrations"
$COMPOSE exec -T api php bin/console doctrine:migrations:migrate --no-interaction

# --- Amorçage et données ---------------------------------------------------
# `app:amorcer` se tait si le référentiel est déjà en place : on peut la
# lancer à chaque déploiement sans risque.
etape "Référentiel des droits et compte d'administration"
$COMPOSE exec -T api php bin/console app:amorcer

BASE_VIDE=$($COMPOSE exec -T api php bin/console dbal:run-sql \
    "SELECT COUNT(*) FROM composantes" 2>/dev/null | grep -oE '[0-9]+' | head -1 || echo 0)

if [ "$BASE_VIDE" = "0" ] || [ "$RECHARGER_DONNEES" = "1" ]; then
    etape "Chargement des données du projet"
    $COMPOSE exec -T api php bin/console app:ptba:importer "$PTBA" --annee="$(date +%Y)"
    $COMPOSE exec -T api php bin/console app:points-focaux:importer "$CONTACTS"
    $COMPOSE exec -T api php bin/console app:rh:importer-personnel "$PERSONNEL"
else
    etape "Données déjà présentes — chargement ignoré (relancer avec --donnees pour forcer)"
fi

etape "État des services"
$COMPOSE ps

printf '\n\033[1;32mSISEP est déployé sur %s\033[0m\n' "${APP_URL_WEB}"
printf 'Journaux : %s logs -f api\n' "$COMPOSE"
