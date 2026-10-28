<?php

declare(strict_types=1);

/**
 * Decodage JSON d'une vraie table de donnees Bedrock. C'est ce que paie le serveur au demarrage,
 * et chaque worker asynchrone le repaie pour son propre compte.
 */
$chemin = dirname(__DIR__, 3) . "/vendor/altayofficial/bedrock-data/item_palette.json";

return [
	"nom" => "json_decode (item_palette.json)",
	"iterations" => 20,
	"ignorer" => !is_file($chemin) ? "item_palette.json introuvable" : null,
	"setup" => static fn () : string => (string) file_get_contents($chemin),
	"run" => static function (string $brut) : void {
		json_decode($brut, true);
	},
];
