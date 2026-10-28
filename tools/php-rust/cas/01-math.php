<?php

declare(strict_types=1);

use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;

/**
 * Deplacement et collisions : le coeur du tick d'entite. Chaque entite vivante recalcule sa
 * trajectoire et teste ses boites englobantes contre celles du terrain a chaque tick.
 */
return [
	"nom" => "math (Vector3 / AxisAlignedBB)",
	"iterations" => 200000,
	"run" => static function () : void {
		$position = new Vector3(0.5, 64.0, 0.5);
		$mouvement = new Vector3(0.21, -0.08, 0.13);
		$boite = new AxisAlignedBB(0.0, 64.0, 0.0, 1.0, 65.8, 1.0);

		$position = $position->addVector($mouvement);
		$position->distanceSquared(new Vector3(8.0, 64.0, 8.0));
		$mouvement->normalize();
		$boite->offset($mouvement->x, $mouvement->y, $mouvement->z)->intersectsWith($boite);
	},
];
