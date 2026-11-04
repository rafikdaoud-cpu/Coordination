# Changements

## 1.8.0 — 2026-11-04

### Correctif : OpenCV bloqué par la sécurité de Nextcloud

- Sur Nextcloud réel, la CSP des pages refuse `unsafe-eval` malgré `allowEvalScript` :
  le scanner restait en « Détection rapide · OpenCV bloqué par la sécurité du serveur ».
- OpenCV.js s'exécute désormais dans un Web Worker (`js/docscan-worker.js`), fichier
  statique hors CSP de page. La page n'autorise plus ni eval ni WASM ; seulement
  `worker-src 'self'`. La détection ne bloque plus l'affichage de la caméra.
- Messages d'échec précis : worker refusé (CSP worker-src), CSP posée par le serveur web
  sur les fichiers .js, fichier introuvable (HTTP 404), délai.
- Test `tests/browser-opencv.py` avec la CSP stricte des pages Nextcloud (sans unsafe-eval).


## 1.7.0 — 2026-11-04

### Application Android

- Nouveau dossier `android/` : application qui n'ouvre que Coordination (connexion
  Nextcloud comprise), compilée par GitHub Actions et publiée dans la release
  `android-latest`. Voir `android/README.md`.
- Mode application dans l'app web (agent `CoordinationAndroid/`) : barre Nextcloud
  masquée, bouton « ⎋ Déconnexion » dans l'en-tête Coordination.


## 1.6.0 — 2026-11-04

### Mode guidé sur smartphone : scan → valider → suivant

- Bouton « 📷 Scanner les pièces une par une » (étape 2) ; « 📷 Scanner » sous une pièce
  lance le même parcours à partir de cette pièce.
- Sans quitter la caméra : photo automatique, aperçu, puis **✅ Valider et suivant**
  (envoi du PDF et validation de la pièce), pièce suivante ; « ➕ Ajouter une page
  (verso) », « ↺ Reprendre la photo », « Passer cette pièce ».
- Pièce déjà scannée mais non validée : « Valider et suivant » ou « Scanner à nouveau ».
- Fin de parcours : liste des pièces obligatoires restantes, ou invitation à transmettre.
- Erreur réseau : pages conservées, nouvelle tentative sans renvoyer un PDF déjà reçu.

### OpenCV : diagnostic et chargement

- Adresse d'`opencv.js` déduite de celle de `docscan.js` (fonctionne quel que soit le
  dossier d'apps), avec repli sur `OC.appswebroots` puis `OC.linkTo`.
- La raison d'un échec s'affiche sous le titre de la caméra : « bloqué par la sécurité
  du serveur (CSP …) », « fichier introuvable (HTTP 404) », « délai dépassé »…
- Test `tests/browser-opencv.py` : adresse, chargement, CSP refusant eval, fichier absent.


## 1.5.0 — 2026-11-04

### Scanner : détection OpenCV (option B)

- OpenCV.js 4.10 (`js/vendor/opencv.js`, 10,4 Mo, Apache-2.0) chargé **à la demande**
  au premier scan, puis gardé en cache par le navigateur.
- Détection par contours (Canny, deux sensibilités) : trouve une feuille blanche sur
  une table claire, cas où la détection rapide échoue.
- La détection rapide (option A) fonctionne pendant le chargement et sert de repli si
  OpenCV ne peut pas se charger ; le moteur actif est indiqué sous le titre.
- La page Coordination autorise `unsafe-eval` et la compilation WASM (requis par
  OpenCV.js), uniquement pour elle.


## 1.4.0 — 2026-11-04

### Scanner en direct avec détection automatique (option A)

- « 📷 Scanner » ouvre la caméra arrière en plein écran dans l'application.
- La feuille est détectée en temps réel (cadre jaune puis vert) ; la photo part
  **seule** quand la feuille reste immobile ≈ 1 s, avec vibration et flash visuel.
- Redressement automatique de la perspective et recadrage sur la feuille.
- Recto puis verso : après une prise, retourner la pièce déclenche la page suivante.
- Déclencheur manuel, interrupteurs « Auto » et « N&B », bouton « Terminer ».
- Repli automatique sur l'appareil photo natif si la caméra est refusée ou indisponible.
- Détection maison sans bibliothèque (`js/docscan.js`, ≈ 20 Ko) : feuille claire sur
  fond plus sombre recommandée. En cas de ratés fréquents, l'option B (OpenCV.js) reste possible.
- `PageController` autorise la caméra pour la page (Permissions-Policy `camera 'self'`).


## 1.3.0 — 2026-11-04

### Parcours coordinateur imposé : RDV → scan → validation

- Les pièces ne se scannent et ne se valident qu'une fois le RDV enregistré
  (statut « RDV fixé ») ; après un rejet, un nouveau RDV est exigé d'abord.
- La validation coordinateur d'une pièce exige au moins un scan de cette pièce ;
  supprimer le dernier scan d'une pièce validée la remet « à vérifier ».
- La transmission au BO exige, pour chaque pièce obligatoire, scan **et** validation.
  Le scan devient donc systématique : l'option `scan_required` de 1.2.0 est retirée.
- Contrôles côté serveur (403/409) et interface guidée : onglets ① Rendez-vous puis
  ② Pièces, ouverture de la fiche sur l'étape en cours, bandeau d'étape, bascule
  automatique vers les pièces après le RDV, case « Valider » active après le scan,
  bouton « 📤 Transmettre au Back-Office » sur l'onglet Pièces.

### Smartphone

- Messages collés en haut de la fiche (plus d'`alert` lors d'une case refusée),
  effacement automatique des succès, onglet actif ramené dans la zone visible,
  défilement conservé lors des mises à jour. Aucune migration de base.


## 1.2.0 — 2026-11-04

### Nouveau : scan des pièces en PDF

- Bouton « 📷 Scanner » sous chaque pièce : photos à l'appareil arrière, plusieurs
  pages, aperçu et retrait de page, mode document noir et blanc, assemblage PDF A4
  sur le téléphone (`js/scanpdf.js`, sans bibliothèque externe).
- « Galerie ou PDF » : photos existantes ou PDF d'une application de scan.
- Invitation à scanner après avoir coché une pièce ; progression des pièces scannées.
- Back-Office, responsable et coordinateur affecté : « Voir » et « Télécharger ».
  Commerciaux et autres coordinateurs : aucun accès (404).
- Serveur : contrôle du contenu PDF, 20 Mo et 10 scans par pièce, stockage dans
  les données privées de l'app, historique d'ajout/suppression, suppression avec
  le dossier. Option `scan_required` pour exiger un scan par pièce obligatoire.
- Migration additive : table `coord_scans`.

### Corrections

- Sécurité : le nom affiché d'un coordinateur était inséré sans échappement dans la
  liste d'attribution groupée (XSS). Corrigé et couvert par un test navigateur.
- L'attribution groupée par wilaya ou par sélection ne modifie plus les dossiers
  collectés ou validés ; le nombre de dossiers ignorés est affiché. Même règle pour
  l'attribution individuelle (409).
- Retirer le coordinateur remet le dossier au statut « Nouveau ».
- Concurrence : colonne `revision` et verrou de ligne transactionnel. Modification
  de fiche avec révision périmée, décision BO, case cochée ou RDV sur un dossier
  changé entre-temps : refus 409 au lieu d'un écrasement silencieux. Les pièces
  sont revérifiées sous verrou avant collecte/validation.
- Numéro de dossier : unicité contrôlée aussi à la modification ; nom du client
  non vidable ; longueurs bornées (notes, consignes, banques, champs personnalisés).
- Recherche : `%` et `_` saisis sont cherchés littéralement.
- Erreurs : codes HTTP significatifs (400/403/404/409/413/500) ; les messages
  techniques (SQL) sont journalisés côté serveur et remplacés par un message neutre,
  y compris dans le rapport d'import.
- Conservation du défilement de la fiche lors des rafraîchissements d'affichage.


## 1.1.0 — 2026-11-02

Version préparée à partir de l'archive fournie `coordination-1.0.15.zip`.

### Smartphone

- Cartes dossiers plus lisibles, téléphone tactile, attribution répétitive masquée
  dans la liste personnelle du coordinateur.
- Fiches adaptées à la hauteur disponible, actions basses et onglets accessibles.
- Checklist en cartes bilingues avec grandes cases et progression des pièces obligatoires.
- Chargement progressif par 15 dossiers, jusqu'à épuisement de la liste.
- Écran initial adapté au rôle : nouveaux, attribués ou collectés.
- Messages d'enregistrement, annulation visuelle d'une case refusée et avertissement
  avant abandon de champs non enregistrés.

### Fiabilité et droits

- Rôles communs aux API, à partir de groupes exacts et configurables.
- Vérification du périmètre avant lecture des détails ; annuaire limité aux coordinateurs.
- Suppression par GET refusée ; écritures via POST/PUT/DELETE.
- Contrôle serveur des droits coordinateur/BO, des transitions, du RDV et des pièces
  obligatoires. Motif exigé en cas de rejet.
- Remise à zéro des cases BO lors d'une nouvelle collecte.
- Transactions : création + pièces + historique, modifications + historique,
  attributions + historique et suppression des données liées.
- Référence automatique si vide et refus d'une référence existante à la création.
- Suppression du SQL de création et des créations de groupe dans le constructeur ;
  groupes créés par une étape Nextcloud d'installation/mise à jour.
- Lecture XLSX bornée, en-têtes français accentués reconnus, téléphone distingué du
  numéro de dossier, lignes sans nom signalées et erreurs partielles visibles.
- Tests reproductibles et documentation de déploiement/reprise.

Pas de nouvelle migration de schéma. Aucune installation VPS ou publication Git
n'est effectuée par la préparation du paquet.

## 1.0.15 — archive reçue

Base existante avec import XLSX, attribution, rendez-vous, collecte, checklist
français/arabe, contrôle BO, historique et paramétrage.
