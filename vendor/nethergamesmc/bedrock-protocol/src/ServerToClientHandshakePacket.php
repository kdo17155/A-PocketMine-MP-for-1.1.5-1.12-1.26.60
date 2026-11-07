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

use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pocketmine\network\mcpe\protocol\serializer\CommonTypes;

class ServerToClientHandshakePacket extends DataPacket implements ClientboundPacket{
	public const NETWORK_ID = ProtocolInfo::SERVER_TO_CLIENT_HANDSHAKE_PACKET;

	/** Server pubkey and token is contained in the JWT. */
	public string $jwt;

	/** 1.1.x only: base64-encoded DER server public key */
	public string $publicKey = "";
	/** 1.1.x only: raw salt */
	public string $serverToken = "";

	/**
	 * @generate-create-func
	 */
	public static function create(string $jwt) : self{
		$result = new self;
		$result->jwt = $jwt;
		return $result;
	}

	public static function createLegacy(string $publicKey, string $serverToken) : self{
		$result = new self;
		$result->publicKey = $publicKey;
		$result->serverToken = $serverToken;
		return $result;
	}

	public function canBeSentBeforeLogin() : bool{
		return true;
	}

	protected function decodePayload(ByteBufferReader $in, int $protocolId) : void{
		if($protocolId <= ProtocolInfo::PROTOCOL_1_1_5){
			//1.1.x sends the public key and salt as separate strings instead of a JWT
			$this->publicKey = CommonTypes::getString($in);
			$this->serverToken = CommonTypes::getString($in);
			return;
		}
		$this->jwt = CommonTypes::getString($in);
	}

	protected function encodePayload(ByteBufferWriter $out, int $protocolId) : void{
		if($protocolId <= ProtocolInfo::PROTOCOL_1_1_5){
			CommonTypes::putString($out, $this->publicKey);
			CommonTypes::putString($out, $this->serverToken);
			return;
		}
		CommonTypes::putString($out, $this->jwt);
	}

	public function handle(PacketHandlerInterface $handler) : bool{
		return $handler->handleServerToClientHandshake($this);
	}
}
