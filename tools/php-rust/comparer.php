<?php

declare(strict_types=1);

/**
 * Compare deux runtimes sur le meme banc et affiche le rapport entre les deux.
 *
 * Usage : php tools/php-rust/comparer.php <binaire-reference> <binaire-candidat>
 *
 * Chaque binaire execute banc.php en mode JSON dans son propre processus : un runtime ne peut donc
 * pas fausser la mesure de l'autre, et le candidat n'a pas besoin de savoir lire les resultats.
 */

if ($argc < 3) {
	fwrite(STDERR, "usage : php " . basename(__FILE__) . " <binaire-reference> <binaire-candidat>" . PHP_EOL);
	exit(1);
}

$banc = __DIR__ . "/banc.php";

/**
 * @return array{runtime: string, binaire: string, resultats: list<array<string, mixed>>}
 */
function executer(string $binaire, string $banc) : array {
	$sortie = [];
	$code = 0;
	exec(escapeshellarg($binaire) . " " . escapeshellarg($banc) . " --json 2>/dev/null", $sortie, $code);
	if ($code !== 0) {
		fwrite(STDERR, "echec de $binaire (code $code)" . PHP_EOL);
		exit(1);
	}
	$decode = json_decode(implode("\n", $sortie), true);
	if (!is_array($decode)) {
		fwrite(STDERR, "sortie illisible de $binaire" . PHP_EOL);
		exit(1);
	}

	return $decode;
}

$reference = executer($argv[1], $banc);
$candidat = executer($argv[2], $banc);

$parNom = [];
foreach ($candidat["resultats"] as $r) {
	$parNom[$r["nom"]] = $r;
}

printf("reference : %s (%s)%s", $reference["runtime"], $reference["binaire"], PHP_EOL);
printf("candidat  : %s (%s)%s%s", $candidat["runtime"], $candidat["binaire"], PHP_EOL, PHP_EOL);
printf("%-34s %13s %13s %9s%s", "cas", "reference ms", "candidat ms", "rapport", PHP_EOL);
printf("%s%s", str_repeat("-", 73), PHP_EOL);

foreach ($reference["resultats"] as $r) {
	$autre = $parNom[$r["nom"]] ?? null;
	if (isset($r["ignore"]) || $autre === null || isset($autre["ignore"])) {
		$raison = $r["ignore"] ?? $autre["ignore"] ?? "absent";
		printf("%-34s %s%s", $r["nom"], "ignore : " . $raison, PHP_EOL);
		continue;
	}
	printf(
		"%-34s %13s %13s %8sx%s",
		$r["nom"],
		$r["ms"],
		$autre["ms"],
		$r["ms"] > 0 ? round($autre["ms"] / $r["ms"], 2) : "-",
		PHP_EOL
	);
}
