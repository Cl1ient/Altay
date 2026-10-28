# Altay sous php-rust

Experience : faire tourner Altay sur [php-rust](https://github.com/francescotinti/php-rust), un PHP 8.5
reecrit de zero en Rust (binaire `phpr`, VM a bytecode, GC a collecte de cycles).

## Organisation

    tools/php-rust/
      README.md              ce fichier
      rapport.php            etat d'un runtime face aux 22 extensions exigees par Altay
      banc.php               mesure des chemins reels d'Altay sous le runtime courant
      comparer.php           fait tourner le banc sous deux binaires et donne le rapport
      cas/                   un fichier par chemin mesure
      linux/preparer-ffi.sh  de quoi lier phpr sur Linux

## Le mur : les extensions

Altay n'est pas une application PHP ordinaire. Il s'appuie sur des extensions natives qui ne sont pas
des bibliotheques PHP mais du code C lie au moteur. `src/PocketMine.php` en exige 22 et refuse de
demarrer s'il en manque une seule.

    phpr tools/php-rust/rapport.php

Sous `phpr` : **13 sur 22**.

    presentes  : curl, ctype, date, gmp, hash, json, mbstring, openssl, pcre, phar, reflection, spl, zip
    manquantes : chunkutils2, encoding, igbinary, leveldb, morton, pmmpthread, sockets, yaml, zlib

Le controle de demarrage d'Altay s'execute bel et bien sous `phpr` et rend son verdict lui-meme, en
ajoutant un point que le rapport ne couvre pas : l'absence de support IPv6.

Trois manques n'ont aucun substitut :

- **pmmpthread** — le serveur est multi-thread par conception : thread principal, threads reseau,
  workers asynchrones, thread de logs. php-rust annonce le multi-threading comme objectif de feuille
  de route, pas comme fonctionnalite livree.
- **leveldb** — le format de monde Bedrock. Sans lui, aucun monde ne se charge.
- **chunkutils2** — l'encodage des chunks, sur le chemin chaud de chaque envoi de terrain.

`sockets` manque aussi : il n'y aurait meme pas de quoi ouvrir une socket d'ecoute.

## Les perfs

Le serveur ne demarrant pas, le banc mesure ce qui peut l'etre : des chemins reels d'Altay qui ne
touchent que des bibliotheques pures PHP. On compare donc le **moteur** sur du code en forme d'Altay.

    php tools/php-rust/comparer.php <php-du-projet> <phpr>

| cas | PHP 8.4.16 | phpr 8.5.7 | rapport |
|---|---|---|---|
| math (Vector3 / AxisAlignedBB) | 354,75 ms | 5285,12 ms | **14,90x** |
| binaryutils (BinaryStream) | 350,59 ms | 4108,73 ms | **11,72x** |
| chaines et tableaux | 339,16 ms | 1806,31 ms | **5,33x** |
| json_decode (item_palette.json) | 33,87 ms | 44,57 ms | **1,32x** |
| nbt (LittleEndian) | — | ignore | extension `encoding` absente |

Lecture : plus le code est oriente objet, plus l'ecart se creuse. `json_decode` est implemente
nativement en Rust et s'en tire a 1,32x, mais des que l'on empile appels de methodes et objets
courts — ce qu'est le tick d'une entite ou l'encodage d'un paquet — on passe a plus de dix fois.

Le projet annonce 1,77x sur la suite WordPress. Le code d'Altay n'a pas la meme forme : une boucle
de jeu a 20 ticks par seconde vit d'allocations courtes et d'appels de methodes, exactement la ou
l'ecart est le plus grand.

## Construire phpr sur Linux

Le `build.rs` de `crates/php-types` vise des chemins Homebrew. Sur Linux, trois bibliotheques n'ont
pas leur lien `.so` de developpement et `libtidy` est generalement absente.

    tools/php-rust/linux/preparer-ffi.sh /tmp/phpr-ffi /chemin/vers/php-rust/php-rust

Le script fabrique les liens manquants et un faux `libtidy.so` dont chaque symbole abandonne s'il est
appele — Altay ne se sert jamais d'`ext/tidy`. Ajoute ensuite le dossier produit aux
`cargo:rustc-link-search` de `build.rs`, puis :

    cargo build --release --bin phpr
    LD_LIBRARY_PATH=/tmp/phpr-ffi target/release/phpr ...

## Conclusion

Faire tourner Altay sous php-rust demanderait d'y porter une pile d'extensions natives, dont un
equivalent de pmmpthread. Ce n'est pas une question de compatibilite du langage — php-rust fait
tourner WordPress et Symfony — mais d'ecosysteme natif. Et les mesures ci-dessus suggerent que meme
une fois ce portage fait, le moteur ne tiendrait pas une boucle de jeu : c'est precisement la forme
de code ou il est aujourd'hui le plus loin.
