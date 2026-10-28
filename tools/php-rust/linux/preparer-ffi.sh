#!/usr/bin/env bash
# Prepare de quoi lier phpr sur Linux.
#
# Le build.rs de crates/php-types vise des chemins Homebrew : php-rust est developpe sur macOS.
# Sur une Debian ou une Ubuntu, trois bibliotheques n'ont pas leur lien .so de developpement et
# libtidy est generalement absente. Ce script fabrique les liens manquants et un faux libtidy
# dont chaque symbole abandonne s'il est appele : Altay ne se sert jamais d'ext/tidy, donc le
# lien est satisfait sans qu'aucun de ces symboles soit jamais atteint.
#
# Usage : preparer-ffi.sh <dossier-de-sortie> <source-php-rust>
set -euo pipefail

SORTIE="${1:?usage: preparer-ffi.sh <dossier-de-sortie> <source-php-rust>}"
SOURCE="${2:?usage: preparer-ffi.sh <dossier-de-sortie> <source-php-rust>}"
SYS=/usr/lib/x86_64-linux-gnu

mkdir -p "$SORTIE"

for paire in "libgd.so:libgd.so.3" "libxslt.so:libxslt.so.1" "libexslt.so:libexslt.so.0"; do
    lien="${paire%%:*}"
    cible="${paire##*:}"
    if [ -e "$SYS/$cible" ]; then
        ln -sf "$SYS/$cible" "$SORTIE/$lien"
    else
        echo "manquante : $SYS/$cible — installe le paquet correspondant" >&2
    fi
done

if ! ls "$SYS"/libtidy.so* >/dev/null 2>&1; then
    tidyio="$SOURCE/crates/php-types/src/tidyio.rs"
    [ -f "$tidyio" ] || { echo "introuvable : $tidyio" >&2; exit 1; }
    {
        echo '#include <stdio.h>'
        echo '#include <stdlib.h>'
        grep -oE 'fn tidy[A-Za-z_]*' "$tidyio" | sed 's/fn //' | sort -u | while read -r symbole; do
            echo "void $symbole(void){ fputs(\"libtidy stub: $symbole appele\\n\", stderr); abort(); }"
        done
    } > "$SORTIE/tidy-stub.c"
    gcc -shared -fPIC -o "$SORTIE/libtidy.so" "$SORTIE/tidy-stub.c"
    echo "faux libtidy genere depuis $(grep -coE 'fn tidy[A-Za-z_]*' "$tidyio") symboles"
fi

echo "pret : $SORTIE"
echo "ajoute ensuite ces chemins dans crates/php-types/build.rs, puis :"
echo "  cargo build --release --bin phpr"
echo "  LD_LIBRARY_PATH=$SORTIE target/release/phpr ..."
