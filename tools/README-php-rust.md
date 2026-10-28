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

## Conclusion

Faire tourner Altay sur php-rust demanderait d'y porter une pile d'extensions natives, dont un
equivalent de pmmpthread. Ce n'est pas une question de compatibilite du langage — php-rust fait tourner
WordPress et Symfony — mais d'ecosysteme natif.
