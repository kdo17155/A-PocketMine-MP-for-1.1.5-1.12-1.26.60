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

namespace pocketmine\network\mcpe\handler;

use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\types\ServerAuthMovementMode;

final class ProtocolMovementAuthority{
	private function __construct(){
		//NOOP
	}

	public static function getMovementMode(int $protocolId) : ServerAuthMovementMode{
		return $protocolId <= ProtocolInfo::PROTOCOL_1_19_20 ?
			ServerAuthMovementMode::SERVER_AUTHORITATIVE_V2 :
			ServerAuthMovementMode::SERVER_AUTHORITATIVE_V3;
	}

	public static function usesPlayerAuthInput(int $protocolId) : bool{
		return $protocolId >= ProtocolInfo::PROTOCOL_1_16_100;
	}
}
