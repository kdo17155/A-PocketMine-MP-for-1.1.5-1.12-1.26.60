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
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\utils\SingletonTrait;
use function count;
use function ksort;

/**
 * Maps modern (1.13+) blockstate data back to the legacy numeric ID + metadata pairs used by 1.1.x clients.
 *
 * 1.1.x predates the block palette; chunks are sent as raw 1-byte block IDs plus 4-bit metadata, so every
 * blockstate has to be resolved back to its pre-1.13 numeric representation.
 */
final class Legacy113BlockMapper{
	use SingletonTrait;

	/** @var array<string, array{int, int}> */
	private array $stateDataToIdMeta = [];
	/** @var array<int, array{int, int}> */
	private array $cache = [];

	private static function make() : self{
		return new self;
	}

	public function __construct(){
		$palette = LegacyR12BlockStates::getInstance()->getBlockPaletteEntries(ProtocolInfo::PROTOCOL_1_12_0);
		$dictionary = BlockStateDictionary::loadFromLegacyPalette($palette);

		foreach($palette as $i => $entry){
			$data = $dictionary->generateDataFromStateId($i);
			if($data === null){
				continue;
			}
			$key = self::stateDataKey($data);
			if(!isset($this->stateDataToIdMeta[$key])){
				$this->stateDataToIdMeta[$key] = [$entry->getId(), $entry->getMetadata()];
			}
		}
	}

	public static function stateDataKey(BlockStateData $data) : string{
		$states = [];
		foreach($data->getStates() as $name => $tag){
			$states[(string) $name] = $tag->toString();
		}
		ksort($states);

		$parts = [];
		foreach($states as $name => $value){
			$parts[] = $name . "=" . $value;
		}

		return $data->getName() . "|" . \implode(",", $parts);
	}

	/**
	 * @return array{int, int} legacy block ID and metadata
	 */
	public function internalIdToLegacy(BlockTranslator $blockTranslator, int $internalStateId) : array{
		if(isset($this->cache[$internalStateId])){
			return $this->cache[$internalStateId];
		}

		try{
			$data = $blockTranslator->internalIdToNetworkStateData($internalStateId);
			$result = $this->stateDataToIdMeta[self::stateDataKey($data)] ?? null;
		}catch(\Throwable){
			$result = null;
		}

		return $this->cache[$internalStateId] = $result ?? [248, 0]; //info_update
	}

	/** @var array<int, array{int, int}> */
	private array $networkIdCache = [];

	/**
	 * @return array{int, int} legacy block ID and metadata for the given network block state ID
	 */
	public function networkIdToLegacy(BlockTranslator $blockTranslator, int $networkId) : array{
		if(isset($this->networkIdCache[$networkId])){
			return $this->networkIdCache[$networkId];
		}

		$data = $blockTranslator->getBlockStateDictionary()->generateDataFromStateId($networkId);
		$result = $data !== null ? ($this->stateDataToIdMeta[self::stateDataKey($data)] ?? null) : null;

		return $this->networkIdCache[$networkId] = $result ?? [248, 0];
	}

	/**
	 * @return int the number of known legacy blockstates
	 */
	public function getKnownStateCount() : int{
		return count($this->stateDataToIdMeta);
	}
}
