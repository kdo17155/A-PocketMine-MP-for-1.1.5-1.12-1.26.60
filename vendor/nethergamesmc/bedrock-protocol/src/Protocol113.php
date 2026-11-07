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

/**
 * Packet ID translation table for Minecraft: Pocket Edition 1.1.x (protocol 113).
 *
 * 1.1.5 uses a different packet ID layout than 1.12+, and some packets that exist in newer versions
 * simply don't exist in 1.1.5 (or exist under a different name/ID).
 *
 * The maps below translate between the "core" (modern) NETWORK_ID used by this library and the ID
 * that appears on the wire for a 1.1.5 client.
 */
final class Protocol113{
	public const PROTOCOL_ID = 113;

	/**
	 * modern NETWORK_ID => 1.1.5 wire ID (clientbound packets)
	 *
	 * @var array<int, int>
	 */
	private const MODERN_TO_113 = [
		ProtocolInfo::LOGIN_PACKET => 0x01,
		ProtocolInfo::PLAY_STATUS_PACKET => 0x02,
		ProtocolInfo::SERVER_TO_CLIENT_HANDSHAKE_PACKET => 0x03,
		ProtocolInfo::CLIENT_TO_SERVER_HANDSHAKE_PACKET => 0x04,
		ProtocolInfo::DISCONNECT_PACKET => 0x05,
		ProtocolInfo::RESOURCE_PACKS_INFO_PACKET => 0x06,
		ProtocolInfo::RESOURCE_PACK_STACK_PACKET => 0x07,
		ProtocolInfo::RESOURCE_PACK_CLIENT_RESPONSE_PACKET => 0x08,
		ProtocolInfo::TEXT_PACKET => 0x09,
		ProtocolInfo::SET_TIME_PACKET => 0x0a,
		ProtocolInfo::START_GAME_PACKET => 0x0b,
		ProtocolInfo::ADD_PLAYER_PACKET => 0x0c,
		ProtocolInfo::ADD_ACTOR_PACKET => 0x0d,
		ProtocolInfo::REMOVE_ACTOR_PACKET => 0x0e,
		ProtocolInfo::ADD_ITEM_ACTOR_PACKET => 0x0f,
		ProtocolInfo::TAKE_ITEM_ACTOR_PACKET => 0x11,
		ProtocolInfo::MOVE_ACTOR_ABSOLUTE_PACKET => 0x12,
		ProtocolInfo::MOVE_PLAYER_PACKET => 0x13,
		ProtocolInfo::PASSENGER_JUMP_PACKET => 0x14,
		ProtocolInfo::UPDATE_BLOCK_PACKET => 0x16,
		ProtocolInfo::ADD_PAINTING_PACKET => 0x17,
		ProtocolInfo::LEVEL_SOUND_EVENT_PACKET => 0x19,
		ProtocolInfo::LEVEL_EVENT_PACKET => 0x1a,
		ProtocolInfo::BLOCK_EVENT_PACKET => 0x1b,
		ProtocolInfo::ACTOR_EVENT_PACKET => 0x1c,
		ProtocolInfo::MOB_EFFECT_PACKET => 0x1d,
		ProtocolInfo::UPDATE_ATTRIBUTES_PACKET => 0x1e,
		ProtocolInfo::INVENTORY_TRANSACTION_PACKET => 0x1e,
		ProtocolInfo::MOB_EQUIPMENT_PACKET => 0x1f,
		ProtocolInfo::MOB_ARMOR_EQUIPMENT_PACKET => 0x20,
		ProtocolInfo::INTERACT_PACKET => 0x21,
		ProtocolInfo::BLOCK_PICK_REQUEST_PACKET => 0x22,
		ProtocolInfo::ACTOR_PICK_REQUEST_PACKET => 0x23,
		ProtocolInfo::PLAYER_ACTION_PACKET => 0x24,
		ProtocolInfo::ACTOR_FALL_PACKET => 0x25,
		ProtocolInfo::HURT_ARMOR_PACKET => 0x26,
		ProtocolInfo::SET_ACTOR_DATA_PACKET => 0x27,
		ProtocolInfo::SET_ACTOR_MOTION_PACKET => 0x28,
		ProtocolInfo::SET_ACTOR_LINK_PACKET => 0x29,
		ProtocolInfo::SET_HEALTH_PACKET => 0x2a,
		ProtocolInfo::SET_SPAWN_POSITION_PACKET => 0x2b,
		ProtocolInfo::ANIMATE_PACKET => 0x2c,
		ProtocolInfo::RESPAWN_PACKET => 0x2d,
		ProtocolInfo::CONTAINER_OPEN_PACKET => 0x30,
		ProtocolInfo::CONTAINER_CLOSE_PACKET => 0x31,
		ProtocolInfo::PLAYER_HOTBAR_PACKET => 0x2f,
		ProtocolInfo::INVENTORY_CONTENT_PACKET => 0x34,
		ProtocolInfo::INVENTORY_SLOT_PACKET => 0x32,
		ProtocolInfo::CONTAINER_SET_DATA_PACKET => 0x33,
		ProtocolInfo::CRAFTING_DATA_PACKET => 0x35,
		ProtocolInfo::CRAFTING_EVENT_PACKET => 0x36,
		ProtocolInfo::ADVENTURE_SETTINGS_PACKET => 0x37,
		ProtocolInfo::BLOCK_ACTOR_DATA_PACKET => 0x38,
		ProtocolInfo::PLAYER_INPUT_PACKET => 0x39,
		ProtocolInfo::LEVEL_CHUNK_PACKET => 0x3a,
		ProtocolInfo::SET_COMMANDS_ENABLED_PACKET => 0x3b,
		ProtocolInfo::SET_DIFFICULTY_PACKET => 0x3c,
		ProtocolInfo::CHANGE_DIMENSION_PACKET => 0x3d,
		ProtocolInfo::SET_PLAYER_GAME_TYPE_PACKET => 0x3e,
		ProtocolInfo::PLAYER_LIST_PACKET => 0x3f,
		ProtocolInfo::SIMPLE_EVENT_PACKET => 0x40,
		ProtocolInfo::LEGACY_TELEMETRY_EVENT_PACKET => 0x41,
		ProtocolInfo::SPAWN_EXPERIENCE_ORB_PACKET => 0x42,
		ProtocolInfo::CLIENTBOUND_MAP_ITEM_DATA_PACKET => 0x43,
		ProtocolInfo::MAP_INFO_REQUEST_PACKET => 0x44,
		ProtocolInfo::REQUEST_CHUNK_RADIUS_PACKET => 0x45,
		ProtocolInfo::CHUNK_RADIUS_UPDATED_PACKET => 0x46,
		ProtocolInfo::ITEM_FRAME_DROP_ITEM_PACKET => 0x47,
		ProtocolInfo::GAME_RULES_CHANGED_PACKET => 0x49,
		ProtocolInfo::CAMERA_PACKET => 0x4a,
		ProtocolInfo::BOSS_EVENT_PACKET => 0x4c,
		ProtocolInfo::SHOW_CREDITS_PACKET => 0x4d,
		//TODO: 1.1.x uses the pre-1.16 command list layout; not implemented yet
		ProtocolInfo::COMMAND_BLOCK_UPDATE_PACKET => 0x50,
		ProtocolInfo::UPDATE_TRADE_PACKET => 0x51,
		ProtocolInfo::UPDATE_EQUIP_PACKET => 0x52,
		ProtocolInfo::RESOURCE_PACK_DATA_INFO_PACKET => 0x53,
		ProtocolInfo::RESOURCE_PACK_CHUNK_DATA_PACKET => 0x54,
		ProtocolInfo::RESOURCE_PACK_CHUNK_REQUEST_PACKET => 0x55,
		ProtocolInfo::TRANSFER_PACKET => 0x56,
		ProtocolInfo::PLAY_SOUND_PACKET => 0x57,
		ProtocolInfo::STOP_SOUND_PACKET => 0x58,
		ProtocolInfo::SET_TITLE_PACKET => 0x59,
		ProtocolInfo::ADD_BEHAVIOR_TREE_PACKET => 0x5a,
		ProtocolInfo::STRUCTURE_BLOCK_UPDATE_PACKET => 0x5b,
		ProtocolInfo::SHOW_STORE_OFFER_PACKET => 0x5c,
		ProtocolInfo::PURCHASE_RECEIPT_PACKET => 0x5d,
		//1.1.5 has no CreativeContentPacket; creative items are sent using the old AddItem packet
		ProtocolInfo::CREATIVE_CONTENT_PACKET => 0x4b,
	];

	/**
	 * 1.1.5 wire ID => modern NETWORK_ID
	 *
	 * Built from MODERN_TO_113; where a wire ID is shared by more than one packet (1.1.x reused a few IDs
	 * for packets that were later split), the serverbound variant wins, since those are the ones a 1.1.x
	 * client can actually send.
	 *
	 * @var array<int, int>|null
	 */
	private static ?array $legacyToModern = null;

	private static function getLegacyToModern() : array{
		if(self::$legacyToModern === null){
			$map = [];
			foreach(self::MODERN_TO_113 as $modern => $legacy){
				if(!isset($map[$legacy])){
					$map[$legacy] = $modern;
				}
			}
			//1.1.x ID 0x1e is used by both UpdateAttributes and InventoryTransaction; clients only send the latter
			$map[0x1e] = ProtocolInfo::INVENTORY_TRANSACTION_PACKET;
			//0x19 is the classic LevelSoundEvent packet
			$map[0x19] = ProtocolInfo::LEVEL_SOUND_EVENT_PACKET;

			self::$legacyToModern = $map;
		}

		return self::$legacyToModern;
	}

	private function __construct(){
		//NOOP
	}

	/**
	 * Returns the 1.1.5 wire packet ID for the given core packet ID, or null if the packet doesn't exist in 1.1.5.
	 */
	public static function toLegacy(int $modernId) : ?int{
		return self::MODERN_TO_113[$modernId] ?? null;
	}

	/**
	 * Returns the core packet ID for the given 1.1.5 wire packet ID, or null if the packet isn't known.
	 */
	public static function toModern(int $legacyId) : ?int{
		return self::getLegacyToModern()[$legacyId] ?? null;
	}

	/**
	 * Returns true if the given core packet has a 1.1.5 representation.
	 */
	public static function existsIn113(int $modernId) : bool{
		return isset(self::MODERN_TO_113[$modernId]);
	}
}
