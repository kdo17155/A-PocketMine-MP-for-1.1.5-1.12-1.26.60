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

namespace pocketmine\world\sound;

use pocketmine\block\Block;
use pocketmine\network\mcpe\convert\BlockTranslator;
use pocketmine\network\mcpe\convert\Legacy113BlockMapper;

abstract class BlockSound implements Sound{

	private BlockTranslator $blockTranslator;
	private bool $legacy113 = false;

	public function __construct(private Block $block){}

	public function setBlockTranslator(BlockTranslator $blockTranslator) : void{
		$this->blockTranslator = $blockTranslator;
	}

	public function setLegacy113(bool $legacy113) : void{
		$this->legacy113 = $legacy113;
	}

	public function toRuntimeId() : int{
		$networkId = $this->blockTranslator->internalIdToNetworkId($this->block->getStateId());
		if($this->legacy113){
			//1.1.x sound extraData is a plain legacy block ID, not a runtime ID
			return Legacy113BlockMapper::getInstance()->networkIdToLegacy($this->blockTranslator, $networkId)[0];
		}
		return $networkId;
	}
}
