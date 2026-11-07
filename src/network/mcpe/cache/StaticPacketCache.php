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

namespace pocketmine\network\mcpe\cache;

use pocketmine\color\Color;
use pocketmine\data\bedrock\BedrockDataFiles;
use pocketmine\data\SavedDataLoadingException;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\AvailableActorIdentifiersPacket;
use pocketmine\network\mcpe\protocol\BiomeDefinitionListPacket;
use pocketmine\network\mcpe\protocol\JigsawStructureDataPacket;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\serializer\NetworkNbtSerializer;
use pocketmine\network\mcpe\protocol\types\biome\BiomeDefinitionEntry;
use pocketmine\network\mcpe\protocol\types\BlockPaletteEntry;
use pocketmine\network\mcpe\protocol\types\CacheableNbt;
use pocketmine\network\mcpe\protocol\types\SerializableVoxelCells;
use pocketmine\network\mcpe\protocol\types\SerializableVoxelShape;
use pocketmine\network\mcpe\protocol\VoxelShapesPacket;
use pocketmine\utils\AssumptionFailedError;
use pocketmine\utils\Filesystem;
use pocketmine\utils\SingletonTrait;
use pocketmine\utils\Utils;
use pocketmine\world\biome\model\BiomeDefinitionEntryData;
use function count;
use function get_debug_type;
use function gzdecode;
use function is_array;
use function json_decode;
use function krsort;
use function scandir;
use const JSON_THROW_ON_ERROR;
use const pocketmine\RESOURCE_PATH;
use const SCANDIR_SORT_NONE;

class StaticPacketCache{
	use SingletonTrait;

	/**
	 * @phpstan-return CacheableNbt<\pocketmine\nbt\tag\CompoundTag>
	 */
	protected static function loadCompoundFromFile(string $filePath) : CacheableNbt{
		return new CacheableNbt((new NetworkNbtSerializer())->read(Filesystem::fileGetContents($filePath))->mustGetCompoundTag());
	}

	/**
	 * @return list<BiomeDefinitionEntry>
	 */
	private static function loadBiomeDefinitionModel(string $filePath) : array{
		$biomeEntries = json_decode(Filesystem::fileGetContents($filePath), associative: true);
		if(!is_array($biomeEntries)){
			throw new SavedDataLoadingException("$filePath root should be an array, got " . get_debug_type($biomeEntries));
		}

		$jsonMapper = new \JsonMapper();
		$jsonMapper->bExceptionOnMissingData = true;
		$jsonMapper->bStrictObjectTypeChecking = true;
		$jsonMapper->bEnforceMapType = false;

		$entries = [];
		foreach(Utils::promoteKeys($biomeEntries) as $biomeName => $entry){
			if(!is_array($entry)){
				throw new SavedDataLoadingException("$filePath should be an array of objects, got " . get_debug_type($entry));
			}

			try{
				$biomeDefinition = $jsonMapper->map($entry, new BiomeDefinitionEntryData());

				$mapWaterColour = $biomeDefinition->mapWaterColour;
				$entries[] = new BiomeDefinitionEntry(
					(string) $biomeName,
					$biomeDefinition->id,
					$biomeDefinition->temperature,
					$biomeDefinition->downfall,
					$biomeDefinition->redSporeDensity,
					$biomeDefinition->blueSporeDensity,
					$biomeDefinition->ashDensity,
					$biomeDefinition->whiteAshDensity,
					$biomeDefinition->foliageSnow,
					$biomeDefinition->depth,
					$biomeDefinition->scale,
					new Color(
						$mapWaterColour->r,
						$mapWaterColour->g,
						$mapWaterColour->b,
						$mapWaterColour->a
					),
					$biomeDefinition->rain,
					count($biomeDefinition->tags) > 0 ? $biomeDefinition->tags : null,
				);
			}catch(\JsonMapper_Exception $e){
				throw new \RuntimeException($e->getMessage(), 0, $e);
			}
		}

		return $entries;
	}

	private static function make() : self{
		//Per-protocol vanilla spawn data extracted from BDS (jigsaw structures, voxel shapes,
		//data-driven blocks) - required by 1.26.50+ clients during world initialization.
		$voxelShapes = [];
		$jigsawStructures = [];
		$dataDrivenBlocks = [];
		$spawnDataDir = RESOURCE_PATH . "vanilla/spawn_data/";
		if(is_dir($spawnDataDir)){
			foreach(scandir($spawnDataDir, SCANDIR_SORT_NONE) as $protocolDir){
				if($protocolDir === "." || $protocolDir === ".."){
					continue;
				}
				$protocol = (int) $protocolDir;
				$directory = $spawnDataDir . $protocolDir . "/";
				if(file_exists($directory . "voxel_shapes.json.gz")){
					$voxelShapes[$protocol] = self::loadVoxelShapes($directory . "voxel_shapes.json.gz");
				}
				if(file_exists($directory . "jigsaw_structures_data.nbt.gz")){
					$jigsawStructures[$protocol] = JigsawStructureDataPacket::create(new CacheableNbt(self::loadGzNetworkNbt($directory . "jigsaw_structures_data.nbt.gz")));
				}
				if(file_exists($directory . "data_driven_blocks.nbt.gz")){
					$dataDrivenBlocks[$protocol] = self::loadDataDrivenBlocks($directory . "data_driven_blocks.nbt.gz");
				}
			}
		}
		krsort($voxelShapes);
		krsort($jigsawStructures);
		krsort($dataDrivenBlocks);

		return new self(
			BiomeDefinitionListPacket::fromDefinitions(self::loadBiomeDefinitionModel(BedrockDataFiles::BIOME_DEFINITIONS_JSON)),
			BiomeDefinitionListPacket::createLegacy(self::loadCompoundFromFile(BedrockDataFiles::BIOME_DEFINITIONS_NBT)),
			BiomeDefinitionListPacket::createLegacy(self::loadCompoundFromFile(BedrockDataFiles::BIOME_DEFINITIONS_1_16_100_NBT)),
			BiomeDefinitionListPacket::createLegacy(self::loadCompoundFromFile(BedrockDataFiles::BIOME_DEFINITIONS_1_18_10_NBT)),
			BiomeDefinitionListPacket::createLegacy(self::loadCompoundFromFile(BedrockDataFiles::BIOME_DEFINITIONS_1_19_0_NBT)),
			BiomeDefinitionListPacket::createLegacy(self::loadCompoundFromFile(BedrockDataFiles::BIOME_DEFINITIONS_1_19_30_NBT)),
			BiomeDefinitionListPacket::createLegacy(self::loadCompoundFromFile(BedrockDataFiles::BIOME_DEFINITIONS_1_19_60_NBT)),
			AvailableActorIdentifiersPacket::create(self::loadCompoundFromFile(BedrockDataFiles::ENTITY_IDENTIFIERS_NBT)),
			AvailableActorIdentifiersPacket::create(self::loadCompoundFromFile(BedrockDataFiles::ENTITY_IDENTIFIERS_1_16_100_NBT)),
			AvailableActorIdentifiersPacket::create(self::loadCompoundFromFile(BedrockDataFiles::ENTITY_IDENTIFIERS_1_17_0_NBT)),
			AvailableActorIdentifiersPacket::create(self::loadCompoundFromFile(BedrockDataFiles::ENTITY_IDENTIFIERS_1_19_0_NBT)),
			AvailableActorIdentifiersPacket::create(self::loadCompoundFromFile(BedrockDataFiles::ENTITY_IDENTIFIERS_1_19_20_NBT)),
			AvailableActorIdentifiersPacket::create(self::loadCompoundFromFile(BedrockDataFiles::ENTITY_IDENTIFIERS_1_19_80_NBT)),
			$voxelShapes,
			$jigsawStructures,
			$dataDrivenBlocks
		);
	}

	private static function loadGzNetworkNbt(string $file) : CompoundTag{
		$raw = gzdecode(Filesystem::fileGetContents($file));
		if($raw === false){
			throw new AssumptionFailedError("$file should contain valid gzip data");
		}
		$root = (new NetworkNbtSerializer())->read($raw)->mustGetCompoundTag();
		return $root;
	}

	private static function loadVoxelShapes(string $file) : VoxelShapesPacket{
		$raw = gzdecode(Filesystem::fileGetContents($file));
		if($raw === false){
			throw new AssumptionFailedError("$file should contain valid gzip data");
		}
		$data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
		if(!is_array($data)){
			throw new SavedDataLoadingException("$file root should be an array, got " . get_debug_type($data));
		}
		$shapes = [];
		foreach($data["shapes"] as $shape){
			$cells = $shape["cells"];
			$shapes[] = new SerializableVoxelShape(
				new SerializableVoxelCells($cells["xSize"], $cells["ySize"], $cells["zSize"], $cells["storage"]),
				array_map('floatval', $shape["x"]),
				array_map('floatval', $shape["y"]),
				array_map('floatval', $shape["z"])
			);
		}
		$nameMap = [];
		foreach($data["nameMap"] as $name => $id){
			$nameMap[(string) $name] = (int) $id;
		}
		return VoxelShapesPacket::create($shapes, $nameMap, 0);
	}

	/**
	 * @return BlockPaletteEntry[]
	 */
	private static function loadDataDrivenBlocks(string $file) : array{
		$palette = self::loadGzNetworkNbt($file)->getListTag("blockPalette");
		if($palette === null){
			throw new AssumptionFailedError("$file should contain a blockPalette list");
		}
		$entries = [];
		foreach($palette->getValue() as $entry){
			if($entry instanceof CompoundTag && ($states = $entry->getCompoundTag("states")) !== null){
				//TreeRoot encodes the root nameless by default, like the vanilla server does
				$entries[] = new BlockPaletteEntry($entry->getString("name"), new CacheableNbt(clone $states));
			}
		}
		return $entries;
	}

	public function __construct(
		private BiomeDefinitionListPacket $biomeDefs,
		private BiomeDefinitionListPacket $legacyBiomeDefs,
		private BiomeDefinitionListPacket $legacyBiomeDefs116100,
		private BiomeDefinitionListPacket $legacyBiomeDefs11810,
		private BiomeDefinitionListPacket $legacyBiomeDefs1190,
		private BiomeDefinitionListPacket $legacyBiomeDefs11930,
		private BiomeDefinitionListPacket $legacyBiomeDefs11960,
		private AvailableActorIdentifiersPacket $availableActorIdentifiers,
		private AvailableActorIdentifiersPacket $availableActorIdentifiers116100,
		private AvailableActorIdentifiersPacket $availableActorIdentifiers1170,
		private AvailableActorIdentifiersPacket $availableActorIdentifiers1190,
		private AvailableActorIdentifiersPacket $availableActorIdentifiers11920,
		private AvailableActorIdentifiersPacket $availableActorIdentifiers11980,
		/** @var VoxelShapesPacket[] protocol => packet */
		private array $voxelShapes = [],
		/** @var JigsawStructureDataPacket[] protocol => packet */
		private array $jigsawStructures = [],
		/** @var BlockPaletteEntry[][] protocol => entries */
		private array $dataDrivenBlocks = []
	){}

	/**
	 * Block collision shapes (1.26.50+); an empty packet for versions without the data
	 */
	public function getVoxelShapes(int $protocolId) : VoxelShapesPacket{
		foreach($this->voxelShapes as $protocol => $packet){
			if($protocolId >= $protocol){
				return $packet;
			}
		}
		return VoxelShapesPacket::create([], [], 0);
	}

	/**
	 * Jigsaw structure registry (1.26.50+); null for versions which don't get it
	 */
	public function getJigsawStructureData(int $protocolId) : ?JigsawStructureDataPacket{
		foreach($this->jigsawStructures as $protocol => $packet){
			if($protocolId >= $protocol){
				return $packet;
			}
		}
		return null;
	}

	/**
	 * Vanilla data-driven blocks which have to be declared in StartGame (1.26.50+)
	 *
	 * @return BlockPaletteEntry[]
	 */
	public function getDataDrivenBlockPalette(int $protocolId) : array{
		foreach($this->dataDrivenBlocks as $protocol => $entries){
			if($protocolId >= $protocol){
				return $entries;
			}
		}
		return [];
	}

	public function getBiomeDefs(int $protocolId) : BiomeDefinitionListPacket{
		if($protocolId < ProtocolInfo::PROTOCOL_1_18_10){
			return $this->legacyBiomeDefs116100;
		}
		if($protocolId < ProtocolInfo::PROTOCOL_1_19_0){
			return $this->legacyBiomeDefs11810;
		}
		if($protocolId < ProtocolInfo::PROTOCOL_1_19_30){
			return $this->legacyBiomeDefs1190;
		}
		if($protocolId >= ProtocolInfo::PROTOCOL_1_21_80){
			return $this->biomeDefs;
		}
		if($protocolId < ProtocolInfo::PROTOCOL_1_19_60){
			return $this->legacyBiomeDefs11930;
		}
		if($protocolId <= ProtocolInfo::PROTOCOL_1_19_80){
			return $this->legacyBiomeDefs11960;
		}
		return $this->legacyBiomeDefs;
	}

	public function getAvailableActorIdentifiers(int $protocolId) : AvailableActorIdentifiersPacket{
		if($protocolId < ProtocolInfo::PROTOCOL_1_17_0){
			return $this->availableActorIdentifiers116100;
		}
		if($protocolId < ProtocolInfo::PROTOCOL_1_19_0){
			return $this->availableActorIdentifiers1170;
		}
		if($protocolId < ProtocolInfo::PROTOCOL_1_19_20){
			return $this->availableActorIdentifiers1190;
		}
		if($protocolId < ProtocolInfo::PROTOCOL_1_19_80){
			return $this->availableActorIdentifiers11920;
		}
		if($protocolId === ProtocolInfo::PROTOCOL_1_19_80){
			return $this->availableActorIdentifiers11980;
		}
		return $this->availableActorIdentifiers;
	}
}
