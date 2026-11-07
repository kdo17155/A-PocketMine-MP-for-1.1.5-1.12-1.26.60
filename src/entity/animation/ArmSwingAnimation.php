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

namespace pocketmine\entity\animation;

use pocketmine\entity\Living;
use pocketmine\network\mcpe\protocol\ActorEventPacket;
use pocketmine\network\mcpe\protocol\AnimatePacket;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\types\ActorEvent;

final class ArmSwingAnimation implements ProtocolAnimation{

	private int $protocolId = ProtocolInfo::CURRENT_PROTOCOL;

	//TODO: not sure if this should be constrained to humanoids, but we don't have any concept of that right now
	public function __construct(private Living $entity){}

	public function setProtocolId(int $protocolId) : void{
		$this->protocolId = $protocolId;
	}

	public function encode() : array{
		if($this->protocolId < ProtocolInfo::PROTOCOL_1_20_10){
			//Legacy clients use AnimatePacket for arm swings.
			return [
				AnimatePacket::create($this->entity->getId(), AnimatePacket::ACTION_SWING_ARM)
			];
		}

		return [
			ActorEventPacket::create($this->entity->getId(), ActorEvent::ARM_SWING, 0, null)
		];
	}
}
