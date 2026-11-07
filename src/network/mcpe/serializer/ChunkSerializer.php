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

namespace pocketmine\network\mcpe\serializer;

use pmmp\encoding\Byte;
use pmmp\encoding\ByteBufferWriter;
use pmmp\encoding\LE;
use pmmp\encoding\VarInt;
use pocketmine\block\tile\Spawnable;
use pocketmine\data\bedrock\BiomeIds;
use pocketmine\data\bedrock\LegacyBiomeIdToStringIdMap;
use pocketmine\nbt\TreeRoot;
use pocketmine\network\mcpe\convert\BlockTranslator;
use pocketmine\network\mcpe\convert\Legacy113BlockMapper;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\serializer\NetworkNbtSerializer;
use pocketmine\network\mcpe\protocol\types\DimensionIds;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\PalettedBlockArray;
use pocketmine\world\format\SubChunk;
use function count;
use function min;
use function str_repeat;

final class ChunkSerializer{
	private function __construct(){
		//NOOP
	}

	/**
	 * Returns the min/max subchunk index expected in the protocol.
	 * This has no relation to the world height supported by PM.
	 *
	 * @phpstan-param DimensionIds::* $dimensionId
	 * @return int[]
	 * @phpstan-return array{int, int}
	 */
	public static function getDimensionChunkBounds(int $dimensionId, int $protocolId = ProtocolInfo::CURRENT_PROTOCOL) : array{
		return match($dimensionId){
			DimensionIds::OVERWORLD => $protocolId >= ProtocolInfo::PROTOCOL_1_18_0 ? [-4, 19] : [0, 15],
			DimensionIds::NETHER => [0, 7],
			DimensionIds::THE_END => [0, 15],
			default => throw new \InvalidArgumentException("Unknown dimension ID $dimensionId"),
		};
	}

	/**
	 * Returns the number of subchunks that will be sent from the given chunk.
	 * Chunks are sent in a stack, so every chunk below the top non-empty one must be sent.
	 *
	 * @phpstan-param DimensionIds::* $dimensionId
	 */
	public static function getSubChunkCount(Chunk $chunk, int $dimensionId, int $protocolId = ProtocolInfo::CURRENT_PROTOCOL) : int{
		//if the protocol world bounds ever exceed the PM supported bounds again in the future, we might need to
		//polyfill some stuff here
		[$minSubChunkIndex, $maxSubChunkIndex] = self::getDimensionChunkBounds($dimensionId, $protocolId);
		for($y = $maxSubChunkIndex, $count = $maxSubChunkIndex - $minSubChunkIndex + 1; $y >= $minSubChunkIndex; --$y, --$count){
			if($chunk->getSubChunk($y)->isEmptyFast()){
				continue;
			}
			return $count;
		}

		return 0;
	}

	/**
	 * @phpstan-param DimensionIds::* $dimensionId
	 * @return string[]
	 */
	public static function serializeSubChunks(Chunk $chunk, int $dimensionId, TypeConverter $typeConverter) : array{
		$stream = new ByteBufferWriter();
		$subChunks = [];
		$protocolId = $typeConverter->getProtocolId();

		$subChunkCount = self::getSubChunkCount($chunk, $dimensionId, $protocolId);
		$writtenCount = 0;

		[$minSubChunkIndex, ] = self::getDimensionChunkBounds($dimensionId, $protocolId);
		for($y = $minSubChunkIndex; $writtenCount < $subChunkCount; ++$y, ++$writtenCount){
			$stream->clear();
			self::serializeSubChunk($chunk->getSubChunk($y), $typeConverter->getBlockTranslator(), $stream, false, $protocolId);
			$subChunks[] = $stream->getData();
		}

		return $subChunks;
	}

	/**
	 * @phpstan-param DimensionIds::* $dimensionId
	 */
	public static function serializeFullChunk(Chunk $chunk, int $dimensionId, TypeConverter $typeConverter, ?string $tiles = null) : string{
		$stream = new ByteBufferWriter();

		foreach(self::serializeSubChunks($chunk, $dimensionId, $typeConverter) as $subChunk){
			$stream->writeByteArray($subChunk);
		}

		self::serializeBiomes($chunk, $dimensionId, $typeConverter->getProtocolId(), $stream);
		self::serializeChunkData($chunk, $stream, $typeConverter, $tiles);

		return $stream->getData();
	}

	/**
	 * @phpstan-param DimensionIds::* $dimensionId
	 */
	public static function serializeBiomes(Chunk $chunk, int $dimensionId, int $protocolId, ByteBufferWriter $stream) : void{
		if($protocolId < ProtocolInfo::PROTOCOL_1_18_0){
			self::serializeLegacyBiomes($chunk, $stream);
			return;
		}

		[$minSubChunkIndex, $maxSubChunkIndex] = self::getDimensionChunkBounds($dimensionId, $protocolId);
		$biomeIdMap = LegacyBiomeIdToStringIdMap::getInstance();
		//all biomes must always be written :(
		//1.18.0 and 1.18.10 expect one extra biome section; this was reduced from 25 to 24 in 1.18.30.
		$biomeSectionCount = $protocolId < ProtocolInfo::PROTOCOL_1_18_30 ?
			25 :
			$maxSubChunkIndex - $minSubChunkIndex + 1;
		for($i = 0; $i < $biomeSectionCount; ++$i){
			$y = min($minSubChunkIndex + $i, $maxSubChunkIndex);
			self::serializeBiomePalette($chunk->getSubChunk($y)->getBiomeArray(), $biomeIdMap, $stream);
		}
	}

	public static function serializeBorderBlocks(ByteBufferWriter $stream) : void {
		Byte::writeUnsigned($stream, 0); //border block array count
		//Border block entry format: 1 byte (4 bits X, 4 bits Z). These are however useless since they crash the regular client.
	}

	public static function serializeChunkData(Chunk $chunk, ByteBufferWriter $stream, TypeConverter $typeConverter, ?string $tiles = null) : void{
		self::serializeBorderBlocks($stream);

		if($tiles !== null){
			$stream->writeByteArray($tiles);
		}else{
			$stream->writeByteArray(self::serializeTiles($chunk, $typeConverter));
		}
	}

	/**
	 * Serializes a chunk for 1.1.x clients, which predate the subchunk palette format.
	 *
	 * Layout: subchunk count (byte), then for each subchunk:
	 *   storage version (byte, always 0), 4096 block IDs, 2048 metadata nibbles,
	 *   2048 sky light nibbles, 2048 block light nibbles
	 * followed by the heightmap (256 shorts), biomes (256 bytes), border blocks and extra data.
	 *
	 * @phpstan-param DimensionIds::* $dimensionId
	 */
	public static function serializeFullChunk113(Chunk $chunk, int $dimensionId, TypeConverter $typeConverter) : string{
		$stream = new ByteBufferWriter();
		$protocolId = ProtocolInfo::PROTOCOL_1_1_5;

		$subChunkCount = self::getSubChunkCount($chunk, $dimensionId, $protocolId);
		Byte::writeUnsigned($stream, $subChunkCount);

		[$minSubChunkIndex, ] = self::getDimensionChunkBounds($dimensionId, $protocolId);
		$writtenCount = 0;
		for($y = $minSubChunkIndex; $writtenCount < $subChunkCount; ++$y, ++$writtenCount){
			self::serializeSubChunk113($chunk->getSubChunk($y), $typeConverter->getBlockTranslator(), $stream);
		}

		//heightmap
		foreach($chunk->getHeightMapArray() as $height){
			LE::writeUnsignedShort($stream, $height & 0xffff);
		}

		//biomes (2D, one byte per column)
		$biomeArray = $chunk->getSubChunk($minSubChunkIndex)->getBiomeArray();
		for($z = 0; $z < 16; ++$z){
			for($x = 0; $x < 16; ++$x){
				$biomeId = $biomeArray->get($x, 0, $z);
				Byte::writeUnsigned($stream, LegacyBiomeIdToStringIdMap::getInstance()->legacyToString($biomeId) !== null ? $biomeId : BiomeIds::OCEAN);
			}
		}

		self::serializeBorderBlocks($stream);
		VarInt::writeUnsignedInt($stream, 0); //extra data

		return $stream->getData();
	}

	/**
	 * Serializes a single subchunk in the pre-1.2 (legacy) format.
	 */
	private static function serializeSubChunk113(SubChunk $subChunk, BlockTranslator $blockTranslator, ByteBufferWriter $stream) : void{
		$mapper = Legacy113BlockMapper::getInstance();

		Byte::writeUnsigned($stream, 0); //storage version

		$idArray = str_repeat("\x00", 4096);
		$metaArray = str_repeat("\x00", 2048);

		$layers = $subChunk->getBlockLayers();
		if(isset($layers[0])){
			$layer = $layers[0];
			for($x = 0; $x < 16; ++$x){
				for($z = 0; $z < 16; ++$z){
					for($y = 0; $y < 16; ++$y){
					[$legacyId, $legacyMeta] = $mapper->internalIdToLegacy($blockTranslator, $layer->get($x, $y, $z));

					$idArray[($x << 8) | ($z << 4) | $y] = chr($legacyId & 0xff);
						$index = ($x << 7) | ($z << 3) | ($y >> 1);
						if(($y & 1) === 0){
							$metaArray[$index] = chr((ord($metaArray[$index]) & 0xf0) | ($legacyMeta & 0x0f));
						}else{
							$metaArray[$index] = chr((($legacyMeta & 0x0f) << 4) | (ord($metaArray[$index]) & 0x0f));
						}
					}
				}
			}
		}

		$stream->writeByteArray($idArray);
		$stream->writeByteArray($metaArray);
		//1.1.x clients are sent a fully lit world (same behaviour as the reference implementation)
		$stream->writeByteArray(str_repeat("\xff", 2048)); //sky light
		$stream->writeByteArray(str_repeat("\x00", 2048)); //block light
	}

	public static function serializeSubChunk(SubChunk $subChunk, BlockTranslator $blockTranslator, ByteBufferWriter $stream, bool $persistentBlockStates, int $protocolId = ProtocolInfo::CURRENT_PROTOCOL) : void{
		$layers = $subChunk->getBlockLayers();
		Byte::writeUnsigned($stream, 8); //version

		Byte::writeUnsigned($stream, count($layers));

		$blockStateDictionary = $blockTranslator->getBlockStateDictionary();

		foreach($layers as $blocks){
			$bitsPerBlock = $blocks->getBitsPerBlock();
			if($protocolId <= ProtocolInfo::PROTOCOL_1_17_0 && $bitsPerBlock === 0){
				//These clients don't understand zero-bit palettes. Expand the single palette entry to one bit per block.
				$bitsPerBlock = 1;
				$words = str_repeat("\x00", PalettedBlockArray::getExpectedWordArraySize($bitsPerBlock));
			}else{
				$words = $blocks->getWordArray();
			}
			Byte::writeUnsigned($stream, ($bitsPerBlock << 1) | ($persistentBlockStates ? 0 : 1));
			$stream->writeByteArray($words);
			$palette = $blocks->getPalette();

			if($bitsPerBlock !== 0){
				VarInt::writeSignedInt($stream, count($palette)); //yes, this is intentionally zigzag
			}
			if($persistentBlockStates){
				$nbtSerializer = new NetworkNbtSerializer();
				foreach($palette as $p){
					//TODO: introduce a binary cache for this
					$state = $blockStateDictionary->generateDataFromStateId($blockTranslator->internalIdToNetworkId($p));
					if($state === null){
						$state = $blockTranslator->getFallbackStateData();
					}

					$stream->writeByteArray($nbtSerializer->write(new TreeRoot($state->toNbt())));
				}
			}else{
				//we would use writeSignedIntArray() here, but the gains of writing in batch are negated by the cost of
				//allocating a temporary array for the mapped palette IDs, especially for small palettes
				foreach($palette as $p){
					VarInt::writeSignedInt($stream, $blockTranslator->internalIdToNetworkId($p));
				}
			}
		}
	}

	private static function serializeLegacyBiomes(Chunk $chunk, ByteBufferWriter $stream) : void{
		$biomeIdMap = LegacyBiomeIdToStringIdMap::getInstance();
		for($z = 0; $z < 16; ++$z){
			for($x = 0; $x < 16; ++$x){
				$biomeId = $chunk->getBiomeId($x, 0, $z);
				Byte::writeUnsigned($stream, $biomeIdMap->legacyToString($biomeId) !== null ? $biomeId : BiomeIds::OCEAN);
			}
		}
	}

	private static function serializeBiomePalette(PalettedBlockArray $biomePalette, LegacyBiomeIdToStringIdMap $biomeIdMap, ByteBufferWriter $stream) : void{
		$biomePaletteBitsPerBlock = $biomePalette->getBitsPerBlock();
		Byte::writeUnsigned($stream, ($biomePaletteBitsPerBlock << 1) | 1); //the last bit is non-persistence (like for blocks), though it has no effect on biomes since they always use integer IDs
		$stream->writeByteArray($biomePalette->getWordArray());

		$biomePaletteArray = $biomePalette->getPalette();
		if($biomePaletteBitsPerBlock !== 0){
			VarInt::writeSignedInt($stream, count($biomePaletteArray));
		}

		foreach($biomePaletteArray as $p){
			//we would use writeSignedIntArray() here, but the gains of writing in batch are negated by the cost of
			//allocating a temporary array for the mapped palette IDs, especially for small palettes
			VarInt::writeSignedInt($stream, $biomeIdMap->legacyToString($p) !== null ? $p : BiomeIds::OCEAN);
		}
	}

	public static function serializeTiles(Chunk $chunk, TypeConverter $typeConverter) : string{
		$stream = new ByteBufferWriter();
		foreach($chunk->getTiles() as $tile){
			if($tile instanceof Spawnable){
				$stream->writeByteArray($tile->getSerializedSpawnCompound($typeConverter)->getEncodedNbt());
			}
		}

		return $stream->getData();
	}
}
