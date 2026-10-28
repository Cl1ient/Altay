<?php

declare(strict_types=1);

/**
 * Manipulation de chaines et de tableaux associatifs : le regime ordinaire d'un plugin, et
 * ce que l'on retrouve partout dans la couche commandes, permissions et traductions.
 */
return [
	"nom" => "chaines et tableaux",
	"iterations" => 100000,
	"run" => static function () : void {
		$table = [];
		foreach (["oak", "birch", "spruce", "jungle", "acacia", "cherry"] as $bois) {
			$identifiant = "minecraft:" . $bois . "_fence";
			$table[$identifiant] = strtoupper(str_replace([":", "_"], ["__", "-"], $identifiant));
		}
		ksort($table);
		implode(",", array_keys($table));
		json_encode($table);
	},
];
