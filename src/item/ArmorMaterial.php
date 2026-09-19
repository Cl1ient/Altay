<?php

/*
 *
 *      _    _ _
 *     / \  | | |_ __ _ _   _
 *    / _ \ | | __/ _` | | | |
 *   / ___ \| | || (_| | |_| |
 *  /_/   \_\_|\__\__,_|\__, |
 *                       |___/
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Original work by the PocketMine Team.
 * https://www.pocketmine.net/
 *
 * @author Altay Team
 * @link https://github.com/altayofficial
 */

declare(strict_types=1);

namespace pocketmine\item;

use pocketmine\utils\Utils;
use pocketmine\world\sound\Sound;

class ArmorMaterial{

	public function __construct(
		private readonly int $enchantability,
		private readonly ?Sound $equipSound = null,
		private readonly float $knockbackResistance = 0.0
	){
		Utils::checkFloatNotInfOrNaN("Knockback resistance", $knockbackResistance);
		if($knockbackResistance < 0.0 || $knockbackResistance > 1.0){
			throw new \InvalidArgumentException("Knockback resistance must be in range 0.0 - 1.0");
		}
	}

	/**
	 * Returns the value that defines how enchantable the item is.
	 *
	 * The higher an item's enchantability is, the more likely it will be to gain high-level enchantments
	 * or multiple enchantments upon being enchanted in an enchanting table.
	 */
	public function getEnchantability() : int{
		return $this->enchantability;
	}

	/**
	 * Returns the sound that plays when equipping the armor
	 */
	public function getEquipSound() : ?Sound{
		return $this->equipSound;
	}

	public function getKnockbackResistance() : float{
		return $this->knockbackResistance;
	}
}
