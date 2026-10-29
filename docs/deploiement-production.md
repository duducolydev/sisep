# SISEP — Mise en production

Mode opératoire pour installer SISEP sur le serveur `10.7.200.41`, servi en
HTTPS à l'adresse `https://sisephasaoc.ansd.sn` sur le réseau interne de
l'ANSD.

Cette procédure a été répétée en local sur la même composition et les mêmes
images : migrations, amorçage, import des trois classeurs, connexion et
lecture de l'arbre. Ce qui suit n'est donc pas théorique.

**bash deploy/deployer.sh**

## 0. Ce que vous installez

Trois conteneurs, **aucun port ouvert sur le réseau** :

| Service | Rôle | Exposition |
|---|---|---|
| `web` | nginx — sert le front compilé et relaie `/api/` vers PHP-FPM | réseau du frontal (nom `sisep-web`) ; `127.0.0.1:8080` pour les vérifications |
| `api` | PHP-FPM 8.3, `APP_ENV=prod`, code figé dans l'image | réseau interne uniquement |
| `db` | MySQL 8, volume nommé | réseau interne uniquement |

Les utilisateurs n'atteignent pas `web` directement. Sur ce serveur, les
ports 80 et 443 appartiennent au **nginx du forum** (conteneur
`forum-ansd-nginx-1`), qui détient le certificat `*.ansd.sn`. Il termine
TLS et relaie `sisephasaoc.ansd.sn` vers `web` par le réseau Docker de la
composition du forum, auquel `web` se raccorde. Ce nginx appartient au
forum : le bloc qui relaie SISEP vit donc dans le dépôt du forum
(`docker/nginx-sisephasaoc.conf`, monté par son `docker-compose.prod.yml`),
avec le reste de sa configuration (§ 4).

Le front et l'API partagent la même origine. Il n'y a donc **aucun CORS à
configurer**, et le cookie de session reste first-party. La base n'est
joignable d'aucune machine du réseau : pour l'inspecter, passer par
`docker compose exec db`.

## 1. Préalables sur le serveur

```bash
docker --version && docker compose version   # Docker Engine + plugin compose
sudo ss -ltnp | grep -E ':8080\s'            # le port de vérification est-il libre ?
docker ps --format '{{.Names}}\t{{.Ports}}' | grep -E ':(80|443)->'   # le frontal
docker inspect forum-ansd-nginx-1 --format '{{range $k, $v := .NetworkSettings.Networks}}{{println $k}}{{end}}'
```

Le serveur héberge déjà le forum (composition `forum-ansd`). Son conteneur
nginx tient 80 et 443 ; le nginx du système est arrêté et doit le rester,
il se disputerait les mêmes ports. La dernière commande donne le nom du
réseau du frontal, à reporter dans `SISEP_RESEAU_FRONTAL` à l'étape
suivante. Si le port 8080 est pris, en choisir un autre.

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
| `APP_URL_WEB` | `https://sisephasaoc.ansd.sn` — repris tel quel dans les liens des courriels |
| `ADMIN_EMAIL` | l'adresse du premier administrateur |

`SISEP_RESEAU_FRONTAL` si le réseau du frontal ne s'appelle pas
`forum-ansd_default` (§ 1). `SISEP_PORT` si 8080 n'est pas libre. `MAILER_DSN` reste à `null://null` tant
que le SMTP n'est pas arrêté : l'application fonctionne, les courriels
partent simplement nulle part. **Le lien « Mot de passe oublié » en dépend** :
sans SMTP, la demande est acceptée mais aucun lien n'arrive, et le seul
recours reste le mot de passe temporaire attribué par l'administrateur.

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

### Raccorder SISEP au frontal (une seule fois)

Tant que ce n'est pas fait, `sisephasaoc.ansd.sn` affiche le forum : le
nginx du forum ne connaît pas ce nom et le sert par son bloc par défaut.

Le bloc de SISEP est dans le dépôt du forum (`docker/nginx-sisephasaoc.conf`,
§ 5.10 de son `docs/DEPLOIEMENT.md`). Le forum monte ses fichiers nginx un par
un, et non par dossier : prendre en compte un fichier de plus demande de
**recréer** son conteneur nginx, ce qui coupe le portail une à deux secondes.
Un simple rechargement ne suffit pas. Le faire à une heure creuse, et jamais
pendant le Forum (23-25 novembre 2026).

```bash
cd /opt/forum-ansd
git pull --ff-only
# 1. Valider la configuration complète dans un conteneur jetable : le nginx
#    en service n'est pas touché, et un échec ici ne coûte rien au portail.
docker compose -f docker-compose.prod.yml run --rm --no-deps nginx nginx -t
# 2. Recréer nginx : Compose voit le nouveau montage et remplace le conteneur.
docker compose -f docker-compose.prod.yml up -d nginx
# Le serveur ne résout pas forcément ses propres noms : on les force sur
# 127.0.0.1, ce qui interroge le nginx du forum sans passer par le DNS.
curl -s -o /dev/null -w '%{http_code}\n' --resolve forum2026.ansd.sn:443:127.0.0.1 https://forum2026.ansd.sn/   # 200
```

Ouvrir ensuite `https://sisephasaoc.ansd.sn`.

Sur une installation déjà servie par `http://10.7.200.41:8080`, faire ce
raccordement **avant** de relancer `deploy/deployer.sh` : l'ancienne adresse
reste ouverte jusqu'au redéploiement, et `sisephasaoc.ansd.sn` répond 502 en
attendant, sans que le portail en souffre. Après le redéploiement, l'ancienne
adresse ne répond plus depuis le réseau : le port n'est publié que sur la
boucle locale du serveur.

## 5. Vérifier

```bash
docker compose -f docker-compose.prod.yml ps          # trois services « Up », db « healthy »
curl -s -o /dev/null -w '%{http_code}\n' http://localhost:8080/           # 200
curl -s -o /dev/null -w '%{http_code}\n' http://localhost:8080/api/v1/projets/courant  # 401 sans session : normal
curl -s -o /dev/null -w '%{http_code}\n' --resolve sisephasaoc.ansd.sn:443:127.0.0.1 https://sisephasaoc.ansd.sn/api/v1/projets/courant  # 401 : le frontal relaie
curl -sI --resolve sisephasaoc.ansd.sn:80:127.0.0.1 http://sisephasaoc.ansd.sn/ | grep -i '^location'   # https://sisephasaoc.ansd.sn/
docker compose -f docker-compose.prod.yml logs -f api
```

Après une première connexion, le cookie de session doit porter l'attribut
`Secure` (outils de développement du navigateur › Application › Cookies).
S'il ne le porte pas, l'API ne lit pas les en-têtes du frontal : vérifier
`SYMFONY_TRUSTED_PROXIES` dans `docker-compose.prod.yml`.

À l'issue d'une installation neuve, la base contient : 4 composantes, 11
sous-composantes, 26 activités, 71 sous-activités, 49 dotations pour
10 888 856 938 FCFA notifiés, 26 structures, 47 points focaux et 74 agents.

**Tout s'affichera en « risque élevé »**, et c'est normal : l'exercice est
avancé de 76 % et l'exécution budgétaire réelle n'est pas encore chargée. Les
alertes rentreront dans l'ordre dès que les engagements et les dépenses 2026
seront saisis.

## 6. Mettre à jour

Faire d'abord la sauvegarde du § 7 : certaines migrations convertissent des
données, et un retour arrière passe par elle.

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
Les migrations ajoutent surtout des colonnes ou des tables ; quelques-unes
convertissent des valeurs existantes, et le disent dans leur description
(`doctrine:migrations:status` les liste avant application). Ainsi, en
octobre 2026, les empreintes de mot de passe sont masquées dans le journal.

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
# Guillemets simples : $MYSQL_ROOT_PASSWORD est lu dans le conteneur, qui
# l'a dans son environnement ; le shell de l'hôte, lui, ne le connaît pas.
docker compose -f docker-compose.prod.yml exec -T db \
  sh -c 'mysqldump -u root -p"$MYSQL_ROOT_PASSWORD" --single-transaction sisep' \
  | gzip > /var/backups/sisep-$(date +%F).sql.gz

# Fichiers déposés
docker run --rm -v sisep_sisep_prod_uploads:/data -v /var/backups:/sauvegarde alpine \
  tar czf /sauvegarde/sisep-uploads-$(date +%F).tar.gz -C /data .
```

Le nom du volume dépend du nom du projet compose : le vérifier avec
`docker volume ls | grep sisep`.

## 8. Tâches planifiées

Voir `docs/exploitation.md`, § 2. Sans la première tâche, aucun rapport
trimestriel n'est attendu : l'écran « Mes rapports » des points focaux et la
rubrique « Reporting trimestriel » des fiches restent vides.

Le journal va dans le dossier personnel de l'utilisateur qui porte la
crontab, créé par la ligne elle-même : si le dossier de redirection manque ou
n'est pas accessible en écriture (un `/var/log/sisep` créé par `sudo`
appartient à root), le shell ne peut pas ouvrir le fichier et **la tâche ne
se lance pas du tout**, sans erreur visible.

Lancer d'abord les deux commandes à la main, avec le même utilisateur : si
Docker répond « permission denied », les lignes vont dans la crontab de root
(`sudo crontab -e`), pas dans celle de l'utilisateur.

```bash
cd /opt/sisep
docker compose -f docker-compose.prod.yml exec -T api php bin/console app:rapports:generer
docker compose -f docker-compose.prod.yml exec -T api php bin/console app:alertes:envoyer --dry-run
crontab -e
```

Sur cette installation, les deux lignes deviennent :

```
5 2 * * * cd /opt/sisep && mkdir -p "$HOME/logs/sisep" && docker compose -f docker-compose.prod.yml exec -T api php bin/console app:rapports:generer >> "$HOME/logs/sisep/rapports.log" 2>&1
0 7 * * * cd /opt/sisep && mkdir -p "$HOME/logs/sisep" && docker compose -f docker-compose.prod.yml exec -T api php bin/console app:alertes:envoyer >> "$HOME/logs/sisep/alertes.log" 2>&1
```

La seconde envoie de vrais courriels dès que `MAILER_DSN` est configuré :
d'où le `--dry-run` préalable, qui montre ce qui partirait.

Vérifier le lendemain : `crontab -l | grep sisep` et
`tail ~/logs/sisep/rapports.log`, qui doit se terminer par « N rapport(s)
T… généré(s) ». `deploy/deployer.sh` lance aussi `app:rapports:generer` à
chaque déploiement.

## 9. Ce qui reste à faire après la mise en ligne

- **Charger l'exécution budgétaire 2026** (engagements, dépenses). Sans elle,
  tous les taux d'exécution sont à zéro.
- **Définir les indicateurs** du cadre de résultats : aucun n'est chargé.
- **Définir le gabarit d'étapes** de l'UGP depuis Administration ›
  Paramètres : sans étapes, aucune activité n'a de progression.
- **Créer les comptes** des agents de l'UGP et leur attribuer leurs rôles.
- **Compléter le classeur des contacts** : trois agents sans sexe et six
  opérations non rattachées au PTBA sont signalés par les imports.
- **Renouveler le certificat `*.ansd.sn` avant le 20/11/2026.** Il est
  commun au forum et à SISEP, et c'est la DSI qui le gère. Une fois les
  fichiers remplacés, un `nginx -s reload` du frontal suffit aux deux sites.
  Le frontal annonce HSTS : un certificat expiré bloque l'accès, sans
  possibilité de passer outre.
- **Accès hors du réseau de l'ANSD.** `sisephasaoc.ansd.sn` n'existe que
  dans le DNS interne : un point focal hors du réseau ne l'atteint pas.
  L'ouvrir demande un enregistrement DNS public et un renvoi du port 443 vers
  `10.7.200.41` (DSI). Avant cela, il faudra ajouter une limitation des
  tentatives de connexion, absente aujourd'hui.
