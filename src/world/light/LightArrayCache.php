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
use pocketmine\utils\Filesystem;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\LightArray;
use pocketmine\world\format\SubChunk;
use function array_values;
use function chr;
use function count;
use function hash;
use function hash_final;
use function hash_init;
use function hash_update;
use function is_dir;
use function pack;
use function strlen;
use function substr;
use function unpack;
use const LEVELDB_ZLIB_RAW_COMPRESSION;

/**
 * Persists the computed light of chunks next to the world, so that a chunk loaded again does not have to go through
 * light population. Terrain providers do not store light: without this, every chunk load costs a LightPopulationTask.
 *
 * The heightmap is stored with the light because light population is also what computes it.
 *
 * Every entry carries a fingerprint of the terrain it was computed from, and is only restored onto that exact terrain:
 * a world edited by another tool, restored from a backup or regenerated falls back to light population.
 *
 * The fingerprint identifies the terrain as the world provider holds it, not as it sits in memory, where a chunk can be
 * repacked without being written again. It is therefore taken when a chunk is loaded or right after it is saved (see
 * fingerprint()), and handed back here.
 */
final class LightArrayCache{
	private const ENTRY_VERSION = 2;
	private const FINGERPRINT_SIZE = 16;
	private const HEADER_SIZE = 1 + self::FINGERPRINT_SIZE;

	private const FULL_ARRAY = 16;
	private const ARRAY_SIZE = 2048;
	private const HEIGHT_MAP_BYTES = 512;

	private const WRITE_BUFFER_SIZE = 16 * 1024 * 1024;
	private const BLOCK_CACHE_SIZE = 16 * 1024 * 1024;

	private \LevelDB $db;

	/**
	 * Hash of the entry the database holds for each loaded chunk, so that unchanged light is not written again. A
	 * loaded chunk has an entry here exactly when the database holds a valid one for it.
	 *
	 * @var string[]
	 * @phpstan-var array<string, string>
	 */
	private array $storedHashes = [];

	/**
	 * @throws \LevelDBException
	 */
	private function __construct(string $path){
		$this->db = new \LevelDB($path, [
			"compression" => LEVELDB_ZLIB_RAW_COMPRESSION,
			"block_size" => 16 * 1024,
			"write_buffer_size" => self::WRITE_BUFFER_SIZE,
			"block_cache_size" => self::BLOCK_CACHE_SIZE
		]);
	}

	/**
	 * Opens the cache of a world. The cache only holds data that can be computed again, so one that cannot be opened
	 * (left corrupt by a power loss, for instance) is wiped and started over rather than keeping the world from
	 * loading. Returns null when even that fails: the world then runs without a cache.
	 */
	public static function open(string $path, \Logger $logger) : ?self{
		try{
			return new self($path);
		}catch(\LevelDBException $e){
			$logger->warning("Light cache could not be opened and will be rebuilt: " . $e->getMessage());
		}
		try{
			if(is_dir($path)){
				Filesystem::recursiveUnlink($path);
			}
			return new self($path);
		}catch(\LevelDBException | \RuntimeException $e){
			$logger->error("Light cache is unavailable, light will be computed on every chunk load: " . $e->getMessage());
			return null;
		}
	}

	/**
	 * Restores the light of a freshly loaded chunk. Returns whether the chunk is now light populated. An entry that
	 * does not belong to this terrain, or cannot be read, is removed.
	 *
	 * @param string $terrain fingerprint of the chunk as it was just loaded
	 */
	public function restore(int $chunkX, int $chunkZ, Chunk $chunk, string $terrain) : bool{
		$key = self::key($chunkX, $chunkZ);
		$data = $this->db->get($key);
		if($data === false){
			return false;
		}
		if(
			strlen($data) < self::HEADER_SIZE ||
			$data[0] !== chr(self::ENTRY_VERSION) ||
			substr($data, 1, self::FINGERPRINT_SIZE) !== $terrain ||
			!$this->apply($data, $chunk)
		){
			$this->db->delete($key);
			return false;
		}
		$this->storedHashes[$key] = self::hash($data);

		return true;
	}

	private function apply(string $data, Chunk $chunk) : bool{
		$subChunks = $chunk->getSubChunks();
		try{
			$stream = new BinaryStream($data);
			$stream->get(self::HEADER_SIZE);
			$heights = unpack("s*", $stream->get(self::HEIGHT_MAP_BYTES));
			if($heights === false){
				return false;
			}
			$restored = 0;
			foreach(self::readArrays($stream) as $y => $array){
				if(!isset($subChunks[$y])){
					return false;
				}
				if($array instanceof LightArray){
					$subChunks[$y]->setBlockLightArray($array);
				}else{
					$subChunks[$y]->setUniformBlockLight($array);
				}
				$restored++;
			}
			foreach(self::readArrays($stream) as $y => $array){
				if(!isset($subChunks[$y])){
					return false;
				}
				if($array instanceof LightArray){
					$subChunks[$y]->setBlockSkyLightArray($array);
				}else{
					$subChunks[$y]->setUniformBlockSkyLight($array);
				}
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
	 * Stores the light of a chunk, tied to its terrain. A chunk whose light is not computed has its entry dropped
	 * instead.
	 *
	 * @param string $terrain fingerprint of the chunk as the world provider holds it
	 */
	public function store(int $chunkX, int $chunkZ, Chunk $chunk, string $terrain) : void{
		if($chunk->isLightPopulated() !== true){
			$this->drop($chunkX, $chunkZ);
			return;
		}

		$key = self::key($chunkX, $chunkZ);
		$subChunks = $chunk->getSubChunks();
		$data = chr(self::ENTRY_VERSION) . $terrain .
			pack("s*", ...$chunk->getHeightMapArray()) .
			self::writeArrays(count($subChunks), self::blockLightOf($subChunks)) .
			self::writeArrays(count($subChunks), self::skyLightOf($subChunks));
		$hash = self::hash($data);
		if(($this->storedHashes[$key] ?? null) !== $hash){
			$this->db->put($key, $data);
			$this->storedHashes[$key] = $hash;
		}
	}

	/**
	 * Removes the entry of a chunk, loaded or not.
	 */
	public function drop(int $chunkX, int $chunkZ) : void{
		$key = self::key($chunkX, $chunkZ);
		$this->db->delete($key);
		unset($this->storedHashes[$key]);
	}

	/**
	 * Returns whether the database holds a valid entry for a loaded chunk.
	 */
	public function has(int $chunkX, int $chunkZ) : bool{
		return isset($this->storedHashes[self::key($chunkX, $chunkZ)]);
	}

	/**
	 * Called once a chunk has left memory: nothing needs to be remembered about it until it is loaded again.
	 */
	public function forget(int $chunkX, int $chunkZ) : void{
		unset($this->storedHashes[self::key($chunkX, $chunkZ)]);
	}

	public function close() : void{
		unset($this->db);
	}

	/**
	 * Yields the block light of each sub chunk: its level when it is uniform, its array otherwise. A sub chunk that
	 * shares a uniform array is read through its level, so that storing it does not give it a private copy.
	 *
	 * @param SubChunk[] $subChunks
	 * @phpstan-param array<int, SubChunk> $subChunks
	 *
	 * @return \Generator|LightArray[]|int[]
	 * @phpstan-return \Generator<int, LightArray|int, void, void>
	 */
	private static function blockLightOf(array $subChunks) : \Generator{
		foreach($subChunks as $y => $subChunk){
			yield $y => $subChunk->getUniformBlockLight() ?? $subChunk->getBlockLightArray();
		}
	}

	/**
	 * Same as blockLightOf(), for sky light.
	 *
	 * @param SubChunk[] $subChunks
	 * @phpstan-param array<int, SubChunk> $subChunks
	 *
	 * @return \Generator|LightArray[]|int[]
	 * @phpstan-return \Generator<int, LightArray|int, void, void>
	 */
	private static function skyLightOf(array $subChunks) : \Generator{
		foreach($subChunks as $y => $subChunk){
			yield $y => $subChunk->getUniformBlockSkyLight() ?? $subChunk->getBlockSkyLightArray();
		}
	}

	/**
	 * Yields what was stored for each sub chunk: a level for uniform light, an array otherwise.
	 *
	 * @return \Generator|LightArray[]|int[]
	 * @phpstan-return \Generator<int, LightArray|int, void, void>
	 */
	private static function readArrays(BinaryStream $stream) : \Generator{
		for($i = 0, $count = $stream->getByte(); $i < $count; $i++){
			$y = Binary::signByte($stream->getByte());
			$level = $stream->getByte();
			if($level > self::FULL_ARRAY){
				throw new BinaryDataException("Invalid light level $level");
			}
			yield $y => $level === self::FULL_ARRAY ? new LightArray($stream->get(self::ARRAY_SIZE)) : $level;
		}
	}

	/**
	 * @param \Generator|LightArray[]|int[] $arrays
	 * @phpstan-param \Generator<int, LightArray|int, void, void> $arrays
	 */
	private static function writeArrays(int $count, \Generator $arrays) : string{
		$result = chr($count);
		foreach($arrays as $y => $light){
			if($light instanceof LightArray){
				$level = $light->get(0, 0, 0);
				$result .= chr($y & 0xff) . ($light->isUniform($level) ? chr($level) : chr(self::FULL_ARRAY) . $light->getData());
			}else{
				$result .= chr($y & 0xff) . chr($light);
			}
		}

		return $result;
	}

	/**
	 * Identifies the block data of a chunk in its current form. This only describes what the world provider holds when
	 * the chunk has just been loaded, or just been saved with its blocks. Light only depends on blocks, so biomes are
	 * left out.
	 */
	public static function fingerprint(Chunk $chunk) : string{
		$context = hash_init("xxh128");
		foreach($chunk->getSubChunks() as $y => $subChunk){
			$layers = $subChunk->getBlockLayers();
			hash_update($context, pack("cVC", $y, $subChunk->getEmptyBlockId(), count($layers)));
			foreach($layers as $layer){
				$palette = $layer->getPalette();
				hash_update($context, pack("CV", $layer->getBitsPerBlock(), count($palette)));
				hash_update($context, $layer->getWordArray());
				hash_update($context, pack("V*", ...$palette));
			}
		}

		return hash_final($context, true);
	}

	private static function hash(string $data) : string{
		return hash("xxh128", $data, true);
	}

	private static function key(int $chunkX, int $chunkZ) : string{
		return pack("ll", $chunkX, $chunkZ);
	}
}
