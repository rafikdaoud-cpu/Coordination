#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
php_bin="${PHP_BIN:-php}"
"$php_bin" -r 'foreach (["mbstring","zip","simplexml","pdo_sqlite"] as $ext) { if (!extension_loaded($ext)) { fwrite(STDERR,"Extension PHP requise pour les tests : $ext\n"); exit(2); } }'
while IFS= read -r -d '' file; do
    "$php_bin" -l "$file" >/dev/null
done < <(find lib appinfo templates tests -type f -name '*.php' -print0)
node --check js/app.js
node --check js/communes.js
node --check js/scanpdf.js
"$php_bin" tests/import.php
"$php_bin" tests/create.php
"$php_bin" tests/scan.php
node tests/pdf.js
echo 'Syntaxe PHP/JavaScript et 99 contrôles automatisés réussis.'
