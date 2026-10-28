<?php

declare(strict_types=1);

use pocketmine\utils\Binary;
use pocketmine\utils\BinaryStream;

/**
 * Lecture et ecriture de tampons : tout paquet reseau passe par la, dans les deux sens.
 */
return [
	"nom" => "binaryutils (BinaryStream)",
	"iterations" => 100000,
	"run" => static function () : void {
		$flux = new BinaryStream();
		$flux->putVarInt(2225);
		$flux->putUnsignedVarInt(123456);
		$flux->putLFloat(1.5);
		$flux->putLInt(0x2a2a2a2a);
		$flux->putLLong(0x7fffffff);

		$relecture = new BinaryStream($flux->getBuffer());
		$relecture->getVarInt();
		$relecture->getUnsignedVarInt();
		$relecture->getLFloat();
		$relecture->getLInt();
		$relecture->getLLong();

		Binary::writeInt(42);
		Binary::readInt("\x00\x00\x00\x2a");
	},
];
