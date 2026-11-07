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

namespace pocketmine\network\mcpe\protocol;

use pmmp\encoding\Byte;
use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pmmp\encoding\LE;
use pmmp\encoding\VarInt;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\serializer\CommonTypes;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;

class LevelSoundEventPacket extends DataPacket implements ClientboundPacket, ServerboundPacket{
	public const NETWORK_ID = ProtocolInfo::LEVEL_SOUND_EVENT_PACKET;

	/** @see LevelSoundEvent */
	public string $sound;
	public Vector3 $position;
	public int $extraData = -1;
	public string $entityType = ":"; //???
	public bool $isBabyMob = false; //...
	public bool $disableRelativeVolume = false;
	public int $actorUniqueId = -1;
	public ?Vector3 $firePosition = null;

	/**
	 * @generate-create-func
	 */
	public static function create(
		string $sound,
		Vector3 $position,
		int $extraData,
		string $entityType,
		bool $isBabyMob,
		bool $disableRelativeVolume,
		int $actorUniqueId,
		?Vector3 $firePosition,
	) : self{
		$result = new self;
		$result->sound = $sound;
		$result->position = $position;
		$result->extraData = $extraData;
		$result->entityType = $entityType;
		$result->isBabyMob = $isBabyMob;
		$result->disableRelativeVolume = $disableRelativeVolume;
		$result->actorUniqueId = $actorUniqueId;
		$result->firePosition = $firePosition;
		return $result;
	}

	public static function nonActorSound(string $sound, Vector3 $position, bool $disableRelativeVolume, int $extraData = -1) : self{
		return self::create($sound, $position, $extraData, ":", false, $disableRelativeVolume, -1, null);
	}

	protected function decodePayload(ByteBufferReader $in, int $protocolId) : void{
		if($protocolId <= ProtocolInfo::PROTOCOL_1_1_5){
			$soundId = Byte::readUnsigned($in);
			$this->sound = \pocketmine\network\mcpe\protocol\types\LevelSoundEvent::toString($soundId);
			$this->position = CommonTypes::getVector3($in);
			$this->extraData = VarInt::readSignedInt($in);
			$this->entityType = Legacy113EntityIds::toModern(VarInt::readSignedInt($in)) ?? ":";
			$this->isBabyMob = CommonTypes::getBool($in);
			$this->disableRelativeVolume = CommonTypes::getBool($in);
			return;
		}
		if($protocolId >= ProtocolInfo::PROTOCOL_1_26_30){
			$this->sound = CommonTypes::getString($in);
		}else{
			$this->sound = LevelSoundEvent::toString(VarInt::readUnsignedInt($in));
		}
		$this->position = CommonTypes::getVector3($in);
		$this->extraData = VarInt::readSignedInt($in);
		$this->entityType = CommonTypes::getString($in);
		$this->isBabyMob = CommonTypes::getBool($in);
		$this->disableRelativeVolume = CommonTypes::getBool($in);
		if($protocolId >= ProtocolInfo::PROTOCOL_1_21_70){
			$this->actorUniqueId = LE::readSignedLong($in); //WHY IS THIS NON-STANDARD?
			if($protocolId >= ProtocolInfo::PROTOCOL_1_26_20){
				$this->firePosition = CommonTypes::readOptional($in, CommonTypes::getVector3(...));
			}
		}
	}

	protected function encodePayload(ByteBufferWriter $out, int $protocolId) : void{
		if($protocolId <= ProtocolInfo::PROTOCOL_1_1_5){
			//1.1.x uses the original LevelSoundEvent layout: byte sound ID, numeric entity type
			Byte::writeUnsigned($out, Legacy113SoundIds::toLegacy($this->sound) ?? 0);
			CommonTypes::putVector3($out, $this->position);
			if($this->sound === LevelSoundEvent::NOTE){
				VarInt::writeSignedInt($out, $this->extraData >> 8);
				$entityType = $this->extraData & 0xff;
			}else{
				VarInt::writeSignedInt($out, $this->extraData);
				$entityType = $this->entityType === "minecraft:player" ? 319 : (Legacy113EntityIds::toLegacy($this->entityType) ?? 1);
			}
			VarInt::writeSignedInt($out, $entityType);
			CommonTypes::putBool($out, $this->isBabyMob);
			CommonTypes::putBool($out, $this->disableRelativeVolume);
			return;
		}
		if($protocolId >= ProtocolInfo::PROTOCOL_1_26_30){
			CommonTypes::putString($out, $this->sound);
		}else{
			VarInt::writeUnsignedInt($out, LevelSoundEvent::toId($this->sound));
		}
		CommonTypes::putVector3($out, $this->position);
		VarInt::writeSignedInt($out, $this->extraData);
		CommonTypes::putString($out, $this->entityType);
		CommonTypes::putBool($out, $this->isBabyMob);
		CommonTypes::putBool($out, $this->disableRelativeVolume);
		if($protocolId >= ProtocolInfo::PROTOCOL_1_21_70){
			LE::writeSignedLong($out, $this->actorUniqueId);
			if($protocolId >= ProtocolInfo::PROTOCOL_1_26_20){
				CommonTypes::writeOptional($out, $this->firePosition, CommonTypes::putVector3(...));
			}
		}
	}

	public function handle(PacketHandlerInterface $handler) : bool{
		return $handler->handleLevelSoundEvent($this);
	}
}
