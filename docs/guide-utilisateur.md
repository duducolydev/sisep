# Guide d'utilisation du SISEP

**Système de suivi-évaluation du projet PHASAOC — ANSD**

Version d'octobre 2026, à jour de la présentation au PHASAOC du 22 octobre 2026.

Ce guide s'adresse à toutes les personnes qui utilisent le SISEP : l'équipe de coordination de l'UGP, les responsables du suivi-évaluation, les gestionnaires budgétaires, les points focaux des structures bénéficiaires et les administrateurs de la plateforme. Il est organisé par écran, dans l'ordre du menu. Chacun n'y trouve que les boutons que son profil autorise : si une action décrite ici n'apparaît pas à l'écran, c'est que votre profil ne la permet pas (voir la partie 1.4).

## Nouveautés d'octobre 2026

- Les **gabarits d'étapes** s'appellent désormais **modèles d'étapes** (menu Administration).
- L'**arbre du PTBA se gère depuis l'écran Composantes** : créer, modifier et archiver une composante, une sous-composante ou une activité, sans passer par l'équipe technique.
- **Natures d'engagement** : « Fournitures de biens » devient « Fournitures », et « Travaux » devient « Travaux (construction, réaménagement…) ».
- **Documents et justificatifs** : leur intitulé et leur type se corrigent après le dépôt, sans retirer le fichier.
- **Indicateurs de performance** : l'évolution s'affiche en **diagramme en barres**, avec la cible en ligne de repère.
- **Indicateurs** : le Responsable suivi-évaluation valide les valeurs saisies par les sectoriels (jamais sa propre saisie).
- **Comptes** : les rôles d'un compte se modifient directement dans « Modifier un compte », et l'administrateur y attribue un nouveau mot de passe temporaire en cas d'oubli.
- **Parties prenantes** : une structure se modifie depuis sa fiche, et ses points focaux s'y ajoutent, s'y modifient et s'y désactivent.

## 1. Premiers pas

### 1.1 Se connecter

1. Ouvrez l'adresse du SISEP communiquée par l'UGP dans votre navigateur (Chrome, Edge ou Firefox récents).
2. Saisissez votre adresse e-mail et votre mot de passe, puis cliquez sur **Se connecter**.
3. **Première connexion** : votre compte a été créé avec un mot de passe temporaire. L'application vous demande aussitôt d'en choisir un nouveau : saisissez le mot de passe temporaire, puis deux fois le nouveau.

**Mot de passe oublié** : il n'y a pas de réinitialisation automatique. Contactez l'administrateur SISEP de l'UGP ; il vous attribuera un nouveau mot de passe temporaire, envoyé par courriel, que vous changerez à la connexion suivante (voir la partie 14.1).

> Ne communiquez jamais votre mot de passe, y compris à l'équipe technique. Personne n'en a besoin pour vous aider.

### 1.2 L'écran

- Le **menu**, à gauche, regroupe les écrans en deux blocs : **Pilotage** (tableau de bord, composantes, budget, exécution, indicateurs, projets, documents) et **Acteurs et paramètres** (parties prenantes, ressources humaines, administration). Le bouton **Replier** le réduit aux seules icônes.
- Votre **nom et votre profil** figurent en bas du menu : un clic ouvre **Mon profil**.
- La plupart des écrans ont des **filtres** en haut (composante, structure, statut, exercice, recherche) et un bouton **Exporter** pour récupérer la liste affichée dans un fichier Excel.

### 1.3 Mon profil

L'écran **Mon profil** affiche vos informations, vos rôles (avec la structure concernée le cas échéant) et permet de **changer votre mot de passe** (rubrique Sécurité).

### 1.4 Les profils et ce qu'ils permettent

Chaque compte reçoit un ou plusieurs rôles. Un rôle peut valoir pour tout le projet ou pour une seule structure : un point focal de la DSID, par exemple, ne voit et ne renseigne que les activités dont la DSID est responsable.

| Profil | Ce qu'il permet |
|---|---|
| Administrateur | Tout. Rôle d'administration de la plateforme, à ne pas confier à une personne qui traite elle-même les écritures budgétaires. |
| Coordonnateur | Consulter et gérer l'arbre du PTBA (y compris archiver), valider la clôture des activités, **valider** les engagements, les dépenses et les valeurs d'indicateurs (sans les saisir), gérer les structures, les points focaux, les comptes, les rôles et les paramètres, consulter le journal, exporter, suivre les projets, les ressources humaines et les documents. |
| Responsable suivi-évaluation | Créer et modifier les activités, valider leur clôture, **saisir et valider** les dotations, engagements et dépenses, définir les indicateurs, **saisir et valider** leurs valeurs, inviter un sectoriel, consulter le journal, exporter, suivre les projets, les ressources humaines et les documents. |
| Gestionnaire budgétaire | Consulter les activités et les indicateurs, **saisir** les dotations, engagements et dépenses (sans les valider), exporter. |
| Point focal de structure | Consulter les activités de sa structure, ainsi que celles dont une ligne est confiée à sa structure, et renseigner l'avancement de ce qui relève de sa structure (étapes, justificatifs) ; tenir à jour les points focaux de sa structure. Il saisit aussi les valeurs des indicateurs auxquels il a été invité. Ce rôle s'attribue toujours pour une structure précise. |
| Lecteur | Consulter les activités et les indicateurs. |

**Séparation de la saisie et de la validation.** Une écriture budgétaire (engagement, dépense) ou une valeur d'indicateur est saisie par une personne et validée par une autre. Les profils Administrateur et Responsable suivi-évaluation peuvent faire les deux, mais **jamais sur la même écriture** : l'application refuse que la personne qui a saisi un engagement, une dépense ou une valeur la valide elle-même. Toute écriture est enregistrée dans le journal, avec son état avant et après.

### 1.5 Conventions d'affichage

- Les **montants** sont en francs CFA entiers, sans décimales.
- Les **taux** (exécution, engagement, atteinte, progression) sont toujours calculés à partir des données du moment, jamais saisis. Quand un taux ne peut pas être calculé (par exemple sans budget notifié), l'écran affiche « — » plutôt que 0 %.
- Les **chiffres** sont alignés à droite dans les tableaux, pour se comparer d'un coup d'œil.

## 2. Tableau de bord

C'est l'écran d'accueil. Il donne la situation du projet en un coup d'œil :

- **Synthèse du projet** : budget notifié, engagements, dépenses exécutées et solde disponible.
- **Points d'attention** : les activités en retard ou à risque, au regard du rythme attendu à la date du jour.
- **Décaissement cumulé** au fil des mois.
- **Arbre d'exécution** : l'avancement de chaque composante.
- **Cadre de résultats** : la situation des indicateurs de performance.
- **Activité récente** : les dernières opérations enregistrées.

Le bouton **Exporter** produit la synthèse au format Excel.

## 3. Composantes : l'arbre du PTBA

L'écran **Composantes** présente le PTBA sous forme d'arbre : composante → sous-composante → activité → ligne d'activité. Deux graphiques résument l'exécution budgétaire et la progression des étapes par composante.

### 3.1 Lire l'arbre

Sur chaque ligne, une barre montre :

- le **dépensé** (bleu foncé), l'**engagé non décaissé** (bleu clair) et le **disponible** (beige), sur le budget notifié ;
- le **taux d'exécution**, à droite ;
- un trait vertical rouge pour le **rythme attendu** à la date du jour : une barre qui reste en deçà signale un retard.

Cliquez sur le triangle d'une ligne pour la déplier, ou utilisez les boutons en haut à droite de l'arbre pour tout déplier ou tout replier. L'icône en forme d'œil ouvre la fiche d'une activité.

### 3.2 Filtrer et rechercher

Les filtres restreignent l'arbre à une composante, un statut, une structure responsable ou un exercice. La zone **Rechercher** trouve une activité ou une ligne d'activité par son code ou son nom (par exemple « RNOCB ») : l'arbre se déplie seul pour montrer les résultats.

### 3.3 Gérer l'arbre

Selon votre profil, des boutons apparaissent en bout de ligne :

| Bouton | Effet |
|---|---|
| **Nouvelle composante** (en haut de l'écran) | Crée une composante : code, libellé, description. |
| **+** sur une composante | Ajoute une sous-composante. Le code est prérempli (par exemple « 2. »). |
| **+** sur une sous-composante | Ouvre **Créer une activité**. |
| **Crayon** | Modifie le code, le libellé, la description ; pour une activité, aussi la structure responsable. |
| **Corbeille** | Archive l'élément après confirmation. |

Règles à connaître :

- **Changer la structure responsable** d'une activité change qui la voit et qui la renseigne : réservé à l'UGP.
- **L'archivage ne détruit rien** : l'élément quitte l'arbre mais reste en base et dans le journal. Il est refusé tant qu'une composante contient des sous-composantes, qu'une sous-composante contient des activités, ou qu'une activité porte une dotation ou un engagement. Archivez d'abord les éléments qu'elle contient.
- Seuls le Coordonnateur et l'Administrateur peuvent archiver.

### 3.4 Créer une activité

Le bouton **Créer une activité** demande la sous-composante de rattachement, le code, le libellé et la structure responsable. Les lignes d'activité, qui portent le budget, s'ajoutent ensuite depuis la fiche de l'activité.

## 4. La fiche d'une activité

La fiche réunit tout ce qui concerne une activité : ses lignes, leur avancement et leur budget. On y accède depuis l'arbre des composantes, l'écran Exécution ou une recherche.

### 4.1 Les lignes d'activité

Une **ligne d'activité** est la maille budgétaire du PTBA : c'est elle qui reçoit les dotations et les engagements. L'UGP en ajoute avec **Ajouter une ligne d'activité**.

### 4.2 Renseigner l'avancement par étapes

L'avancement ne se saisit pas en pourcentage global : il se renseigne **étape par étape**. Chaque ligne d'activité porte ses propres étapes ; l'activité peut aussi avoir des étapes qui lui sont propres.

**Structurer les étapes** (UGP) :

1. Dans la rubrique des étapes, choisissez un **modèle d'étapes** (« Cycle standard d'une activité », « Enquête ou recensement », « Acquisition (passation de marché) », « Atelier ou formation »…) puis cliquez sur **Appliquer le modèle**. Les étapes du modèle sont créées d'un coup.
2. Ou ajoutez une étape à la main, avec son libellé.
3. Une étape peut porter des **livrables** (par exemple « Questionnaires imprimés », avec une quantité attendue).

**Lignes confiées à une autre direction.** Une ligne peut relever d'une autre structure que l'activité (la fiche l'indique : « Ligne confiée à CSI »). Le point focal de cette structure trouve alors l'activité dans son arbre et renseigne les étapes de sa ligne, et d'elle seule ; le reste de l'activité lui est visible en lecture.

**Renseigner une étape** (point focal ou UGP) : dépliez **Étapes** sur la ligne, cliquez sur **Renseigner l'étape** et indiquez :

- le **niveau atteint** en pourcentage ;
- la **date de mise à jour** ;
- la **valeur actuelle** si l'étape a un livrable chiffré (optionnel) ;
- une **observation** : difficultés rencontrées, précisions (optionnel).

**Joindre un justificatif** : le bouton **Joindre un justificatif** demande le type de document (rapport, procès-verbal, liste de présence, photo…, ou « Autre » avec une précision) et le fichier, 10 Mo au plus.

**Comment se calcule la progression** :

- la progression d'une ligne est la moyenne de ses étapes ;
- la progression d'une activité est la moyenne de ses lignes, les étapes propres de l'activité comptant comme une ligne de plus.

### 4.3 Le budget de l'activité

La rubrique **Exécution budgétaire** suit, pour chaque ligne, la chaîne dotation → engagement → dépense.

1. **Notifier une dotation** : le montant notifié pour l'exercice. Une révision validée en COPIL s'affiche à côté (« Validé COPIL »).
2. **Saisir un engagement** : exercice, nature, montant en FCFA, date d'engagement et, le cas échéant, référence du marché. Les natures proposées :
   - **Travaux (construction, réaménagement…)**, fournitures, acquisition de biens, service de consultation, service courant ;
   - regroupées sous **Fonctionnement** : salaire, frais de mission, atelier, remboursement de transport, autres natures de fonctionnement.
3. **Valider** ou **Annuler** l'engagement, tant qu'il est en brouillon. Un engagement validé ne s'annule plus et ne se supprime jamais.
4. Sur un engagement validé, **Saisir une dépense**, puis la faire valider de la même façon.

Le serveur vérifie chaque écriture, au moment de l'enregistrer :

- le total engagé ne peut pas dépasser la dotation notifiée (« Le montant engagé atteindrait …, au-delà de la dotation notifiée ») ;
- le total décaissé ne peut pas dépasser le montant engagé ;
- la personne qui a saisi une écriture ne peut pas la valider ;
- un exercice clôturé n'accepte plus aucune écriture.

### 4.4 Clôturer une activité

Quand une activité est achevée, le Coordonnateur ou le Responsable suivi-évaluation clique sur **Marquer terminée**, en haut de la fiche.

## 5. Budget

L'écran **Budget** consolide l'exécution financière du projet :

- l'**exécution financière pluriannuelle** et le **décaissement cumulé** ;
- la **synthèse par composante** ;
- les **activités les moins exécutées**, pour cibler les relances ;
- **toutes les activités**, avec leurs montants notifiés, engagés et dépensés.

Pour les profils qui consultent le journal, le **fil des écritures budgétaires** indique qui a saisi, modifié ou validé chaque dotation, engagement et dépense, avec les montants avant et après.

## 6. Exécution

L'écran **Exécution** rapproche, ligne par ligne, l'**exécution physique** (l'avancement des étapes) et l'**exécution financière** (le dépensé sur le notifié). Il répond à la question : « l'argent dépensé correspond-il au travail fait ? ».

- Les indicateurs en tête donnent l'exécution physique, l'exécution financière, le taux d'engagement et l'avancement moyen des étapes.
- Les filtres restreignent à une composante, une activité ou une période (mois ou trimestre) ; la recherche trouve une ligne par son code ou son nom.
- Le bouton **Exporter** produit le tableau au format Excel.

## 7. Indicateurs de performance

### 7.1 La liste et la fiche

L'écran **Indicateurs de performance** présente le cadre de résultats : chaque indicateur avec sa valeur de référence, sa valeur actuelle, sa cible et son taux d'atteinte. Un clic ouvre sa fiche :

- **Progression** : valeur de référence, valeur actuelle, cible et taux d'atteinte. Une valeur encore en attente de validation est signalée comme telle, et le taux est alors « provisoire ».
- **Évolution** : un diagramme en barres, une barre par valeur. Les boutons **Par saisie**, **Par trimestre** et **Par année** regroupent les valeurs (pour un trimestre ou une année, c'est la dernière valeur de la période qui compte : un indicateur est un niveau, pas un cumul). Une **barre hachurée** est en attente de validation ; la **ligne pointillée** marque la cible. Le survol d'une barre affiche sa valeur exacte.
- **Série historique** : toutes les valeurs saisies, filtrables par période, avec leur statut.

### 7.2 Saisir une valeur

1. Sur la fiche, cliquez sur **Saisir une valeur**.
2. Indiquez la période, puis la valeur : soit la **valeur absolue** atteinte, soit un **ajustement** (+ ou −) par rapport à la dernière valeur.
3. Ajoutez si besoin les **désagrégations** (par sexe, par région…) et un commentaire.
4. Joignez si besoin un justificatif, avec son type (10 Mo au plus).

La valeur est enregistrée en brouillon : elle compte aussitôt dans l'affichage, marquée « en attente de validation ».

### 7.3 Valider une valeur

Le Responsable suivi-évaluation — ou le Coordonnateur — valide les valeurs en attente depuis la série historique de l'indicateur (bouton **Valider**). Chaque valeur en attente indique qui l'a saisie. La valeur validée devient la valeur officielle de l'indicateur : valeur actuelle, taux d'atteinte, évolution, tableau de bord.

Personne ne valide sa propre saisie : une valeur saisie (ou corrigée) par un Responsable suivi-évaluation est validée par un autre Responsable suivi-évaluation ou par le Coordonnateur ; le bouton **Valider** ne lui est pas proposé. Une valeur corrigée après validation repasse en attente.

### 7.4 Inviter un sectoriel

Pour qu'un point focal renseigne un indicateur, cliquez sur **Inviter un sectoriel** dans la rubrique **Accès** de la fiche. La liste propose :

- les comptes qui ont le rôle Point focal de structure ;
- les comptes rattachés à la fiche d'un point focal actif (voir la partie 10).

La personne invitée voit et renseigne cet indicateur seulement. L'invitation se révoque depuis la même rubrique.

### 7.5 Définir un indicateur

Le catalogue se gère dans **Administration > Indicateurs** : code, libellé, définition, unité, source, fréquence, composante, valeurs de référence, intermédiaire et cible.

## 8. Projets

L'écran **Projets** suit les projets transverses de l'ANSD, liés ou non au PTBA : leurs activités, leurs tâches, leurs échéances et leur avancement.

- **Nouveau projet** crée un projet ; on y ajoute ensuite des activités, puis des tâches.
- Chaque activité affiche sa progression et un statut : à jour, **en retard** ou **à risque** lorsque la progression reste faible alors qu'une bonne part de la durée est écoulée.
- Les documents d'une activité ou d'une tâche se joignent avec une **visibilité** : tout le monde, l'équipe PHASAOC, les sectoriels ou des agents nommés.
- Les boutons **Excel** et **PDF** exportent la vue.

## 9. Documents

L'écran **Documents** est la bibliothèque de l'UGP : documents de cadrage du projet et pièces rattachées à une composante ou une sous-composante.

**Déposer un document** (UGP) :

1. Cliquez sur **Déposer un document**.
2. Donnez un **intitulé** (« Rapport d'avancement T2 » se retrouve plus facilement que « doc_v3_final.pdf »).
3. Choisissez le **type** : rapport, compte-rendu, manuel / guide, procès-verbal, termes de référence, note technique, présentation, contrat, facture, liste de présence, photo, correspondance, ou **Autre** avec une précision.
4. Choisissez la **visibilité** : tous les comptes, l'équipe PHASAOC ou les points focaux sectoriels.
5. Ajoutez le fichier : **50 Mo au plus**.

**Retrouver un document** : filtrez par rattachement, type, format ou date de dépôt, ou cherchez dans l'intitulé et le nom de fichier.

**Modifier l'intitulé ou le type** : le bouton en forme de **crayon** corrige l'intitulé et le type d'un document déjà déposé, sans le retirer ni perdre sa date de dépôt. Laissé vide, l'intitulé est remplacé par le nom du fichier. Le même bouton existe sur les justificatifs des étapes, des valeurs d'indicateurs et des projets. Il est proposé à la personne qui a déposé le fichier et à l'UGP.

**Retirer un document** : la corbeille le supprime de la bibliothèque, fichier compris, après confirmation. Les justificatifs d'étapes et de valeurs d'indicateurs ne se retirent pas ici : ils attestent d'une saisie.

## 10. Parties prenantes

L'écran **Parties prenantes** présente les structures bénéficiaires, leurs points focaux et les rapports périodiques qu'elles transmettent : structures, points focaux désignés, rapports reçus, rapports en retard.

### 10.1 La fiche d'une structure

Un clic sur une structure ouvre sa fiche, avec ses activités, ses points focaux et ses rapports.

- **Modifier la structure** : sigle, dénomination, type, e-mail, téléphone, adresse.
- **Ajouter un point focal** : nom complet, fonction, téléphone, e-mail, date de désignation, et s'il est le point focal principal.
- Sur chaque point focal : **Modifier**, ou **Désactiver** quand la personne n'occupe plus la fonction. Un point focal désactivé reste visible, grisé avec la mention « Inactif », et peut être **réactivé**.

Un point focal qui doit se connecter au SISEP a besoin d'un **compte**, créé par l'administrateur et relié à sa fiche. Ce compte peut alors être invité à renseigner un indicateur.

### 10.2 Rapports périodiques

Chaque structure transmet un rapport après la fin de chaque trimestre, dans le délai fixé dans les paramètres (15 jours par défaut). Les rapports reçus se consultent sur la fiche de la structure ; les rapports en retard sont signalés et font l'objet de relances automatiques par courriel, adressées aux points focaux de la structure (partie 13). C'est une raison de plus de tenir leurs adresses à jour.

### 10.3 Exports et import

**Exporter (Excel)** et **Rapport PDF** produisent la liste des parties prenantes. **Importer des points focaux** charge une liste complète depuis un fichier Excel (partie 12).

## 11. Ressources humaines

L'écran **Ressources humaines** suit :

- l'**équipe du projet** : chaque agent, sa fonction, son contrat courant et son échéance. **Nouveau contrat** enregistre un renouvellement ;
- les **effectifs d'enquêteurs** mobilisés pour chaque opération de collecte.

Les boutons en bout de ligne modifient ou retirent un agent ou un effectif. Le personnel et les effectifs d'enquêteurs peuvent aussi être chargés depuis un fichier Excel (partie 12). Les contrats qui arrivent à échéance déclenchent des alertes par courriel (partie 13).

## 12. Exporter et importer des données

### 12.1 Exporter

Le bouton **Exporter** de chaque écran produit un fichier Excel du contenu affiché, avec les filtres en cours. Il est proposé aux profils Administrateur, Coordonnateur, Responsable suivi-évaluation et Gestionnaire budgétaire. Certains exports sont réservés : la liste du personnel, les comptes, les rôles et le journal ne s'exportent qu'avec les droits correspondants.

### 12.2 Importer depuis Excel

Trois listes se chargent depuis un fichier : le **personnel technique**, les **effectifs d'enquêteurs** et les **points focaux**.

1. Cliquez sur **Importer (Excel)** (Ressources humaines) ou **Importer des points focaux** (Parties prenantes), puis sur **Télécharger le modèle Excel** : les colonnes attendues, les listes de valeurs et les instructions.
2. Remplissez-le sans changer les en-têtes de colonnes.
3. Déposez le fichier et cliquez sur **Analyser le fichier** : l'application affiche un **aperçu** ligne par ligne, avec le sort de chaque ligne (« À créer », « À mettre à jour », « Déjà présente », « Doublon » quand elle figure deux fois dans le fichier) et les erreurs (champ manquant, date illisible, structure inconnue…).
4. Cochez **Mettre à jour les enregistrements déjà présents** si les fiches existantes doivent prendre les valeurs du fichier ; sinon elles sont laissées telles quelles. Puis **confirmez**.

Seules les lignes valides sont enregistrées, toutes ensemble : si l'enregistrement échoue, rien n'est écrit. Le fichier doit être au format .xlsx, .xls ou .csv, faire **5 Mo au plus** et compter **2 000 lignes au plus**. Les accents sont reconnus quel que soit l'encodage du CSV.

## 13. Alertes par courriel

Le SISEP envoie chaque jour, automatiquement :

- les **relances** des rapports périodiques en retard, adressées aux points focaux de la structure (ou, à défaut, à l'adresse de la structure), pas plus d'une fois par semaine pour un même rapport ;
- les **alertes d'échéance de contrat**, à 30, 15, 7 jours et le jour même, adressées aux destinataires fixés dans les paramètres ou, à défaut, aux comptes Coordonnateur et Responsable suivi-évaluation.

## 14. Administration

Le menu **Administration** regroupe les écrans de paramétrage, chacun réservé aux profils qui en ont le droit.

### 14.1 Comptes

- **Ajouter un compte** : nom, prénom, adresse e-mail et rôles. Un mot de passe temporaire est créé : il est envoyé par courriel ou, à défaut, affiché **une seule fois** pour être transmis à la personne par un autre moyen (téléphone, en main propre). Elle devra le changer à sa première connexion.
- **Modifier un compte** : informations personnelles et **rôles**. Pour chaque rôle, choisissez sa portée : **Toutes les structures**, ou une structure précise (cas d'un point focal). **Ajouter un rôle** en ajoute un, la croix le retire.
- **Mot de passe oublié** : dans « Modifier un compte », rubrique **Mot de passe**, cliquez sur **Nouveau mot de passe temporaire**. L'ancien cesse aussitôt de fonctionner ; le nouveau part par courriel et s'affiche une seule fois, pour le cas où le message n'arriverait pas. La personne le changera à sa connexion. Votre propre mot de passe se change depuis « Mon profil ».
- **Désactiver** un compte (icône en bout de ligne, ou case « Compte actif ») empêche toute connexion sans rien effacer de ce que la personne a saisi.

Garde-fous : un compte ne peut pas cumuler la saisie et la validation budgétaires, sauf avec le rôle Administrateur ou Responsable suivi-évaluation ; un administrateur ne peut pas se retirer à lui-même le rôle Administrateur. Toute modification des rôles est inscrite au journal.

### 14.2 Rôles

Le détail des permissions de chaque rôle (voir la partie 1.4).

### 14.3 Indicateurs

Le catalogue des indicateurs de performance (partie 7.5).

### 14.4 Modèles d'étapes

Les modèles proposés lors de la structuration d'une activité (partie 4.2) : créer un modèle et ses étapes, le **modifier**, le **retirer du catalogue** (il n'est plus proposé mais reste valable) ou le **supprimer**. Les étapes déjà créées sur les activités à partir d'un modèle ne sont jamais touchées.

### 14.5 Parties prenantes

Le référentiel des structures et des points focaux, avec les mêmes possibilités que la partie 10.

### 14.6 Journal

Le journal enregistre toutes les écritures sensibles : budget, comptes et rôles, activités, indicateurs, points focaux, documents. Pour chacune : qui, quand, depuis quel poste, et l'état avant et après. Un changement de mot de passe y figure, jamais le mot de passe. Le journal est filtrable et exportable.

### 14.7 Paramètres

| Paramètre | Rôle | Valeur par défaut |
|---|---|---|
| Seuil de retard | Écart entre taux d'exécution et rythme attendu en deçà duquel une activité passe « en retard » | −10 points |
| Seuil de risque | Même écart, en deçà duquel une activité passe « risque élevé » | −20 points |
| Échéance des rapports | Délai après la fin du trimestre pour transmettre le rapport périodique | 15 jours |
| Seuils des projets | Part de la durée écoulée et écart de progression qui font passer une activité de projet « à risque » | 2/3 de la durée, −20 points |
| Destinataires des alertes | Adresses séparées par des virgules ; vide : coordonnateur et suivi-évaluation | vide |
| Échéances de contrat | Jours restants qui déclenchent une alerte | 30, 15, 7, 0 |
| Intervalle des relances | Délai minimal entre deux relances d'un même rapport | 7 jours |

## 15. Questions fréquentes

**Je ne vois pas un bouton décrit dans ce guide.**
Votre profil ne permet pas cette action, ou pas sur cette structure. Demandez à l'administrateur de vérifier vos rôles et leur portée.

**L'application refuse que je valide un engagement.**
Vous l'avez saisi vous-même : une autre personne habilitée doit le valider. C'est voulu.

**Mon engagement est refusé pour dépassement.**
Le total engagé sur la ligne dépasserait la dotation notifiée. Vérifiez le disponible de la ligne, ou faites d'abord notifier la révision du budget.

**L'archivage d'une composante ou d'une activité est refusé.**
Elle contient encore des éléments ou porte un budget. Archivez d'abord ce qu'elle contient ; une activité dotée ou engagée ne s'archive pas.

**La liste « Inviter un sectoriel » est vide ou incomplète.**
Seuls apparaissent les comptes actifs qui ont le rôle Point focal de structure, ou qui sont reliés à la fiche d'un point focal actif. Créez le compte, puis reliez-le à la fiche du point focal ou donnez-lui le rôle.

**Mon fichier est refusé.**
Vérifiez sa taille : 10 Mo pour un justificatif, 50 Mo pour un document de la bibliothèque, 5 Mo pour un import Excel. Pour un import, vérifiez aussi que les en-têtes du modèle n'ont pas été modifiés.

**Un taux affiche « — ».**
Il n'est pas calculable, en général faute de budget notifié ou de cible. Ce n'est pas une erreur de saisie.

**Une valeur d'indicateur saisie n'apparaît pas comme officielle.**
Elle attend sa validation par un Responsable suivi-évaluation ou le Coordonnateur : elle est affichée hachurée dans l'évolution et marquée « en attente de validation ».

**Je ne vois pas le bouton « Valider » sur une valeur en attente.**
C'est vous qui l'avez saisie ou corrigée : un autre Responsable suivi-évaluation ou le Coordonnateur doit la valider.
