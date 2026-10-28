<?php

declare(strict_types=1);

use pocketmine\nbt\LittleEndianNbtSerializer;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\TreeRoot;

/**
 * Encodage et decodage NBT : chaque etat de bloc custom, chaque tuile et chaque objet enrichi
 * traverse ce chemin, y compris dans les workers qui serialisent les chunks.
 */
return [
	"nom" => "nbt (LittleEndian)",
	"iterations" => 50000,
	"requiert" => ["extension" => "encoding", "classe" => \pmmp\encoding\ByteBufferReader::class],
	"run" => static function () : void {
		$serialiseur = new LittleEndianNbtSerializer();
		$etat = CompoundTag::create()
			->setString("name", "minecraft:oak_fence")
			->setTag("states", CompoundTag::create()
				->setByte("minecraft:connection_north", 1)
				->setByte("minecraft:connection_south", 0)
				->setByte("minecraft:connection_east", 1)
				->setByte("minecraft:connection_west", 0));

		$octets = $serialiseur->write(new TreeRoot($etat));
		$serialiseur->read($octets)->mustGetCompoundTag();
	},
];
