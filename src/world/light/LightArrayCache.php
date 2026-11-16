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

namespace pocketmine\world\light;

use pocketmine\utils\Binary;
use pocketmine\utils\BinaryDataException;
use pocketmine\utils\BinaryStream;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\io\exception\CorruptedWorldException;
use pocketmine\world\format\LightArray;
use pocketmine\world\format\SubChunk;
use function array_values;
use function chr;
use function count;
use function pack;
use function unpack;
use const LEVELDB_ZLIB_RAW_COMPRESSION;

/**
 * Persists the computed light of chunks next to the world, so that a chunk loaded again does not have to go through
 * light population. Terrain providers do not store light: without this, every chunk load costs a LightPopulationTask.
 *
 * The heightmap is stored with the light because light population is also what computes it.
 */
final class LightArrayCache{
	private const FULL_ARRAY = 16;
	private const ARRAY_SIZE = 2048;
	private const HEIGHT_MAP_BYTES = 512;

	private \LevelDB $db;

	/**
	 * @throws CorruptedWorldException
	 */
	public function __construct(string $path){
		try{
			$this->db = new \LevelDB($path, [
				"compression" => LEVELDB_ZLIB_RAW_COMPRESSION,
				"block_size" => 16 * 1024
			]);
		}catch(\LevelDBException $e){
			throw new CorruptedWorldException("Failed to open light cache: " . $e->getMessage(), 0, $e);
		}
	}

	/**
	 * Restores the light of a freshly loaded chunk. Returns whether the chunk is now light populated.
	 */
	public function restore(int $chunkX, int $chunkZ, Chunk $chunk) : bool{
		$data = $this->db->get(self::key($chunkX, $chunkZ));
		if($data === false){
			return false;
		}

		$subChunks = $chunk->getSubChunks();
		try{
			$stream = new BinaryStream($data);
			$heights = unpack("s*", $stream->get(self::HEIGHT_MAP_BYTES));
			if($heights === false){
				return false;
			}
			$restored = 0;
			foreach(self::readArrays($stream) as $y => $array){
				if(!isset($subChunks[$y])){
					return false;
				}
				$subChunks[$y]->setBlockLightArray($array);
				$restored++;
			}
			foreach(self::readArrays($stream) as $y => $array){
				if(!isset($subChunks[$y])){
					return false;
				}
				$subChunks[$y]->setBlockSkyLightArray($array);
				$restored++;
			}
		}catch(BinaryDataException){
			return false;
		}
		if($restored !== 2 * count($subChunks)){
			return false;
		}
		/** @phpstan-var non-empty-list<int> $heightMap */
		$heightMap = array_values($heights);
		$chunk->setHeightMapArray($heightMap);
		$chunk->setLightPopulated(true);

		return true;
	}

	/**
	 * Stores the light of a chunk. A chunk whose light is not computed has its entry dropped instead: what was stored
	 * for it no longer matches its terrain.
	 */
	public function store(int $chunkX, int $chunkZ, Chunk $chunk) : void{
		$key = self::key($chunkX, $chunkZ);
		if($chunk->isLightPopulated() !== true){
			$this->db->delete($key);
			return;
		}

		$subChunks = $chunk->getSubChunks();
		$this->db->put(
			$key,
			pack("s*", ...$chunk->getHeightMapArray()) .
			self::writeArrays(count($subChunks), self::blockLightOf($subChunks)) .
			self::writeArrays(count($subChunks), self::skyLightOf($subChunks))
		);
	}

	public function close() : void{
		unset($this->db);
	}

	/**
	 * @param SubChunk[] $subChunks
	 * @phpstan-param array<int, SubChunk> $subChunks
	 *
	 * @return \Generator|LightArray[]
	 * @phpstan-return \Generator<int, LightArray, void, void>
	 */
	private static function blockLightOf(array $subChunks) : \Generator{
		foreach($subChunks as $y => $subChunk){
			yield $y => $subChunk->getBlockLightArray();
		}
	}

	/**
	 * @param SubChunk[] $subChunks
	 * @phpstan-param array<int, SubChunk> $subChunks
	 *
	 * @return \Generator|LightArray[]
	 * @phpstan-return \Generator<int, LightArray, void, void>
	 */
	private static function skyLightOf(array $subChunks) : \Generator{
		foreach($subChunks as $y => $subChunk){
			yield $y => $subChunk->getBlockSkyLightArray();
		}
	}

	/**
	 * @return \Generator|LightArray[]
	 * @phpstan-return \Generator<int, LightArray, void, void>
	 */
	private static function readArrays(BinaryStream $stream) : \Generator{
		for($i = 0, $count = $stream->getByte(); $i < $count; $i++){
			$y = Binary::signByte($stream->getByte());
			$level = $stream->getByte();
			yield $y => $level === self::FULL_ARRAY ? new LightArray($stream->get(self::ARRAY_SIZE)) : LightArray::fill($level);
		}
	}

	/**
	 * @param \Generator|LightArray[] $arrays
	 * @phpstan-param \Generator<int, LightArray, void, void> $arrays
	 */
	private static function writeArrays(int $count, \Generator $arrays) : string{
		$result = chr($count);
		foreach($arrays as $y => $array){
			$level = $array->get(0, 0, 0);
			$result .= chr($y & 0xff) . ($array->isUniform($level) ? chr($level) : chr(self::FULL_ARRAY) . $array->getData());
		}

		return $result;
	}

	private static function key(int $chunkX, int $chunkZ) : string{
		return pack("ll", $chunkX, $chunkZ);
	}
}
