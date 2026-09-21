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

namespace pocketmine\timings;

use pocketmine\utils\BinaryStream;
use function array_key_first;
use function array_pop;
use function asort;
use function base64_encode;
use function count;
use function gzencode;
use function hrtime;
use function max;

/**
 * Records, for each tick, every timer call with its nesting and start/end times so the slowest ticks
 * can be displayed as a flame chart. Only the SLOWEST_TICKS_KEPT slowest ticks are retained.
 *
 * Section layout (gzip + base64): byte version, uvarint tickCount, then per tick: uvarint tick,
 * bool isGap, [uvarint previousTick], uvarint durationNs, uvarint entryCount, then per entry in
 * depth-first order: uvarint depth, uvarint recordId, uvarint startNs (delta from the previous
 * entry's start), uvarint durationNs. recordId matches RecordId in the timer lines above.
 */
final class TimingsTimeline{
	public const FORMAT_VERSION = 1;
	public const SECTION_START = "###TIMELINE v" . self::FORMAT_VERSION . "###";
	public const SECTION_END = "###TIMELINE END###";

	private const SLOWEST_TICKS_KEPT = 100;

	private static bool $enabled = false;
	private static bool $recording = false;
	private static int $tick = 0;
	private static ?int $previousTick = null;
	private static int $tickStart = 0;
	private static int $depth = 0;

	/** @var int[] flat list of depth, recordId, startNs, endNs per entry */
	private static array $entries = [];
	/** @var int[] indexes into $entries of the entries not yet ended */
	private static array $stack = [];

	/**
	 * @var string[] serialized ticks
	 * @phpstan-var array<int, string>
	 */
	private static array $ticks = [];
	/**
	 * @var int[] time spent in timers per tick, same keys as $ticks
	 * @phpstan-var array<int, int>
	 */
	private static array $durations = [];
	private static int $nextTickKey = 0;

	public static function isEnabled() : bool{
		return self::$enabled;
	}

	public static function setEnabled(bool $enabled) : void{
		self::$enabled = $enabled;
		if(!$enabled){
			self::reset();
		}
	}

	public static function reset() : void{
		self::$recording = false;
		self::$entries = [];
		self::$stack = [];
		self::$depth = 0;
		self::$ticks = [];
		self::$durations = [];
	}

	/**
	 * @param int|null $previousTick set when recording the gap between two ticks rather than a tick itself
	 */
	public static function newTick(int $tick, ?int $previousTick) : void{
		if(!self::$enabled || !TimingsHandler::isEnabled()){
			return;
		}
		self::$recording = true;
		self::$tick = $tick;
		self::$previousTick = $previousTick;
		self::$tickStart = hrtime(true);
		self::$depth = 0;
		self::$entries = [];
		self::$stack = [];
	}

	public static function endTick() : void{
		if(!self::$recording){
			return;
		}
		$now = hrtime(true);
		foreach(self::$stack as $index){
			self::$entries[$index + 3] = $now - self::$tickStart;
		}
		self::$stack = [];
		self::$recording = false;

		$duration = $now - self::$tickStart;
		//the gap between two ticks is mostly sleep, so rank it by the time actually spent in timers
		$busyTime = self::$previousTick === null ? $duration : self::getRootEntriesTime();
		if(count(self::$ticks) >= self::SLOWEST_TICKS_KEPT){
			asort(self::$durations);
			$fastest = array_key_first(self::$durations);
			if($fastest === null || self::$durations[$fastest] >= $busyTime){
				self::$entries = [];
				return;
			}
			unset(self::$ticks[$fastest], self::$durations[$fastest]);
		}

		$stream = new BinaryStream();
		$stream->putUnsignedVarLong(self::$tick);
		$stream->putBool(self::$previousTick !== null);
		if(self::$previousTick !== null){
			$stream->putUnsignedVarLong(self::$previousTick);
		}
		$stream->putUnsignedVarLong($duration);
		$stream->putUnsignedVarInt(count(self::$entries) >> 2);
		$previousStart = 0;
		for($i = 0, $count = count(self::$entries); $i < $count; $i += 4){
			$start = self::$entries[$i + 2];
			$stream->putUnsignedVarInt(self::$entries[$i]);
			$stream->putUnsignedVarInt(self::$entries[$i + 1]);
			$stream->putUnsignedVarLong($start - $previousStart);
			$stream->putUnsignedVarLong(self::$entries[$i + 3] - $start);
			$previousStart = $start;
		}
		$key = self::$nextTickKey++;
		self::$ticks[$key] = $stream->getBuffer();
		self::$durations[$key] = $busyTime;
		self::$entries = [];
	}

	private static function getRootEntriesTime() : int{
		$total = 0;
		for($i = 0, $count = count(self::$entries); $i < $count; $i += 4){
			if(self::$entries[$i] === 0){
				$total += self::$entries[$i + 3] - self::$entries[$i + 2];
			}
		}
		return $total;
	}

	public static function startEntry(TimingsRecord $record, int $now) : void{
		if(!self::$recording){
			return;
		}
		$index = count(self::$entries);
		self::$entries[] = self::$depth++;
		self::$entries[] = $record->getId();
		self::$entries[] = max(0, $now - self::$tickStart);
		self::$entries[] = 0;
		self::$stack[] = $index;
	}

	public static function endEntry(int $now) : void{
		if(!self::$recording || self::$stack === []){
			return;
		}
		$index = array_pop(self::$stack);
		self::$depth--;
		self::$entries[$index + 3] = max(self::$entries[$index + 2], $now - self::$tickStart);
	}

	public static function hasData() : bool{
		return self::$ticks !== [];
	}

	/**
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	public static function printSection() : array{
		$stream = new BinaryStream();
		$stream->putByte(self::FORMAT_VERSION);
		$stream->putUnsignedVarInt(count(self::$ticks));
		foreach(self::$ticks as $tick){
			$stream->put($tick);
		}
		return [self::SECTION_START, base64_encode(gzencode($stream->getBuffer(), 6)), self::SECTION_END];
	}
}
