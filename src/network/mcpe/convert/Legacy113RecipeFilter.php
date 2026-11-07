<?php

/*
 * This file is part of BedrockProtocol.
 * Copyright (C) 2014-2022 PocketMine Team <https://github.com/pmmp/BedrockProtocol>
 *
 * BedrockProtocol is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

declare(strict_types=1);

namespace pocketmine\network\mcpe\convert;

use pocketmine\network\mcpe\protocol\types\inventory\ItemStack;
use pocketmine\network\mcpe\protocol\types\recipe\FurnaceRecipe;
use pocketmine\network\mcpe\protocol\types\recipe\IntIdMetaItemDescriptor;
use pocketmine\network\mcpe\protocol\types\recipe\RecipeIngredient;
use pocketmine\network\mcpe\protocol\types\recipe\RecipeWithTypeId;
use pocketmine\network\mcpe\protocol\types\recipe\ShapedRecipe;
use pocketmine\network\mcpe\protocol\types\recipe\ShapelessRecipe;

/**
 * Decides whether a recipe can be sent to a 1.1.x client.
 *
 * 1.1.x clients crash when a recipe references an item that doesn't exist in that version, so recipes using
 * anything outside the 1.1.x item set are dropped.
 */
final class Legacy113RecipeFilter{
	private function __construct(){
		//NOOP
	}

	public static function isSupported(RecipeWithTypeId $recipe) : bool{
		if($recipe instanceof ShapedRecipe){
			foreach($recipe->getInput() as $row){
				foreach($row as $ingredient){
					if(!self::ingredientSupported($ingredient)){
						return false;
					}
				}
			}
			foreach($recipe->getOutput() as $output){
				if(!self::itemSupported($output)){
					return false;
				}
			}
			return true;
		}

		if($recipe instanceof ShapelessRecipe){
			foreach($recipe->getInputs() as $ingredient){
				if(!self::ingredientSupported($ingredient)){
					return false;
				}
			}
			foreach($recipe->getOutputs() as $output){
				if(!self::itemSupported($output)){
					return false;
				}
			}
			return true;
		}

		if($recipe instanceof FurnaceRecipe){
			return Legacy113ItemSet::contains($recipe->getInputId(), $recipe->getInputMeta() ?? 0) &&
				self::itemSupported($recipe->getResult());
		}

		//multi recipes have no items
		return true;
	}

	private static function ingredientSupported(RecipeIngredient $ingredient) : bool{
		$descriptor = $ingredient->getDescriptor();
		if($descriptor === null){
			return true;
		}
		if(!$descriptor instanceof IntIdMetaItemDescriptor){
			return false;
		}
		return Legacy113ItemSet::contains($descriptor->getId(), $descriptor->getMeta());
	}

	private static function itemSupported(ItemStack $item) : bool{
		if($item->getId() === 0){
			return true;
		}
		return Legacy113ItemSet::contains($item->getId(), $item->getMeta());
	}
}
