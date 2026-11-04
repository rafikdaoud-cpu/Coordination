# Reprise technique — Coordination 1.4.0

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
le préfixe Nextcloud. Aucun changement de schéma dans 1.1.0 ; 1.2.0 ajoute une
migration additive (`revision`, `coord_scans`). Une version ne doit jamais être
appliquée en modifiant manuellement `installed_version`.

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

## Version 1.4.0 : scanner en direct (option A)

`js/docscan.js` (`window.CoordinationDocScan`) : `getUserMedia` caméra arrière, analyse
toutes les 100 ms sur une image de 320 px (gris, flou boîte ×2, seuil d'Otsu, plus grande
composante ne touchant pas le bord, enveloppe convexe, quadrilatère d'aire maximale,
contrôles : aire 10–97 %, remplissage, angles 45–135°). Déclenchement après 8 analyses
stables (coins < 2 % de la largeur) ; réarmement quand la feuille disparaît ou bouge de
plus de 10 %. Redressement par homographie et interpolation bilinéaire en pleine
résolution (≤ 1 800 px), puis `CoordinationScan.canvasToPage`. Repli sur l'`input
capture` natif (`.scan-photo-input`). La page déclare `camera 'self'` (FeaturePolicy).
Limites : fond clair sous une feuille claire, reflets de plastification, pièce coupée par
le cadre ; pas de torche pilotable sur iPhone. Option B si besoin : OpenCV.js (≈ 8 Mo,
CSP `wasm-unsafe-eval` à prévoir). Tests : `tests/docscan.js` (images synthétiques) et
fausse caméra Y4M dans `tests/browser-smoke.py`.

## Version 1.3.0 : parcours imposé RDV → scan → validation

`Workflow::APPOINTMENT_STAGE` (assigned, appointment_set, rejected) : statuts depuis
lesquels le RDV se fixe. `Workflow::COORDINATOR_STAGE` (appointment_set seul) : scans
et cases coordinateur. `Workflow::pieceCheckError` : cocher côté coordinateur exige un
scan de la pièce. `transitionError` vers `collected` exige scan et validation de
chaque pièce obligatoire. La suppression du dernier scan d'une pièce validée la décoche
dans la même transaction. Interface : `defaultModalTab`, `renderNextStep`, boutons
`data-goto-tab`. L'option `scan_required` (1.2.0) a été retirée.

## Version 1.2.0 : scans PDF et corrections

Dépôt de destination identifié : `rafikdaoud-cpu/Coordination` (GitHub).

| Emplacement | Fonction |
| --- | --- |
| `js/scanpdf.js` | Réduction des photos (1 800 px, JPEG 0,72, orientation EXIF), mode document, assemblage PDF A4 (`window.CoordinationScan`) |
| `lib/Service/ScanService.php` | Contrôle PDF, stockage AppData `scans-d<ID>/`, table `coord_scans` |
| `lib/Controller/ScanApiController.php` | Dépôt, ouverture/téléchargement, suppression, avec droits et verrou |
| `lib/Service/DossierLock.php` | `UPDATE … SET revision = revision + 1 WHERE id AND status IN … [AND revision]` dans la transaction |
| `lib/Controller/ApiResponses.php` | Codes HTTP, messages publics (`PublicException`), journalisation des erreurs techniques |
| `lib/Migration/Version1200Date20261104120000.php` | Colonne `revision`, table `coord_scans` (additive) |

Droits des scans (`Workflow`) : consultation par admin, responsable, BO et
coordinateur affecté ; dépôt par le coordinateur affecté ou le responsable au
statut `appointment_set` (depuis 1.3.0) ; suppression par l'auteur à ces
étapes ou par le responsable à tout moment. Les commerciaux, qui lisent les fiches,
ne voient pas les scans : `show()` n'ajoute `scans` qu'avec `can_view_scans`.

Ordre d'écriture d'un scan : fichier écrit, puis transaction (verrou, plafond de
10 scans par pièce, ligne, historique) ; si la transaction échoue, le fichier est
effacé. Pour une suppression de scan ou de dossier, les lignes sont supprimées en
transaction puis les fichiers après le commit (au pire un fichier orphelin journalisé).
L'URL de fichier est un GET sans jeton CSRF (lien direct « Voir ») : l'accès reste
soumis à la session et aux droits, et une 404 ne révèle pas l'existence du dossier.

Concurrence : la fiche envoie `revision` lors d'une modification par le responsable.
Les autres écritures (RDV, cases, statut, scans) vérifient sous verrou que le statut
lu est encore celui de la base ; `changeStatus` revérifie pièces et scans après verrou.
`assignOne` ne modifie que les statuts `Workflow::REASSIGNABLE`.

Vérification 1.2.0 : 99 contrôles `tests/run.sh` (PHP 8.4, Node 22) et 9 parcours
Chromium avec prise de deux photos simulées, envoi d'un vrai PDF (en-tête vérifié)
et consultation BO. Le PDF généré est lu sans erreur par poppler (`pdfinfo`,
`pdftoppm`). Non testé : caméra réelle Android/iOS, Safari, Nextcloud réel
(AppData, en-têtes CSP sur l'ouverture inline d'un PDF), limites du proxy web.

## Limites et suites utiles

- Les scans ne sont pas dans Nextcloud Files ni partageables ; pas d'OCR, de
  détection automatique des bords ou de redressement de perspective : cadrer la
  pièce à plat. Pas de « tout télécharger » (ZIP) par dossier pour le BO.
- Les scans ne sont pas chiffrés par l'application : ils suivent le chiffrement
  éventuel du stockage Nextcloud. Aucune purge automatique : définir une durée de
  conservation des pièces d'identité avec le responsable.
- Pas de mode hors ligne, synchronisation différée, notification push ou géolocalisation.
  Une interruption peut avoir lieu après l'écriture serveur et avant sa réponse :
  vérifier l'état réel avant une nouvelle tentative (vaut aussi pour l'envoi d'un scan).
- Unicité du numéro de dossier : contrôlée à la création et à la modification, mais
  l'index historique n'est pas unique ; deux créations simultanées du même numéro
  restent possibles. Une migration future devra traiter les doublons existants
  avant d'ajouter une contrainte unique.
- L'import est transactionnel par dossier, pas pour l'ensemble du fichier. Il faut
  corriger uniquement les lignes en erreur. La lecture est volontairement limitée
  à 256 colonnes et 2 000 lignes de données, avec une ligne d'anticipation, et ne
  garantit pas toutes les variantes de classeurs Excel. Les références de cellule
  aberrantes sont refusées avant l'expansion d'une ligne en mémoire.
- `getDossierDetails` conserve l'initialisation historique d'une checklist vide
  après autorisation. Cette réparation de lecture comporte encore des captures
  d'exception héritées ; une étape de réparation explicite et transactionnelle serait
  préférable. Elle n'ajoute pas de nouveaux modèles aux checklists déjà remplies.
- L'accessibilité complète clavier/lecteur d'écran n'est pas auditée ; ajouter un
  piège à focus et la gestion du fond inerte des modales lors d'une prochaine passe.
- Certains messages utilisent encore `alert/confirm/prompt` natifs. Préserver la
  prévention des pertes et les confirmations de suppression si on les remplace.
- Pas de signature Nextcloud App Store. Distribuer et versionner cette app privée
  dans ce dépôt, sans embarquer de secrets ou de données clients.

Priorités possibles après recette : téléchargement groupé des scans pour le BO,
recadrage automatique des documents, tableau de bord des retards et intégration CRM
avec identifiants stables. Ces évolutions ne sont pas incluses.
