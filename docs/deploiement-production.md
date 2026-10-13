# SISEP — Mise en production

Mode opératoire pour installer SISEP sur le serveur `10.7.200.41`, exposé en
HTTP sur le réseau interne de l'ANSD.

Cette procédure a été répétée en local sur la même composition et les mêmes
images : migrations, amorçage, import des trois classeurs, connexion et
lecture de l'arbre. Ce qui suit n'est donc pas théorique.

## 0. Ce que vous installez

Trois conteneurs, **un seul port publié** :

| Service | Rôle | Exposition |
|---|---|---|
| `web` | nginx — sert le front compilé et relaie `/api/` vers PHP-FPM | port de l'hôte (8080 par défaut) |
| `api` | PHP-FPM 8.3, `APP_ENV=prod`, code figé dans l'image | réseau interne uniquement |
| `db` | MySQL 8, volume nommé | réseau interne uniquement |

Le front et l'API partagent la même origine. Il n'y a donc **aucun CORS à
configurer**, et le cookie de session reste first-party. La base n'est
joignable d'aucune machine du réseau : pour l'inspecter, passer par
`docker compose exec db`.

## 1. Préalables sur le serveur

```bash
docker --version && docker compose version   # Docker Engine + plugin compose
sudo ss -ltnp | grep -E ':(8080|80|443)\s'   # quel port est libre ?
```

Le serveur héberge déjà `forum-ansd`. Si le port 8080 est pris, en choisir un
autre à l'étape suivante : rien dans cette installation ne touche aux
services existants.

## 2. Déposer le code

```bash
sudo mkdir -p /opt/sisep && sudo chown "$USER" /opt/sisep
# depuis votre poste, ou par git clone si le dépôt est accessible :
rsync -av --exclude node_modules --exclude var --exclude .git ./ doudou@10.7.200.41:/opt/sisep/
```

Les classeurs sources (`docs/…`) font partie du dépôt : ils doivent être
présents, les commandes d'import les lisent depuis `/app/docs`.

## 3. Configurer

```bash
cd /opt/sisep
cp .env.prod.example .env
chmod 600 .env
nano .env
```

Cinq valeurs sont obligatoires, le déploiement refuse de démarrer sans elles.

| Variable | Comment la produire |
|---|---|
| `MYSQL_PASSWORD` | `openssl rand -base64 24` |
| `MYSQL_ROOT_PASSWORD` | `openssl rand -base64 24`, différent du précédent |
| `APP_SECRET` | `openssl rand -hex 32` |
| `APP_URL_WEB` | `http://10.7.200.41:8080` — repris tel quel dans les liens des courriels |
| `ADMIN_EMAIL` | l'adresse du premier administrateur |

`SISEP_PORT` si 8080 n'est pas libre. `MAILER_DSN` reste à `null://null` tant
que le SMTP n'est pas arrêté : l'application fonctionne, les courriels
partent simplement nulle part.

## 4. Déployer

```bash
./deploy/deployer.sh
```

Le script construit les images, démarre les services, applique les
migrations, amorce le référentiel des droits et charge les trois classeurs si
la base est vide. Il est idempotent : relancé après une mise à jour du code,
il reconstruit et migre sans retoucher aux données.

**Notez le mot de passe temporaire affiché à la fin de l'amorçage.** Il n'est
écrit nulle part et ne sera pas réaffiché. L'application en exigera un
nouveau à la première connexion.

Ouvrir ensuite `http://10.7.200.41:8080`.

## 5. Vérifier

```bash
docker compose -f docker-compose.prod.yml ps          # trois services « Up », db « healthy »
curl -s -o /dev/null -w '%{http_code}\n' http://localhost:8080/           # 200
curl -s -o /dev/null -w '%{http_code}\n' http://localhost:8080/api/v1/projets/courant  # 401 sans session : normal
docker compose -f docker-compose.prod.yml logs -f api
```

À l'issue d'une installation neuve, la base contient : 4 composantes, 11
sous-composantes, 26 activités, 71 sous-activités, 49 dotations pour
10 888 856 938 FCFA notifiés, 26 structures, 47 points focaux et 74 agents.

**Tout s'affichera en « risque élevé »**, et c'est normal : l'exercice est
avancé de 76 % et l'exécution budgétaire réelle n'est pas encore chargée. Les
alertes rentreront dans l'ordre dès que les engagements et les dépenses 2026
seront saisis.

## 6. Mettre à jour

```bash
cd /opt/sisep && git pull        # ou rsync depuis le poste
./deploy/deployer.sh
```

Pour recharger les classeurs après une révision du PTBA :

```bash
./deploy/deployer.sh --donnees
```

Les imports rapprochent par code et par identité : ils mettent à jour sans
dupliquer. Le montant notifié d'une dotation déjà saisie n'est jamais
réécrit ; les écarts sont listés en fin d'exécution.

### Ce qu'une mise à jour conserve

Tout ce qui est saisi dans l'application : comptes utilisateurs, droits,
écritures budgétaires, documents déposés, journal d'activité. La base vit
dans le volume `sisep_prod_db` et les fichiers dans `sisep_prod_uploads` ;
reconstruire et redémarrer les conteneurs ne touche ni à l'un ni à l'autre.
Les migrations ne font qu'ajouter des colonnes ou des tables.

`--donnees` est le seul cas où l'application voit des valeurs remplacées :
les imports réécrivent depuis les classeurs les coordonnées des points
focaux et les fiches du personnel. Ils ne touchent jamais aux comptes.

### Ce qu'il ne faut jamais lancer sur ce serveur

| Commande | Effet |
|---|---|
| `docker compose … down -v` | Le `-v` supprime les volumes : base et fichiers déposés perdus. `down` seul est sans risque. |
| `docker volume rm sisep_sisep_prod_db` | Même effet, explicitement. |
| `php bin/console app:db:seed` | Refusée en production : elle viderait toutes les tables, comptes compris, pour y mettre des données fictives. |

## 7. Sauvegardes

Deux choses à sauvegarder **ensemble**, une base sans ses fichiers étant
inexploitable :

```bash
# Base
docker compose -f docker-compose.prod.yml exec -T db \
  mysqldump -u root -p"$MYSQL_ROOT_PASSWORD" --single-transaction sisep \
  | gzip > /var/backups/sisep-$(date +%F).sql.gz

# Fichiers déposés
docker run --rm -v sisep_sisep_prod_uploads:/data -v /var/backups:/sauvegarde alpine \
  tar czf /sauvegarde/sisep-uploads-$(date +%F).tar.gz -C /data .
```

Le nom du volume dépend du nom du projet compose : le vérifier avec
`docker volume ls | grep sisep`.

## 8. Tâches planifiées

Voir `docs/exploitation.md`, § 2. Sur cette installation, les deux lignes
deviennent :

```
5 2 * * * cd /opt/sisep && docker compose -f docker-compose.prod.yml exec -T api php bin/console app:rapports:generer >> /var/log/sisep/rapports.log 2>&1
0 7 * * * cd /opt/sisep && docker compose -f docker-compose.prod.yml exec -T api php bin/console app:alertes:envoyer >> /var/log/sisep/alertes.log 2>&1
```

La seconde n'envoie rien tant que `MAILER_DSN` vaut `null://null`.

## 9. Ce qui reste à faire après la mise en ligne

- **Charger l'exécution budgétaire 2026** (engagements, dépenses). Sans elle,
  tous les taux d'exécution sont à zéro.
- **Définir les indicateurs** du cadre de résultats : aucun n'est chargé.
- **Définir le gabarit d'étapes** de l'UGP depuis Administration ›
  Paramètres : sans étapes, aucune activité n'a de progression.
- **Créer les comptes** des agents de l'UGP et leur attribuer leurs rôles.
- **Compléter le classeur des contacts** : trois agents sans sexe et six
  opérations non rattachées au PTBA sont signalés par les imports.
- **HTTPS**, le jour où le service sortira du réseau interne. Le cookie de
  session passera en `Secure` automatiquement, sans changement de code.
