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

use pmmp\encoding\BE;
use pmmp\encoding\ByteBufferWriter;
use pocketmine\crafting\CraftingManager;
use pocketmine\crafting\FurnaceType;
use pocketmine\crafting\MetaWildcardRecipeIngredient;
use pocketmine\crafting\ShapedRecipe;
use pocketmine\crafting\ShapelessRecipe;
use pocketmine\crafting\ShapelessRecipeType;
use pocketmine\crafting\TagWildcardRecipeIngredient;
use pocketmine\data\bedrock\item\ItemTypeSerializeException;
use pocketmine\data\bedrock\ItemTagDowngrader;
use pocketmine\data\bedrock\ItemTagToIdMap;
use pocketmine\data\FilesystemCache;
use pocketmine\data\FilesystemCacheKey;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\convert\ItemTypeDictionary;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\protocol\CraftingDataPacket;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\types\recipe\CraftingRecipeBlockName;
use pocketmine\network\mcpe\protocol\types\recipe\FurnaceRecipe as ProtocolFurnaceRecipe;
use pocketmine\network\mcpe\protocol\types\recipe\FurnaceRecipeBlockName;
use pocketmine\network\mcpe\protocol\types\recipe\IntIdMetaItemDescriptor;
use pocketmine\network\mcpe\protocol\types\recipe\NameItemDescriptor;
use pocketmine\network\mcpe\protocol\types\recipe\ItemDescriptor;
use pocketmine\network\mcpe\protocol\types\recipe\PotionContainerChangeRecipe as ProtocolPotionContainerChangeRecipe;
use pocketmine\network\mcpe\protocol\types\recipe\PotionTypeRecipe as ProtocolPotionTypeRecipe;
use pocketmine\network\mcpe\protocol\types\recipe\RecipeUnlockingRequirement;
use pocketmine\network\mcpe\protocol\types\recipe\ShapedRecipe as ProtocolShapedRecipe;
use pocketmine\network\mcpe\protocol\types\recipe\ShapelessRecipe as ProtocolShapelessRecipe;
use pocketmine\Server;
use pocketmine\timings\Timings;
use pocketmine\utils\AssumptionFailedError;
use pocketmine\utils\ProtocolSingletonTrait;
use Ramsey\Uuid\Uuid;
use function array_map;
use function is_array;
use function is_int;
use function is_string;
use function spl_object_id;

final class CraftingDataCache{
	use ProtocolSingletonTrait;

	/**
	 * @var string[]
	 * @phpstan-var array<int, string>
	 */
	private array $caches = [];
	/**
	 * Maps recipe network IDs sent to clients back to CraftingManager recipe indexes.
	 *
	 * A single core recipe may be expanded into multiple protocol recipes when item tags or newer items are
	 * downgraded. Every expanded recipe needs a unique network ID, but all of them must resolve to the same core recipe.
	 *
	 * @var int[][]
	 * @phpstan-var array<int, array<int, int>>
	 */
	private array $recipeIndexMappings = [];

	/**
	 * The client doesn't like recipes with ID 0 (as of 1.21.100) and complains about them in the content log
	 * This doesn't actually affect the function of the recipe, but it is annoying, so this offset fixes it
	 */
	public const RECIPE_ID_OFFSET = 1;

	public function getCache(CraftingManager $manager) : string{
		$id = spl_object_id($manager);
		$isDefault = $manager === Server::getInstance()->getCraftingManager();
		if(!isset($this->caches[$id])){
			$manager->getDestructorCallbacks()->add(function() use ($id) : void{
				unset($this->caches[$id], $this->recipeIndexMappings[$id]);
			});
			$manager->getRecipeRegisteredCallbacks()->add(function() use ($id, $isDefault) : void{
				unset($this->caches[$id], $this->recipeIndexMappings[$id]);
				if($isDefault){
					FilesystemCache::getInstance()->remove(FilesystemCacheKey::getCraftingDataKey($this->protocolId));
				}
			});
			$this->caches[$id] = $this->getCraftingDataPayload($manager, $isDefault);
		}
		return $this->caches[$id];
	}

	public function getRecipeIndex(CraftingManager $manager, int $recipeNetworkId) : ?int{
		$id = spl_object_id($manager);
		$this->getCache($manager);
		return $this->recipeIndexMappings[$id][$recipeNetworkId] ?? null;
	}

	private function getCraftingDataPayload(CraftingManager $manager, bool $isDefault) : string{
		if(!$isDefault){
			return $this->buildCraftingDataCache($manager);
		}

		$key = FilesystemCacheKey::getCraftingDataKey($this->protocolId);
		$cache = FilesystemCache::getInstance();
		$cached = $cache->get($key);
		if(is_array($cached)){
			$payload = $cached["payload"] ?? null;
			$recipeIndexMapping = $cached["recipeIndexMapping"] ?? null;
			if(is_string($payload) && is_array($recipeIndexMapping) && $this->isValidRecipeIndexMapping($recipeIndexMapping)){
				/** @var array<int, int> $recipeIndexMapping */
				$this->recipeIndexMappings[spl_object_id($manager)] = $recipeIndexMapping;
				return $payload;
			}
		}

		$payload = $this->buildCraftingDataCache($manager);
		$cache->put($key, [
			"payload" => $payload,
			"recipeIndexMapping" => $this->recipeIndexMappings[spl_object_id($manager)],
		]);
		return $payload;
	}

	/**
	 * @param array<int|string, mixed> $mapping
	 */
	private function isValidRecipeIndexMapping(array $mapping) : bool{
		foreach($mapping as $networkId => $recipeIndex){
			if(!is_int($networkId) || !is_int($recipeIndex)){
				return false;
			}
		}
		return true;
	}

	/**
	 * Rebuilds the cached CraftingDataPacket.
	 */
	private function buildCraftingDataCache(CraftingManager $manager) : string{
		Timings::$craftingDataCacheRebuild->startTiming();

		$nullUUID = Uuid::fromString(Uuid::NIL);
		$converter = TypeConverter::getInstance($this->protocolId);
		$protocolId = $this->getProtocolId();
		$itemTagDowngrader = ItemTagDowngrader::getInstance($this->protocolId);
		$recipesWithTypeIds = [];
		$recipeIndexMapping = [];

		$noUnlockingRequirement = new RecipeUnlockingRequirement(null);
		$recipeNetId = self::RECIPE_ID_OFFSET;
		foreach($manager->getCraftingRecipeIndex() as $index => $recipe){
			if($recipe instanceof ShapelessRecipe){
				$typeTag = match($recipe->getType()){
					ShapelessRecipeType::CRAFTING => CraftingRecipeBlockName::CRAFTING_TABLE,
					ShapelessRecipeType::STONECUTTER => CraftingRecipeBlockName::STONECUTTER,
					ShapelessRecipeType::CARTOGRAPHY => CraftingRecipeBlockName::CARTOGRAPHY_TABLE,
					ShapelessRecipeType::SMITHING => CraftingRecipeBlockName::SMITHING_TABLE,
				};
				foreach($itemTagDowngrader->downgradeShapelessRecipe($recipe) as $r){
					try{
						$currentRecipeNetId = $recipeNetId;
						$recipesWithTypeIds[] = new ProtocolShapelessRecipe(
							CraftingDataPacket::ENTRY_SHAPELESS,
							BE::packUnsignedInt($currentRecipeNetId), //TODO: this should probably be changed to something human-readable
							array_map(static fn($i) => $converter->coreRecipeIngredientToNet($i, $protocolId), $r->getIngredientList()),
							array_map($converter->coreItemStackToNet(...), $r->getResults()),
							$nullUUID,
							$typeTag,
							50,
							$noUnlockingRequirement,
							$currentRecipeNetId
						);
						$recipeIndexMapping[$currentRecipeNetId] = $index;
						$recipeNetId++;
					}catch(\InvalidArgumentException|ItemTypeSerializeException) {
						continue;
					}
				}
			}elseif($recipe instanceof ShapedRecipe){
				foreach($itemTagDowngrader->downgradeShapedRecipe($recipe) as $r){
					try{
						$currentRecipeNetId = $recipeNetId;
						$inputs = [];
						for($row = 0, $height = $r->getHeight(); $row < $height; ++$row){
							for($column = 0, $width = $r->getWidth(); $column < $width; ++$column){
								$inputs[$row][$column] = $converter->coreRecipeIngredientToNet($r->getIngredient($column, $row), $this->getProtocolId());
							}
						}
						$recipesWithTypeIds[] = new ProtocolShapedRecipe(
							CraftingDataPacket::ENTRY_SHAPED,
							BE::packUnsignedInt($currentRecipeNetId), //TODO: this should probably be changed to something human-readable
							$inputs,
							array_map($converter->coreItemStackToNet(...), $r->getResults()),
							$nullUUID,
							CraftingRecipeBlockName::CRAFTING_TABLE,
							50,
							true,
							$noUnlockingRequirement,
							$currentRecipeNetId,
						);
						$recipeIndexMapping[$currentRecipeNetId] = $index;
						$recipeNetId++;
					}catch(\InvalidArgumentException|ItemTypeSerializeException) {
						continue;
					}
				}
			}else{
				//TODO: probably special recipe types
			}
		}

		foreach(FurnaceType::cases() as $furnaceType){
			$typeTag = match($furnaceType){
				FurnaceType::FURNACE => FurnaceRecipeBlockName::FURNACE,
				FurnaceType::BLAST_FURNACE => FurnaceRecipeBlockName::BLAST_FURNACE,
				FurnaceType::SMOKER => FurnaceRecipeBlockName::SMOKER,
				FurnaceType::CAMPFIRE => FurnaceRecipeBlockName::CAMPFIRE,
				FurnaceType::SOUL_CAMPFIRE => FurnaceRecipeBlockName::SOUL_CAMPFIRE
			};
			if($this->getProtocolId() >= ProtocolInfo::PROTOCOL_1_26_20){
				$recipeNetId++;
			}
			foreach($manager->getFurnaceRecipeManager($furnaceType)->getAll() as $recipe){
				try{
					if($this->getProtocolId() >= ProtocolInfo::PROTOCOL_1_26_20){
						$recipesWithTypeIds[] = new ProtocolShapelessRecipe(
							CraftingDataPacket::ENTRY_SHAPELESS,
							BE::packUnsignedInt($recipeNetId), //TODO: this should probably be changed to something human-readable
							[ $converter->coreRecipeIngredientToNet($recipe->getInput(), $this->getProtocolId()) ],
							[$converter->coreItemStackToNet($recipe->getResult())],
							$nullUUID,
							$typeTag,
							50,
							$noUnlockingRequirement,
							$recipeNetId //not used, but we need to fill them with something unique regardless
						);
					}else{
						$input = $recipe->getInput();

						if($input instanceof TagWildcardRecipeIngredient){
							foreach(ItemTagToIdMap::getInstance($this->getProtocolId())->getIdsForTag($input->getTagName()) as $itemId){
								$input = $this->normalizeRecipeDescriptorToLegacy($converter->coreRecipeIngredientToNet(new MetaWildcardRecipeIngredient($itemId), $this->getProtocolId())->getDescriptor());
								if(!$input instanceof IntIdMetaItemDescriptor){
									throw new AssumptionFailedError();
								}

								$recipesWithTypeIds[] = new ProtocolFurnaceRecipe(
									CraftingDataPacket::ENTRY_FURNACE_DATA,
									$input->getId(),
									$input->getMeta(),
									$converter->coreItemStackToNet($recipe->getResult()),
									$typeTag
								);
							}
						}else{
							$input = $this->normalizeRecipeDescriptorToLegacy($converter->coreRecipeIngredientToNet($input, $this->getProtocolId())->getDescriptor());
							if(!$input instanceof IntIdMetaItemDescriptor){
								throw new AssumptionFailedError();
							}

							$recipesWithTypeIds[] = new ProtocolFurnaceRecipe(
								CraftingDataPacket::ENTRY_FURNACE_DATA,
								$input->getId(),
								$input->getMeta(),
								$converter->coreItemStackToNet($recipe->getResult()),
								$typeTag
							);
						}
					}
				}catch(\InvalidArgumentException|ItemTypeSerializeException){
					continue;
				}
			}
		}

		$potionTypeRecipes = [];
		foreach($manager->getPotionTypeRecipes() as $recipe){
			try{
				$input = $this->normalizeRecipeDescriptorToLegacy($converter->coreRecipeIngredientToNet($recipe->getInput())->getDescriptor());
				$ingredient = $this->normalizeRecipeDescriptorToLegacy($converter->coreRecipeIngredientToNet($recipe->getIngredient(), $this->getProtocolId())->getDescriptor());
				if(!$input instanceof IntIdMetaItemDescriptor || !$ingredient instanceof IntIdMetaItemDescriptor){
					throw new AssumptionFailedError();
				}
				$output = $converter->coreItemStackToNet($recipe->getOutput());
				$potionTypeRecipes[] = new ProtocolPotionTypeRecipe(
					$input->getId(),
					$input->getMeta(),
					$ingredient->getId(),
					$ingredient->getMeta(),
					$output->getId(),
					$output->getMeta()
				);
			}catch(\InvalidArgumentException|ItemTypeSerializeException){
				continue;
			}
		}

		$potionContainerChangeRecipes = [];
		$itemTypeDictionary = $converter->getItemTypeDictionary();
		foreach($manager->getPotionContainerChangeRecipes() as $recipe){
			try{
				$input = $itemTypeDictionary->fromStringId($recipe->getInputItemId());
				$ingredient = $this->normalizeRecipeDescriptorToLegacy($converter->coreRecipeIngredientToNet($recipe->getIngredient(), $this->getProtocolId())->getDescriptor());
				if(!$ingredient instanceof IntIdMetaItemDescriptor){
					throw new AssumptionFailedError();
				}
				$output = $itemTypeDictionary->fromStringId($recipe->getOutputItemId());
				$potionContainerChangeRecipes[] = new ProtocolPotionContainerChangeRecipe(
					$input,
					$ingredient->getId(),
					$output
				);
			}catch(\InvalidArgumentException|ItemTypeSerializeException){
				continue;
			}
		}

		$packet = CraftingDataPacket::create($recipesWithTypeIds, $potionTypeRecipes, $potionContainerChangeRecipes, [], true);
		$out = new ByteBufferWriter();
		NetworkSession::encodePacketTimed($out, $this->protocolId, $packet);
		Timings::$craftingDataCacheRebuild->stopTiming();
		$this->recipeIndexMappings[spl_object_id($manager)] = $recipeIndexMapping;
		return $out->getData();
	}

	private function normalizeRecipeDescriptorToLegacy(ItemDescriptor $descriptor) : IntIdMetaItemDescriptor{
		if($descriptor instanceof IntIdMetaItemDescriptor){
			return $descriptor;
		}
		if($descriptor instanceof NameItemDescriptor){
			return new IntIdMetaItemDescriptor(TypeConverter::getInstance($this->protocolId)->getItemTypeDictionary()->fromStringId($descriptor->getName()), $descriptor->getAuxValue());
		}
		throw new AssumptionFailedError();
	}
}
