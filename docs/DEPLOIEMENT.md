# Installation / mise à jour de Coordination 1.5.0

Ce document décrit une opération à réaliser après sauvegarde et recette. La
préparation du paquet n'a exécuté aucune commande sur le VPS. Les exemples utilisent
le chemin Nextcloud communiqué, mais l'emplacement actuel de Coordination reste à vérifier.

## 1. Vérifier l'instance et l'emplacement

Dans une session root du VPS :

```bash
cd /home/csmtak11/cloud.csmtaksit.dz
runuser -u csmtak11 -- php occ status
runuser -u csmtak11 -- php occ app:list
ls -ld custom_apps/coordination custom_apps/Coordination
```

Un des deux chemins peut ne pas exister. Le paquet utilise `coordination` en
minuscules, comme son identifiant Nextcloud. L'ancien README mentionnait
`Coordination` : ne pas créer deux copies ni de lien symbolique pour contourner
un doute sur le chemin. Identifier d'abord le dossier réellement chargé.

Le PHP CLI doit être la version adaptée au Nextcloud installé. Vérifier aussi les
extensions de ce PHP **et du PHP web** : `mbstring`, `zip`, `SimpleXML`.

Les scans PDF (1.2.0) sont plafonnés à 20 Mo par l'application. Le PHP web doit
accepter au moins cette taille : vérifier `upload_max_filesize` et `post_max_size`
(Nextcloud les fixe en général à 512 Mo dans `.user.ini`) et la limite du proxy
(`client_max_body_size` pour nginx). Un scan de 3 pages fait d'ordinaire 0,5 à 1,5 Mo. Si
`needsDbUpgrade` est déjà vrai, ou si une autre mise à jour échoue, résoudre cette
situation avant de remplacer Coordination. Une erreur Call CRM n'est pas corrigée
par ce paquet.

## 2. Sauvegarder et préparer hors production

Sauvegarder la base Nextcloud et le code actuel avec votre procédure habituelle,
dans un emplacement privé hors de la racine web. Vérifier que la sauvegarde est
restaurable. Tester en priorité sur une copie de Nextcloud avec des données fictives.

Extraire le ZIP dans un répertoire temporaire. Il contient un répertoire
`coordination/`, lui-même contenant `appinfo/info.xml`, `lib/`, `js/`, etc.
Ne pas extraire directement au hasard dans le dossier existant.

Les tables restent `coord_dossiers`, `coord_checklists`, `coord_histories`,
`coord_configs`, avec le préfixe de votre Nextcloud. La version 1.2.0 contient une
migration **additive** (`Version1200Date20261104120000`) appliquée par `occ upgrade` :
colonne `revision` (défaut 0) dans `coord_dossiers` et nouvelle table `coord_scans`.
Aucune donnée existante n'est modifiée. Ne pas désinstaller/réinstaller l'application
pour appliquer cette mise à jour, ni supprimer ces tables.

Les fichiers PDF sont rangés dans les données privées de l'application :
`<datadirectory>/appdata_<instanceid>/coordination/scans-d<ID>/`. Ils ne sont pas
visibles dans l'application Fichiers. **Inclure le répertoire de données Nextcloud
dans la sauvegarde**, en plus de la base : la table `coord_scans` seule ne contient
que les métadonnées. Ces documents (pièces d'identité, justificatifs) sont des
données personnelles : chiffrement du disque/sauvegarde et durée de conservation
sont à définir avec le responsable.

## 3. Préparer les groupes

Les trois groupes par défaut sont `csm_rcoordinateur`, `csm_coordinateur` et
`csm_backoffice`. Ajouter les utilisateurs aux groupes appropriés dans Nextcloud.
Une personne qui n'avait accès qu'au moyen d'un nom de groupe ressemblant à
« coord » ou « office » devra avoir une affectation explicite.

Pour utiliser des groupes existants différents, un administrateur peut écrire
les valeurs de configuration, en remplaçant les exemples par les identifiants réels :

```bash
runuser -u csmtak11 -- php occ config:app:set coordination group_manager --value='mon_groupe_responsables'
runuser -u csmtak11 -- php occ config:app:set coordination group_coordinator --value='mon_groupe_coordinateurs'
runuser -u csmtak11 -- php occ config:app:set coordination group_backoffice --value='mon_groupe_bo'
```

Pour plusieurs groupes : `--value='["groupe_a","groupe_b"]'`. La clé `group_sales`
gère la consultation des commerciaux. Elle accepte `[]` pour désactiver ce rôle
de consultation. Les administrateurs Nextcloud conservent leurs droits propres.
Les clés de mapping sont dans la configuration d'application Nextcloud ; les
banques/champs/pièces sont dans la table métier `coord_configs`.

L'étape d'installation/mise à jour crée les groupes Coordination par défaut
manquants, même avec un mapping personnalisé, sans y ajouter d'utilisateurs.

Depuis 1.3.0, le scan de chaque pièce est systématique (parcours RDV → scan →
validation) : la clé `scan_required` de 1.2.0 n'est plus lue et peut être supprimée
(`occ config:app:delete coordination scan_required`).

## 4. Remplacer les fichiers et appliquer la version

Pendant une fenêtre de mise à jour, activer la maintenance si elle était désactivée :

```bash
runuser -u csmtak11 -- php occ maintenance:mode --on
```

Après sauvegarde, remplacer les fichiers du dossier de l'application par le
**contenu** du dossier `coordination/` extrait. Ne pas imbriquer un nouveau
`coordination/` dans l'ancien. Conserver le propriétaire et les permissions attendus
sur ce serveur ; ne pas appliquer un `chmod -R 755` général. Les fichiers n'ont pas
tous besoin d'être exécutables.

Pour une mise à jour existante :

```bash
runuser -u csmtak11 -- php occ upgrade
runuser -u csmtak11 -- php occ status
runuser -u csmtak11 -- php occ app:list
```

Pour une première installation uniquement, activer l'app après mise en place
des fichiers : `runuser -u csmtak11 -- php occ app:enable coordination`.

Si l'opération réussit, que `needsDbUpgrade: false` et qu'aucun autre incident ne
justifie la maintenance, la désactiver si vous l'avez activée pour cette opération :

```bash
runuser -u csmtak11 -- php occ maintenance:mode --off
```

En cas d'échec, conserver le message complet, vérifier les journaux Nextcloud et
résoudre l'erreur avant de désactiver la maintenance. Ne pas changer les versions
stockées en base pour contourner `occ upgrade`. Rafraîchir ensuite le navigateur
pour recharger le JavaScript et le CSS.

## 5. Recette sur smartphone

Utiliser des dossiers fictifs et trois comptes distincts.

1. Responsable : création, import XLSX, attribution ; vérifier l'absence de perte
   des anciens dossiers et la conservation des pièces/historiques.
2. Coordinateur : seuls ses dossiers sont accessibles ; appel par le numéro,
   onglets au doigt, pièces obligatoires, RDV, puis collecte.
3. BO : lecture des dossiers collectés ; cases coordinateur non modifiables ;
   validation bloquée tant que des pièces obligatoires ne sont pas contrôlées.
4. Rejet motivé, correction et nouvelle collecte ; anciennes cases BO décochées.
5. Test réseau coupé : message d'échec, pas de faux succès ; revenir en ligne et
   vérifier l'état réel avant de recommencer une opération interrompue.
6. Android/Chrome et iPhone/Safari si utilisés : clavier, date/heure, en-tête
   Nextcloud, défilement, orientation et actions basses accessibles.
7. Commercial en lecture seule, utilisateur sans groupe refusé, tentative d'accès
   au dossier d'un autre coordinateur refusée.
8. Parcours : sur le téléphone du coordinateur, vérifier qu'avant le RDV aucune
   pièce ne se scanne ni ne se valide ; enregistrer le RDV ; « 📷 Scanner »,
   poser la pièce sur un fond sombre : la photo doit partir seule et la page être
   redressée ; retourner pour le verso ; « Terminer », « Envoyer le PDF », valider la
   pièce ; transmettre. Refuser la caméra une fois : l'appareil photo natif doit prendre
   le relais. Tester sur fond clair, sous éclairage faible et avec une carte plastifiée
   pour comparer : sous le titre de l'écran caméra, « Détection OpenCV » doit apparaître
   quelques secondes après l'ouverture (premier scan : ≈ 10 Mo à télécharger en 4G).
   S'il reste « Détection rapide », ouvrir la console du navigateur : un blocage CSP
   signifie que l'instance n'accepte pas `allowEvalScript`.

Le fichier `js/vendor/opencv.js` (10,4 Mo) est un fichier statique : la compression
gzip/brotli du serveur web (nginx `gzip_types application/javascript`) réduit nettement
le premier téléchargement. Vérifier l'autorisation caméra, la
   rotation des photos, la lisibilité du PDF et la durée d'envoi en 4G. Essayer aussi
   « Galerie ou PDF » avec un PDF produit par l'application scanner du téléphone.
9. BO : « Voir » ouvre le PDF dans un nouvel onglet, « Télécharger » l'enregistre.
   Un commercial et un autre coordinateur ne voient pas les scans (l'URL directe
   répond 404). Après collecte, le coordinateur ne peut plus ajouter ni supprimer.
10. Deux sessions sur le même dossier : la seconde modification de la fiche ou la
   seconde décision BO est refusée avec « modifié entre-temps » ; recharger la fiche.

Les essais Chromium livrés ne simulent ni le CSS complet de Nextcloud ni Safari.

## Retour arrière

Conserver les sauvegardes du code et de la base prises au même instant. Si la
recette échoue, effectuer la restauration avec la procédure Nextcloud habituelle,
en maintenance et sous contrôle de l'administrateur. Ne pas remettre seulement
un numéro de version inférieur en base. La migration 1.2.0 est additive, mais le
code 1.1.0 ne sait pas lire la nouvelle colonne `revision` de ses entités : restaurer
donc le code **et** la base ensemble, jamais le code seul. Les
scans et écritures effectués après mise à jour doivent être pris en compte avant
toute restauration.
