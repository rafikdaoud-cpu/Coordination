# Coordination — Nextcloud — 1.7.0

Gestion des dossiers clients de CSM Taksit : attribution aux coordinateurs,
appel et rendez-vous, collecte physique des pièces, puis contrôle Back-Office.
Cette version prépare un usage fréquent sur smartphone.

## Utilisation sur smartphone

- Cartes dossiers avec le client, le téléphone, la localisation, le statut et le RDV.
- Toucher le numéro ouvre l'application téléphone du smartphone.
- Chargement par 15 dossiers, avec « Afficher les suivants ».
- Fiches occupant l'écran disponible sous l'en-tête Nextcloud, actions en bas et
  onglets pouvant défiler horizontalement.
- Pièces en cartes français/arabe, grandes cases et progression des pièces obligatoires.
- Une case n'est confirmée qu'après réponse du serveur ; un échec restaure son état
  précédent et affiche l'erreur.
- Avertissement avant fermeture ou changement d'onglet avec des champs non enregistrés.

Une connexion est nécessaire. Cette version n'enregistre pas les formulaires
hors ligne et ne les restaure pas après fermeture du navigateur.

## Parcours du coordinateur : RDV → scan → validation

La fiche guide le coordinateur, sur smartphone d'abord, dans cet ordre imposé par le serveur :

1. **① Rendez-vous** : la fiche d'un dossier attribué s'ouvre sur cet onglet. Saisir
   date et heure, « Enregistrer le Rendez-vous ». Le dossier passe « RDV fixé » et la
   fiche bascule sur l'onglet Pièces. Avant le RDV, aucune pièce ne peut être scannée
   ni validée.
2. **② Pièces — scanner** : sous chaque pièce, **📷 Scanner** ouvre la caméra arrière
   en plein écran. Poser la pièce **à plat sur un fond sombre** : un cadre apparaît sur la
   feuille, devient vert, et la photo part **toute seule** après ≈ 1 seconde d'immobilité.
   La page est recadrée et redressée automatiquement. Retourner la pièce pour le verso,
   puis **Terminer**, vérifier les miniatures et **✅ Envoyer le PDF** (10 pages maximum).
   Le déclencheur rond permet une prise manuelle ; « Auto » et « N&B » se règlent en haut
   et en bas de l'écran. Si la caméra est refusée, l'appareil photo du téléphone prend le relais.
   La détection utilise OpenCV (≈ 10 Mo téléchargés au premier scan, puis en cache) ; en
   attendant, une détection rapide intégrée fonctionne déjà (feuille sur fond sombre). Les photos sont
   réduites et assemblées en PDF A4 sur le téléphone (environ 250 à 500 Ko par page).
   **🖼️ Galerie ou PDF** accepte des photos existantes ou un PDF d'une application de scan.
   **Mode guidé** (recommandé sur smartphone) : « 📷 Scanner les pièces une par une »
   enchaîne toutes les pièces sans quitter la caméra : photo automatique, aperçu,
   **✅ Valider et suivant** (le PDF est envoyé et la pièce validée), pièce suivante…
3. **② Pièces — valider** : la case « Validée par le coordinateur » ne s'active
   qu'après l'envoi du scan de la pièce. Supprimer le dernier scan d'une pièce validée
   la remet « à vérifier ».
4. **📤 Transmettre au Back-Office** : actif quand toutes les pièces obligatoires sont
   scannées **et** validées.

Après un rejet BO, le coordinateur refixe un rendez-vous puis reprend les étapes 2 à 4.

Le Back-Office, le responsable et le coordinateur affecté voient les scans sous
chaque pièce : **👁️ Voir** (nouvel onglet) et **⬇️ Télécharger**. Les commerciaux et
les autres coordinateurs n'y ont pas accès. Le coordinateur peut supprimer ses scans
jusqu'à la transmission ; ensuite seul le responsable le peut. Limites : PDF réel
uniquement (contrôlé sur le serveur), 20 Mo par scan, 10 scans par pièce. Les fichiers
sont dans les données privées de l'application Nextcloud (pas dans « Fichiers »).

## Application Android

L'application Android (`android/`) n'ouvre que Coordination : connexion Nextcloud, puis
Coordination sans la barre Nextcloud ; toute autre page Nextcloud est bloquée. APK à
télécharger depuis la release `android-latest` du dépôt. Détails : `android/README.md`.

## Rôles et droits

| Rôle | Groupe par défaut | Accès |
| --- | --- | --- |
| Administrateur Nextcloud | Administrateurs reconnus par Nextcloud | Tous les dossiers et le paramétrage |
| Responsable | `csm_rcoordinateur` | Création, import, attribution, suivi, contrôle, suppression |
| Coordinateur | `csm_coordinateur` | Ses dossiers attribués, pièces, rendez-vous et collecte |
| Back-Office | `csm_backoffice` | Lecture des dossiers et contrôle des dossiers collectés |
| Commercial | `callcrm_commercial`, `easycrm_commercial`, `csm_commercial` | Lecture des dossiers, sans écriture ni accès aux scans |

La lecture par les commerciaux conserve le comportement de l'application reçue.
Les groupes sont comparés exactement. Les anciens rapprochements par mots comme
« resp » ou « office » ne donnent plus de droits. L'installation/mise à jour crée
les trois groupes Coordination manquants ; elle n'y ajoute aucun utilisateur.

Les administrateurs peuvent adapter les groupes via les valeurs Nextcloud
`group_manager`, `group_coordinator`, `group_backoffice` et `group_sales` de l'app
`coordination`. Chaque valeur accepte un identifiant ou un tableau JSON de chaînes,
et remplace les groupes par défaut pour ce rôle. Voir le guide de déploiement.

## Parcours d'un dossier

1. Le responsable importe un XLSX ou crée le dossier, puis l'attribue.
2. Le coordinateur fixe le rendez-vous (date et heure).
3. Au RDV, il scanne chaque pièce en PDF puis la valide ; une fois toutes les pièces
   obligatoires scannées et validées, il transmet le dossier collecté au BO.
4. Le Back-Office vérifie la conformité, puis valide après les contrôles obligatoires,
   ou rejette avec un motif.
5. Un dossier rejeté peut être corrigé puis présenté à nouveau. Les anciennes cases
   de conformité Back-Office sont alors remises à zéro.

Une fois le dossier collecté, son attribution est figée : l'attribution groupée
(sélection ou wilaya) ignore les dossiers collectés ou validés et indique leur nombre.
Retirer le coordinateur d'un dossier en cours le remet au statut « Nouveau ».
Si deux personnes modifient le même dossier en même temps, la seconde écriture est
refusée (« modifié entre-temps ») au lieu d'écraser la première.

Ces règles sont contrôlées sur le serveur. La suppression définitive est réservée
au responsable et à l'administrateur.

## Import Excel

Format `.xlsx`, première feuille, première ligne = en-têtes. Exemple :

| N° dossier | Nom du client | Numéro de téléphone | Adresse | Wilaya | Commune | Banque |
| --- | --- | --- | --- | --- | --- | --- |
| DOS-001 | Client Exemple | 0555000000 | Adresse exemple | 16 - Alger | Alger | Banque exemple |

La colonne du nom est obligatoire ; une ligne sans nom est signalée. Un numéro de
dossier vide est généré. Un numéro déjà présent est refusé à la création. Formater
les téléphones en **texte dans Excel** pour conserver le zéro initial. Les colonnes
supplémentaires sont conservées dans les données personnalisées.

Limites : 10 Mo compressés, 2 000 lignes de données et 256 colonnes par fichier. Le lecteur refuse
aussi plus de 200 entrées ZIP, une entrée décompressée supérieure à 16 Mo ou un total
décompressé supérieur à 32 Mo. Un import peut réussir partiellement : le résultat
indique le nombre créé et les erreurs. Ne pas réimporter les lignes déjà créées.

## Installation et mise à jour

L'identifiant et le dossier de l'application sont `coordination`. Le ZIP de livraison
contient déjà ce dossier : extraire dans un répertoire de préparation, puis copier
ses **contenus** dans l'emplacement de l'app. Ne pas créer
`custom_apps/coordination/coordination/`.

Consulter [docs/DEPLOIEMENT.md](docs/DEPLOIEMENT.md) pour la sauvegarde, les groupes,
les commandes `occ` sous l'utilisateur Nextcloud et la recette mobile. L'ancien
README mentionnait un dossier `Coordination` avec majuscule : vérifier l'installation
réelle avant de modifier son emplacement.

Le paquet déclare Nextcloud 25 à 36, comme la version reçue ; cette plage n'a pas été
vérifiée sur chaque version. Utiliser la version de PHP requise par votre Nextcloud,
avec `mbstring`, `zip` et `SimpleXML` pour l'import. Aucune compilation JavaScript ni
dépendance Composer à installer.

## Développement et vérifications

```bash
bash tests/run.sh
# Ou avec un exécutable PHP particulier :
PHP_BIN=/chemin/vers/php bash tests/run.sh
```

Les tests PHP nécessitent aussi PDO SQLite. Ils couvrent les autorisations et
transitions, la concurrence, l'import de fichiers XLSX générés, l'annulation
transactionnelle d'une création incomplète, et les scans (dépôt, droits, contrôle PDF,
stockage, suppression). `tests/pdf.js` vérifie le PDF assemblé côté téléphone. Les tests navigateur nécessitent Python, Playwright
et Chromium :

```bash
CHROMIUM_BIN=/chemin/vers/chromium python3 tests/browser-smoke.py /tmp/coord-previews
CHROMIUM_BIN=/chemin/vers/chromium python3 tests/browser-opencv.py
```

Neuf parcours vérifient les rôles coordinateur, BO et responsable à 360, 390 et
1 280 pixels, avec une API de démonstration, dont la prise de deux photos, l'envoi
d'un vrai PDF et la consultation BO. Ils ne valident pas l'installation sur
un serveur Nextcloud réel.

Détails techniques et limites : [docs/REPRISE_IA.md](docs/REPRISE_IA.md).
Historique des changements : [CHANGELOG.md](CHANGELOG.md).
