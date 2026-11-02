# Installation / mise à jour de Coordination 1.1.0

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
extensions de ce PHP **et du PHP web** : `mbstring`, `zip`, `SimpleXML`. Si
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
`coord_configs`, avec le préfixe de votre Nextcloud. Il n'y a pas de nouvelle
migration de schéma. Ne pas désinstaller/réinstaller l'application pour appliquer
cette mise à jour, ni supprimer ces tables.

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

Les essais Chromium livrés ne simulent ni le CSS complet de Nextcloud ni Safari.

## Retour arrière

Conserver les sauvegardes du code et de la base prises au même instant. Si la
recette échoue, effectuer la restauration avec la procédure Nextcloud habituelle,
en maintenance et sous contrôle de l'administrateur. Ne pas remettre seulement
un numéro de version inférieur en base. Le paquet 1.1.0 n'ajoute aucune colonne,
mais les écritures effectuées après mise à jour doivent être prises en compte
avant toute restauration.
