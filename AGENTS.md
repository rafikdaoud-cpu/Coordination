# Reprise de Coordination

Lire `README.md`, `CHANGELOG.md` et `docs/REPRISE_IA.md` avant une modification.
Application Nextcloud autonome, identifiant `coordination`, namespace
`OCA\Coordination`. Ne pas la confondre avec Call CRM ou EasyCrm.

## Invariants

- Le coordinateur n'écrit que sur ses dossiers. Le responsable peut les gérer.
- Un contrôle coordinateur et un contrôle Back-Office sont deux autorisations
  différentes. Ne jamais prendre le rôle déclaré dans le corps HTTP pour une autorisation.
- Les rôles proviennent de groupes exacts, centralisés dans `Service/Access.php`.
- Vérifier l'accès avant de lire les détails : le service conserve une réparation
  des anciennes checklists manquantes.
- Pas de suppression via GET ; conserver la protection CSRF Nextcloud des écritures.
- Écriture métier et historique doivent réussir ou être annulés ensemble.
- Contrôler les pièces obligatoires côté serveur avant collecte/validation.
- La nouvelle remise au Back-Office remet ses cases de conformité à zéro.
- Aucun SQL DDL, création de groupe ou exception silencieuse dans les constructeurs.
- Conserver les identifiants, tables `coord_*`, dossiers existants et historique.
- Priorité smartphone : tester 360 et 390 pixels, appels `tel:`, zones tactiles,
  onglets accessibles, défilement du corps des fiches et boutons bas visibles.
- En cas d'échec réseau, ne pas afficher un succès ni vider le formulaire.
- Toute écriture d'un dossier passe par `DossierLock::claim` dans sa transaction
  (statut attendu, révision si fournie) ; ne pas réintroduire d'écriture non verrouillée.
- Scans : jamais servis sans `Workflow::canViewScans` ; les commerciaux n'y ont pas
  accès ; ne pas exposer `file_name` ni le chemin AppData ; vérifier l'en-tête PDF.
- Erreurs : `ApiResponses::failure` ; seuls les messages de `PublicException` sont
  montrés, le reste est journalisé.

## Vérification

`bash tests/run.sh` : PHP avec mbstring, zip, SimpleXML, PDO SQLite ; Node.js pour
la syntaxe JavaScript. Le navigateur est séparé :
`CHROMIUM_BIN=/chemin/chromium python3 tests/browser-smoke.py /tmp/coord-previews`.
Ces tests utilisent des doubles Nextcloud et une API fictive. Ils ne remplacent
pas une recette sur une instance Nextcloud de test.

Ne pas installer sur le VPS ni publier dans un dépôt CRM à partir d'une supposition
sur la destination de cette application. Consigner toute nouvelle limite dans
`docs/REPRISE_IA.md` et tout changement de comportement dans `CHANGELOG.md`.
