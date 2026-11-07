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

namespace pocketmine\world\particle;

use pocketmine\block\Block;
use pocketmine\network\mcpe\convert\BlockTranslator;
use pocketmine\network\mcpe\convert\Legacy113BlockMapper;

abstract class BlockParticle implements Particle{

	private BlockTranslator $blockTranslator;
	private bool $legacy113 = false;

	public function __construct(protected Block $b){}

	public function setBlockTranslator(BlockTranslator $blockTranslator) : void{
		$this->blockTranslator = $blockTranslator;
	}

	public function setLegacy113(bool $legacy113) : void{
		$this->legacy113 = $legacy113;
	}

	public function toRuntimeId() : int{
		$networkId = $this->blockTranslator->internalIdToNetworkId($this->b->getStateId());
		if($this->legacy113){
			//1.1.x particle eventData is id + (meta << 8), not a runtime ID
			[$id, $meta] = Legacy113BlockMapper::getInstance()->networkIdToLegacy($this->blockTranslator, $networkId);
			return $id + ($meta << 8);
		}
		return $networkId;
	}
}
