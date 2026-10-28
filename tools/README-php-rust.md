# Altay sur php-rust

Experience : faire tourner Altay sur [php-rust](https://github.com/francescotinti/php-rust), un PHP 8.5
reecrit de zero en Rust (binaire `phpr`, VM a bytecode, GC a collecte de cycles).

## Le mur

Altay n'est pas une application PHP ordinaire : il s'appuie sur des extensions natives qui ne sont pas
des bibliotheques PHP mais du code C lie au moteur. `src/PocketMine.php` en exige 22, et refuse de
demarrer s'il en manque une seule. Trois d'entre elles n'ont aucun substitut possible :

- **pmmpthread** — le serveur est multi-thread par conception : thread principal, threads reseau,
  workers asynchrones, thread de logs. Sans lui il n'y a pas de serveur, juste une boucle.
- **leveldb** — le format de monde Bedrock. Sans lui, aucun monde ne se charge.
- **chunkutils2** — l'encodage des chunks, sur le chemin chaud de chaque envoi de terrain.

S'y ajoutent **morton**, **igbinary**, **pmmp/ext-encoding**, **yaml**, **zip**, **gmp** et **sockets**.
php-rust modelise nativement `pdo`, `mysqli`, `dom`, `curl`, `openssl`, et en passe quelques autres par
FFI, mais aucune de celles-ci.

Le multi-threading y est d'ailleurs annonce comme un objectif de conception, pas comme une
fonctionnalite livree : la feuille de route parle d'une boucle d'evenements Tokio a venir.

## Ce que fait ce dossier

`tools/php-rust-report.php` dresse l'etat d'un runtime vis-a-vis de ces exigences, sans rien demarrer :
version, SAPI, taille des entiers, extensions presentes et manquantes, bloquants structurels, et
quelques fonctions dont depend le demarrage.

    phpr tools/php-rust-report.php

Sur le PHP de reference du projet il affiche 22/22 et aucun bloquant. Sur `phpr`, il donne d'un coup
la liste complete de ce qu'il faudrait ecrire, la ou le serveur s'arreterait sur la premiere manquante.

## Mesure reelle

`phpr` construit depuis le depot (binaire de 17,8 Mo, 3 min 31 de lien apres la compilation des
dependances) annonce **PHP 8.5.7** et execute du code sans broncher :

    $ phpr -r 'echo "phpr parle PHP ", PHP_VERSION, "\n";'
    phpr parle PHP 8.5.7

Le rapport donne **13 extensions sur 22** :

    presentes  : curl, ctype, date, gmp, hash, json, mbstring, openssl, pcre, phar, reflection, spl, zip
    manquantes : chunkutils2, encoding, igbinary, leveldb, morton, pmmpthread, sockets, yaml, zlib

Et le controle de demarrage d'Altay s'execute bel et bien sous `phpr` — il rend son verdict lui-meme,
en ajoutant un dixieme point que le rapport ne couvrait pas :

    [ERROR] Selected PHP binary does not satisfy some requirements.
     - Unable to find the PocketMine ChunkUtils v2 (chunkutils2) extension.
     ...
     - IPv6 support is required, but your PHP binary was built without IPv6 support.

A noter : `zlib_decode()` repond alors que l'extension `zlib` n'est pas enregistree, et `sockets`
manque entierement — sans elle il n'y a meme pas de quoi ouvrir une socket d'ecoute.

## Construire phpr sur Linux

Le `build.rs` de `crates/php-types` code en dur des chemins Homebrew : le projet est developpe sur
macOS. Sur Linux il faut lui donner les chemins systeme, et `libtidy` n'est generalement pas installee.
Contournement sans toucher au systeme : des liens symboliques vers `libgd.so.3`, `libxslt.so.1` et
`libexslt.so.0`, et un faux `libtidy.so` generant les 57 symboles que `tidyio.rs` reference, chacun
appelant `abort()`. Altay ne se sert jamais d'`ext/tidy`, donc le lien est satisfait sans qu'aucun de
ces symboles soit jamais atteint.

## Conclusion

Faire tourner Altay sur php-rust demanderait d'y porter une pile d'extensions natives, dont un
equivalent de pmmpthread. Ce n'est pas une question de compatibilite du langage — php-rust fait tourner
WordPress et Symfony — mais d'ecosysteme natif.
