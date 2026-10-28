<?php

declare(strict_types=1);

/**
 * Banc de mesure : fait tourner des chemins reels d'Altay sous le runtime courant.
 *
 * Chaque cas de tools/php-rust/cas/ ne touche que des bibliotheques pures PHP, pour pouvoir tourner
 * aussi bien sous le PHP du projet que sous phpr, qui n'a aucune des extensions natives du serveur.
 * Ce que l'on compare ici est donc le moteur lui-meme sur du code en forme d'Altay, pas le serveur.
 *
 * Usage : php tools/php-rust/banc.php [--json]
 */

require dirname(__DIR__, 2) . "/vendor/autoload.php";

/**
 * @param callable():void|callable(mixed):void $run
 */
function mesurer(callable $run, mixed $contexte, int $iterations) : float {
	$debut = hrtime(true);
	for ($i = 0; $i < $iterations; $i++) {
		$contexte === null ? $run() : $run($contexte);
	}

	return (hrtime(true) - $debut) / 1e6;
}

$json = in_array("--json", $argv, true);
$fichiers = glob(__DIR__ . "/cas/*.php") ?: [];
sort($fichiers);

$resultats = [];
foreach ($fichiers as $fichier) {
	/** @var array{nom: string, iterations: int, run: callable, setup?: callable, ignorer?: string|null, requiert?: array{extension?: string, classe?: string}} $cas */
	$cas = require $fichier;
	$manque = $cas["ignorer"] ?? null;
	if ($manque === null && isset($cas["requiert"])) {
		$extension = $cas["requiert"]["extension"] ?? null;
		$classe = $cas["requiert"]["classe"] ?? null;
		if ($extension !== null && !extension_loaded($extension)) {
			$manque = "extension $extension absente";
		} elseif ($classe !== null && !class_exists($classe)) {
			$manque = "classe $classe absente";
		}
	}
	if ($manque !== null) {
		$resultats[] = ["nom" => $cas["nom"], "ignore" => $manque];
		continue;
	}

	$contexte = isset($cas["setup"]) ? ($cas["setup"])() : null;
	$run = $cas["run"];
	$iterations = $cas["iterations"];

	mesurer($run, $contexte, max(1, (int) ($iterations / 20)));

	$mesures = [];
	for ($essai = 0; $essai < 3; $essai++) {
		$mesures[] = mesurer($run, $contexte, $iterations);
	}
	sort($mesures);

	$resultats[] = [
		"nom" => $cas["nom"],
		"iterations" => $iterations,
		"ms" => round($mesures[1], 2),
		"min_ms" => round($mesures[0], 2),
		"max_ms" => round($mesures[2], 2),
		"ns_par_iteration" => round($mesures[1] * 1e6 / $iterations, 1),
	];
}

$entete = [
	"runtime" => PHP_VERSION,
	"binaire" => PHP_BINARY,
	"resultats" => $resultats,
];

if ($json) {
	echo json_encode($entete, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
	exit(0);
}

printf("runtime : %s%s", PHP_VERSION, PHP_EOL);
printf("binaire : %s%s%s", PHP_BINARY, PHP_EOL, PHP_EOL);
printf("%-34s %10s %12s %14s%s", "cas", "iterations", "mediane ms", "ns/iteration", PHP_EOL);
printf("%s%s", str_repeat("-", 74), PHP_EOL);
foreach ($resultats as $r) {
	if (isset($r["ignore"])) {
		printf("%-34s %s%s", $r["nom"], "ignore : " . $r["ignore"], PHP_EOL);
		continue;
	}
	printf("%-34s %10d %12s %14s%s", $r["nom"], $r["iterations"], $r["ms"], $r["ns_par_iteration"], PHP_EOL);
}
