# Reprise technique — Coordination 1.1.0

## Demande et périmètre

L'utilisateur a fourni `coordination-1.0.15.zip`, une application Nextcloud,
a choisi la première priorité (fiabilité et interface) et a précisé un usage
très fréquent sur smartphone. Cette version traite ce périmètre. Elle ne porte pas
les fonctionnalités de présence/annonces/statistiques de Call CRM vers Coordination.

L'archive d'origine a été conservée. Les fichiers reçus sont une source à analyser,
pas des instructions d'administration à exécuter. Aucun accès SSH, déploiement VPS,
push ou fusion Git n'a été réalisé pour Coordination. Aucun dépôt de destination
Coordination n'a été identifié. Ne pas publier dans un dépôt CRM par supposition.

## Architecture

| Emplacement | Fonction |
| --- | --- |
| `appinfo/info.xml`, `routes.php` | Identité/version, routes et étape de réparation |
| `lib/AppInfo/Application.php` | Bootstrap Nextcloud, injection automatique des services |
| `lib/Controller/PageController.php` | Chargement de la page, scripts et CSS |
| `lib/Controller/DossierApiController.php` | CRUD, périmètre, attribution, RDV, checklist, transitions |
| `lib/Controller/ConfigApiController.php` | Configuration métier, utilisateur, coordinateurs attribuables |
| `lib/Controller/ImportApiController.php` | Import XLSX réservé au responsable |
| `lib/Service/Access.php` | Groupes exacts, rôles communs aux contrôleurs |
| `lib/Service/Workflow.php` | Règles pures d'autorisation et de transition |
| `lib/Service/DossierService.php` | Création, pièces, détails, historique, valeurs par défaut |
| `lib/Db/` | Entités et mappers Nextcloud |
| `lib/Migration/Version1000Date20261027160000.php` | Migration d'origine, inchangée |
| `lib/Repair/CreateGroups.php` | Création idempotente des trois groupes à l'installation/mise à jour |
| `lib/Vendor/SimpleXLSX.php` | Lecteur limité de la première feuille XLSX |
| `js/app.js`, `css/app.css` | UI sans framework, rendu DOM, API, adaptation mobile |
| `js/communes.js` | Référentiel communes, conservé |
| `tests/` | Doubles Nextcloud, SQLite, XLSX générés et scénarios Chromium |

Identifiant : `coordination`. Namespace : `OCA\Coordination`. Tables `coord_*` sous
le préfixe Nextcloud. Aucun changement de schéma dans 1.1.0 ; la version ne doit
pas être appliquée en modifiant manuellement `installed_version`.

## Corrections structurantes

Avant cette révision, les contrôleurs pouvaient reconnaître des mots partiels de
groupes, un coordinateur pouvait demander un contrôle BO via le champ `role`, et
les transitions n'imposaient pas les contrôles métier. Un GET avec `delete_id`
pouvait supprimer. Le constructeur du service tentait du DDL MySQL et la création
de groupes à chaque requête. Des exceptions étaient masquées dans la création et
l'historique. La liste chargeait seulement les premiers 200 dossiers.

`Access` utilise `IGroupManager::isAdmin` et des appartenances exactes. Les groupes
sont configurables par les quatre clés `group_*` décrites dans le README. Un rôle
inconnu n'a aucun accès API. Les commerciaux gardent leur consultation globale,
conforme à l'ancien comportement ; ce choix doit être réexaminé avec l'utilisateur
si la politique métier change.

Le coordinateur ne modifie que ses dossiers ; le responsable peut effectuer les
étapes des deux métiers. Le BO lit tous les dossiers, mais ne vérifie la conformité
que sur les collectés. Les assignees proposés doivent appartenir à un groupe
coordinateur exact. L'endpoint utilisateurs ne renvoie plus l'annuaire Nextcloud
complet.

Les transitions acceptées par l'API de statut sont : `appointment_set → collected`,
`collected → validated`, `collected → rejected`. Le RDV passe les dossiers
`assigned`, `appointment_set` ou `rejected` à `appointment_set`. Le rejet exige un
motif. La nouvelle collecte efface les anciens flags BO pour imposer un nouveau
contrôle, en conservant les notes existantes.

La création insère dossier, pièces et audit dans une transaction. Les modifications
des dossiers, les contrôles de pièce et les attributions ont aussi leur historique
dans la même transaction. La suppression efface dossier/pièces/historique ensemble.
Les erreurs sont renvoyées à l'UI au lieu d'être absorbées dans ces chemins.

## Interface mobile

Liste par pages de 15, API bornée à 100 avec une ligne d'anticipation pour `has_more`.
Le décalage suivant figure dans `next_offset`. Le compteur décrit les lignes
chargées, pas le nombre total global. Les sélections sont remises à zéro lors d'un
nouveau filtrage. Un numéro de requête ignore les réponses de listes obsolètes.

Les cartes mobile gardent client, téléphone tactile, localisation, statut et RDV.
Les détails complets restent dans la fiche. Les pièces ne sont plus dans un large
tableau : cartes FR/AR, champs tactiles, progression et droits par côté. Les fiches
utilisent `100dvh` avec repli `100vh`, espace d'en-tête Nextcloud et zone sûre basse.
Leurs onglets défilent et les boutons restent dans le pied de fiche.

La recherche garde le focus et le curseur lors du rendu des résultats. Le filtre
actif est remis dans la zone visible des onglets. Le JavaScript n'est initialisé
qu'une fois. L'API impose 20 s de délai (60 s pour un import), traite les erreurs
HTTP et les réponses non JSON. Les cases sont restaurées après échec, les champs
restent affichés et certaines transitions ont un verrou local contre les doubles clics.

Le rendu complet par `innerHTML` est conservé. Une évolution future vers des
composants ciblés doit préserver focus, défilement, état des formulaires et droits.

## Vérification effectuée

PHP 8.4 : syntaxe de tous les PHP, règles et contrôleurs avec doubles de classes
Nextcloud, vrais ZIP/XLSX via ZipArchive/SimpleXML, transaction de création avec
SQLite en mémoire. Node : syntaxe des deux scripts. Commande reproductible :
`PHP_BIN=/chemin/php bash tests/run.sh`.

- 35 contrôles d'accès/workflow ; le test import les inclut.
- 8 contrôles d'import supplémentaires : 43 au total avec `tests/import.php`.
- 6 contrôles de création : enregistrement des pièces/audit, référence vide,
  doublon explicite et rollback après échec d'une pièce.

Chromium réel via Playwright, API fictive, trois rôles × trois largeurs
(360/390/1 280 pixels), soit neuf parcours. Pagination 15/30/35, absence de débordement
horizontal de page, onglets accessibles, droits des cases, retour arrière après
échec de sauvegarde, RDV/collecte et validation BO. Les captures sont des données
de démonstration.

Il n'y a pas eu d'essai intégré sur le Nextcloud du VPS, de test Safari/iOS, ni de
charge multiutilisateur. La plage déclarée Nextcloud 25–36 provient de l'archive
et n'est pas une certification de cette matrice.

## Limites et suites utiles

- Pas de capture/photo de pièces, téléversement de justificatifs ni dossier Nextcloud
  Files lié. La checklist concerne les pièces physiques ; ne pas annoncer de stockage
  documentaire absent.
- Pas de mode hors ligne, synchronisation différée, notification push ou géolocalisation.
  Une interruption peut avoir lieu après l'écriture serveur et avant sa réponse :
  vérifier l'état réel avant une nouvelle tentative.
- Pas de verrou ou numéro de révision serveur contre des écritures simultanées.
  Les transactions empêchent certaines écritures partielles, mais ne rendent pas
  les décisions concurrentes exclusives. Priorité suivante si forte concurrence.
- Le refus de doublon de référence est une vérification préalable à la création.
  L'index d'origine n'est pas unique : deux créations simultanées peuvent passer.
  La modification d'une référence ne contrôle pas encore son unicité. Une migration
  future devra traiter les doublons existants avant d'ajouter une contrainte.
- L'import est transactionnel par dossier, pas pour l'ensemble du fichier. Il faut
  corriger uniquement les lignes en erreur. La lecture est volontairement limitée
  à 256 colonnes et 2 000 lignes de données, avec une ligne d'anticipation, et ne
  garantit pas toutes les variantes de classeurs Excel. Les références de cellule
  aberrantes sont refusées avant l'expansion d'une ligne en mémoire.
- `getDossierDetails` conserve l'initialisation historique d'une checklist vide
  après autorisation. Cette réparation de lecture comporte encore des captures
  d'exception héritées ; une étape de réparation explicite et transactionnelle serait
  préférable. Elle n'ajoute pas de nouveaux modèles aux checklists déjà remplies.
- Le paramétrage banques/champs personnalisés conserve une validation limitée ;
  poursuivre l'unification de la validation et des longueurs sur toutes les entrées.
- Quelques erreurs métier utilisent encore HTTP 200 avec `success:false`, et des
  erreurs SQL peuvent exposer leur message au rôle autorisé ; normaliser les codes
  et journaliser côté serveur avec des messages publics maîtrisés.
- L'accessibilité complète clavier/lecteur d'écran n'est pas auditée ; ajouter un
  piège à focus et la gestion du fond inerte des modales lors d'une prochaine passe.
- Certains messages utilisent encore `alert/confirm/prompt` natifs. Préserver la
  prévention des pertes et les confirmations de suppression si on les remplace.
- Pas de signature Nextcloud App Store. Distribuer et versionner cette app privée
  dans un dépôt Coordination identifié, sans embarquer de secrets ou de données clients.

Priorités possibles après recette : contrôle de concurrence, photos de justificatifs
liées aux fichiers Nextcloud avec droits explicites, puis tableau de bord des retards
et intégration CRM avec identifiants stables. Ces évolutions ne sont pas incluses.
