<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
 */

declare(strict_types=1);

namespace pocketmine\network\mcpe\convert;

use pocketmine\data\bedrock\block\BlockStateData;
use pocketmine\data\bedrock\block\BlockTypeNames;
use pocketmine\nbt\NbtDataException;
use pocketmine\nbt\TreeRoot;
use pocketmine\network\mcpe\protocol\serializer\NetworkNbtSerializer;
use pocketmine\network\mcpe\protocol\types\LegacyBlockPaletteEntry;
use pocketmine\utils\BinaryStream;
use pocketmine\utils\Utils;
use pocketmine\world\format\io\GlobalBlockStateHandlers;
use function array_key_first;
use function array_map;
use function count;
use function get_debug_type;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use const JSON_THROW_ON_ERROR;

/**
 * Handles translation of network block runtime IDs into blockstate data, and vice versa
 */
final class BlockStateDictionary{
	/**
	 * @var int[][]|int[]
	 * @phpstan-var array<string, array<string, int>|int>
	 */
	private array $stateDataToStateIdLookup = [];

	/**
	 * @var int[][]|null
	 * @phpstan-var array<string, array<int, int>|int>|null
	 */
	private ?array $idMetaToStateIdLookupCache = null;

	/**
	 * @param BlockStateDictionaryEntry[] $states
	 *
	 * @phpstan-param list<BlockStateDictionaryEntry> $states
	 */
	public function __construct(
		private array $states
	){
		$table = [];
		foreach($this->states as $stateId => $stateNbt){
			$table[$stateNbt->getStateName()][$stateNbt->getRawStateProperties()] = $stateId;
		}

		//setup fast path for stateless blocks
		foreach(Utils::stringifyKeys($table) as $name => $stateIds){
			if(count($stateIds) === 1){
				$this->stateDataToStateIdLookup[$name] = $stateIds[array_key_first($stateIds)];
			}else{
				$this->stateDataToStateIdLookup[$name] = $stateIds;
			}
		}

		$standardSkull = $this->stateDataToStateIdLookup[BlockTypeNames::SKELETON_SKULL];
		foreach([
			BlockTypeNames::WITHER_SKELETON_SKULL,
			BlockTypeNames::ZOMBIE_HEAD,
			BlockTypeNames::PLAYER_HEAD,
			BlockTypeNames::CREEPER_HEAD,
			BlockTypeNames::DRAGON_HEAD,
			BlockTypeNames::PIGLIN_HEAD
		] as $skull){
			if(!isset($this->stateDataToStateIdLookup[$skull])){
				$this->stateDataToStateIdLookup[$skull] = $standardSkull;
			}
		}
	}

	/**
	 * @return int[][]
	 * @phpstan-return array<string, array<int, int>|int>
	 */
	private function getIdMetaToStateIdLookup() : array{
		if($this->idMetaToStateIdLookupCache === null){
			$table = [];
			//TODO: if we ever allow mutating the dictionary, this would need to be rebuilt on modification

			foreach($this->states as $i => $state){
				$table[$state->getStateName()][$state->getMeta()] = $i;
			}

			$this->idMetaToStateIdLookupCache = [];
			foreach(Utils::stringifyKeys($table) as $name => $metaToStateId){
				//if only one meta value exists
				if(count($metaToStateId) === 1){
					$this->idMetaToStateIdLookupCache[$name] = $metaToStateId[array_key_first($metaToStateId)];
				}else{
					$this->idMetaToStateIdLookupCache[$name] = $metaToStateId;
				}
			}
		}

		return $this->idMetaToStateIdLookupCache;
	}

	public function generateDataFromStateId(int $networkRuntimeId) : ?BlockStateData{
		return ($this->states[$networkRuntimeId] ?? null)?->generateStateData();
	}

	public function generateCurrentDataFromStateId(int $networkRuntimeId) : ?BlockStateData{
		return ($this->states[$networkRuntimeId] ?? null)?->generateCurrentStateData();
	}

	/**
	 * Searches for the appropriate state ID which matches the given blockstate NBT.
	 * Returns null if there were no matches.
	 */
	public function lookupStateIdFromData(BlockStateData $data) : ?int{
		$name = $data->getName();

		$lookup = $this->stateDataToStateIdLookup[$name] ?? null;
		return match(true){
			$lookup === null => null,
			is_int($lookup) => $lookup,
			is_array($lookup) => $lookup[BlockStateDictionaryEntry::encodeStateProperties($data->getStates())] ?? null
		};
	}

	/**
	 * Returns the blockstate meta value associated with the given blockstate runtime ID.
	 * This is used for serializing crafting recipe inputs.
	 */
	public function getMetaFromStateId(int $networkRuntimeId) : ?int{
		return ($this->states[$networkRuntimeId] ?? null)?->getMeta();
	}

	/**
	 * Returns the blockstate data associated with the given block ID and meta value.
	 * This is used for deserializing crafting recipe inputs.
	 */
	public function lookupStateIdFromIdMeta(string $id, int $meta) : ?int{
		$metas = $this->getIdMetaToStateIdLookup()[$id] ?? null;
		return match(true){
			$metas === null => null,
			is_int($metas) => $metas,
			is_array($metas) => $metas[$meta] ?? null
		};
	}

	/**
	 * Returns an array mapping runtime ID => blockstate data.
	 * @return BlockStateDictionaryEntry[]
	 * @phpstan-return array<int, BlockStateDictionaryEntry>
	 */
	public function getStates() : array{ return $this->states; }

	/**
	 * @return BlockStateData[]
	 * @phpstan-return list<BlockStateData>
	 *
	 * @throws NbtDataException
	 */
	public static function loadPaletteFromString(string $blockPaletteContents) : array{
		return array_map(
			fn(TreeRoot $root) => BlockStateData::fromNbt($root->mustGetCompoundTag()),
			(new NetworkNbtSerializer())->readMultiple($blockPaletteContents)
		);
	}

	public static function loadFromString(string $blockPaletteContents, string $metaMapContents) : self{
		$metaMap = json_decode($metaMapContents, flags: JSON_THROW_ON_ERROR);
		if(!is_array($metaMap)){
			throw new \InvalidArgumentException("Invalid metaMap, expected array for root type, got " . get_debug_type($metaMap));
		}
		foreach($metaMap as $meta){
			if(!is_int($meta)){
				throw new \InvalidArgumentException("Invalid metaMap entry, expected int, got " . get_debug_type($meta));
			}
		}
		/** @var array<int, int> $metaMap */

		return self::loadFromPaletteAndMetaMap(self::loadPaletteFromString($blockPaletteContents), $metaMap);
	}

	/**
	 * Loads the pre-1.16.100 palette metadata from the legacy-to-current mapping shipped by BedrockData.
	 */
	public static function loadFromLegacyStateMap(string $blockPaletteContents, string $legacyStateMapContents) : self{
		$metaByState = [];
		$stream = new BinaryStream($legacyStateMapContents);
		$nbtReader = new NetworkNbtSerializer();
		while(!$stream->feof()){
			$stream->get($stream->getUnsignedVarInt()); //legacy string ID; the palette state contains the canonical ID
			$meta = $stream->getLShort();

			$offset = $stream->getOffset();
			$state = BlockStateData::fromNbt($nbtReader->read($stream->getBuffer(), $offset)->mustGetCompoundTag());
			$stream->setOffset($offset);
			$metaByState[$state->getName()][BlockStateDictionaryEntry::encodeStateProperties($state->getStates())] = $meta;
		}

		$palette = self::loadPaletteFromString($blockPaletteContents);
		$metaMap = [];
		foreach($palette as $i => $state){
			//Some palette states were never reachable through a legacy ID/meta pair.
			//They still need to remain in the runtime palette, but metadata 0 is
			//sufficient for recipe/item fallback lookups.
			$metaMap[$i] = $metaByState[$state->getName()][BlockStateDictionaryEntry::encodeStateProperties($state->getStates())] ?? 0;
		}

		return self::loadFromPaletteAndMetaMap($palette, $metaMap);
	}

	/**
	 * @param LegacyBlockPaletteEntry[] $blockPalette
	 */
	public static function loadFromLegacyPalette(array $blockPalette) : self{
		$upgrader = GlobalBlockStateHandlers::getUpgrader();
		$entries = [];
		foreach($blockPalette as $entry){
			$newState = $upgrader->upgradeIntIdMeta($entry->getId(), $entry->getMetadata());
			$entries[] = new BlockStateDictionaryEntry(
				$newState->getName(),
				$newState->getStates(),
				$entry->getMetadata(),
				null
			);
		}
		return new self($entries);
	}

	/**
	 * @param BlockStateData[] $palette
	 * @param int[]            $metaMap
	 *
	 * @phpstan-param list<BlockStateData> $palette
	 * @phpstan-param array<int, int> $metaMap
	 */
	private static function loadFromPaletteAndMetaMap(array $palette, array $metaMap) : self{
		$upgrader = GlobalBlockStateHandlers::getUpgrader()->getBlockStateUpgrader();
		$entries = [];

		$uniqueNames = [];

		//this hack allows the internal cache index to use interned strings which are already available in the
		//core code anyway, saving around 40 KB of memory
		foreach((new \ReflectionClass(BlockTypeNames::class))->getConstants() as $value){
			if(is_string($value)){
				$uniqueNames[$value] = $value;
			}
		}

		foreach($palette as $i => $state){
			$meta = $metaMap[$i] ?? null;
			if($meta === null){
				throw new \InvalidArgumentException("Missing associated meta value for state $i (" . $state->toNbt() . ")");
			}
			if(!is_int($meta)){
				throw new \InvalidArgumentException("Invalid metaMap offset $i, expected int, got " . get_debug_type($meta));
			}
			$newState = $upgrader->upgrade($state);
			$uniqueName = $uniqueNames[$newState->getName()] ??= $newState->getName();
			$entries[$i] = new BlockStateDictionaryEntry($uniqueName, $newState->getStates(), $meta, $newState->equals($state) ? null : $state);
		}

		return new self($entries);
	}
}
