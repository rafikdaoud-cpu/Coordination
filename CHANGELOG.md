# Changements

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
