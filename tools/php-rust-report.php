<?php

declare(strict_types=1);

/**
 * Dresse l'etat d'un runtime PHP vis-a-vis de ce qu'Altay exige, sans rien demarrer.
 *
 * Ecrit pour l'experience php-rust (francescotinti/php-rust), un PHP 8.5 reecrit en Rust : son
 * binaire phpr n'embarque aucune des extensions natives de PocketMine, et le serveur s'arrete sur
 * la premiere manquante sans dire combien il en reste. Ce script les liste toutes d'un coup, et
 * verifie au passage les quelques fonctions de base dont depend le demarrage.
 *
 * Usage : phpr tools/php-rust-report.php
 */

$required = [
	"chunkutils2" => "PocketMine ChunkUtils v2",
	"curl" => "cURL",
	"ctype" => "ctype",
	"date" => "Date",
	"encoding" => "pmmp/ext-encoding",
	"gmp" => "GMP",
	"hash" => "Hash",
	"igbinary" => "igbinary",
	"json" => "JSON",
	"leveldb" => "LevelDB",
	"mbstring" => "Multibyte String",
	"morton" => "morton",
	"openssl" => "OpenSSL",
	"pcre" => "PCRE",
	"phar" => "Phar",
	"pmmpthread" => "pmmpthread",
	"reflection" => "Reflection",
	"sockets" => "Sockets",
	"spl" => "SPL",
	"yaml" => "YAML",
	"zip" => "Zip",
	"zlib" => "Zlib",
];

printf("runtime   : %s%s", PHP_VERSION, PHP_EOL);
printf("sapi      : %s%s", php_sapi_name(), PHP_EOL);
printf("int size  : %d octets%s", PHP_INT_SIZE, PHP_EOL);
printf("binaire   : %s%s%s", PHP_BINARY, PHP_EOL, PHP_EOL);

$present = [];
$missing = [];
foreach($required as $ext => $name){
	if(extension_loaded($ext)){
		$present[] = $ext;
	}else{
		$missing[] = "$name ($ext)";
	}
}

printf("extensions presentes : %d / %d%s", count($present), count($required), PHP_EOL);
if($present !== []){
	printf("  %s%s", implode(", ", $present), PHP_EOL);
}
printf("%sextensions manquantes : %d%s", PHP_EOL, count($missing), PHP_EOL);
foreach($missing as $name){
	printf("  - %s%s", $name, PHP_EOL);
}

// Les trois piliers sans substitut possible : pas de threads, pas de monde, pas de serveur.
$blockers = array_values(array_filter(["pmmpthread", "leveldb", "chunkutils2"], static fn(string $e) => !extension_loaded($e)));
printf("%sbloquants structurels : %s%s", PHP_EOL, $blockers === [] ? "aucun" : implode(", ", $blockers), PHP_EOL);

$functions = ["pcntl_signal", "socket_create", "igbinary_serialize", "yaml_parse", "zlib_decode"];
printf("%sfonctions de demarrage :%s", PHP_EOL, PHP_EOL);
foreach($functions as $fn){
	printf("  %-20s %s%s", $fn, function_exists($fn) ? "ok" : "absente", PHP_EOL);
}
