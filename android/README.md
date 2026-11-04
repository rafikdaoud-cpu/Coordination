# Application Android Coordination

Application minimale (Kotlin, aucune dépendance) qui ouvre **uniquement** l'application
Coordination de `https://cloud.csmtaksit.dz` dans une vue web :

- connexion Nextcloud (et double authentification) autorisée ;
- toute autre page Nextcloud (Fichiers, tableau de bord…) ramène à Coordination ;
- liens externes ouverts dans le navigateur, `tel:` dans le composeur ;
- caméra du scanner en direct (autorisation Android demandée au premier scan) ;
- photos et PDF depuis la galerie ; scans PDF ouverts dans le lecteur PDF du téléphone ;
- barre Nextcloud masquée et bouton « Déconnexion » (mode `co-android` de l'app web) ;
- HTTPS uniquement, page « Connexion impossible » hors réseau.

## Compilation (GitHub Actions)

Le workflow `.github/workflows/android.yml` compile l'APK à chaque modification de
`android/` et le publie dans la release **android-latest** du dépôt (téléchargeable
depuis le téléphone). Lancement manuel : onglet *Actions* → *Application Android* → *Run workflow*.

Serveur : `coordinationServer` dans `gradle.properties`.

## Signature (à faire une fois)

Sans secrets, l'APK est signé avec une clé de test qui change à chaque compilation :
chaque mise à jour oblige à désinstaller l'ancienne version. Pour une clé permanente :

```bash
keytool -genkeypair -v -keystore coordination.jks -alias coordination -keyalg RSA -keysize 4096 -validity 10000
base64 -w0 coordination.jks
```

Dans GitHub → *Settings* → *Secrets and variables* → *Actions*, créer :
`ANDROID_KEYSTORE_BASE64` (sortie de base64), `ANDROID_KEYSTORE_PASSWORD`,
`ANDROID_KEY_ALIAS` (`coordination`), `ANDROID_KEY_PASSWORD`.
**Conserver `coordination.jks` et son mot de passe hors du dépôt** : sans eux, plus
aucune mise à jour possible de l'application installée.
