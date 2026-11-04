# Bibliothèques embarquées

| Fichier | Origine | Version | Licence |
| --- | --- | --- | --- |
| `opencv.js` | paquet npm `@techstark/opencv-js` (`dist/opencv.js`, build officiel OpenCV.js avec WASM intégré) | 4.10.0-release.1 | Apache-2.0 (`opencv-LICENSE.txt`) |

`opencv.js` n'est chargé qu'à la demande par `js/docscan.js` (premier scan), jamais avec la page.
Il exige `unsafe-eval` (embind) : `PageController` l'autorise pour la seule page Coordination.
Mise à jour : `npm pack @techstark/opencv-js@<version>`, copier `package/dist/opencv.js`, relancer les tests.
