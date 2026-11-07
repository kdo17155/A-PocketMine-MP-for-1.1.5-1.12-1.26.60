<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| |  __/
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | | |_) |
 * |_|   \___/ \___| |\___/ \__|_|  |_| |_|_| |_|\___|     |_|  |_|\___|
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

namespace pocketmine\network\mcpe\convert;

use pocketmine\entity\InvalidSkinException;
use pocketmine\entity\Skin;
use pocketmine\network\mcpe\protocol\types\skin\SkinData;
use pocketmine\network\mcpe\protocol\types\skin\SkinImage;
use WeakMap;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function random_bytes;
use function str_repeat;
use const JSON_THROW_ON_ERROR;

class LegacySkinAdapter implements SkinAdapter{
	/**
	 * persona 皮肤（1.16+ 客户端的默认史蒂夫/换装皮肤）
	 * 的完整 SkinData 缓存（Skin 对象 → SkinData）。toSkinData() 时原样回放，
	 * 避免走下面的重建路径生成 Standard_Custom + 空几何数据 —— 1.16+ 客户端
	 * 无法用该组合构建模型，导致穿戴者对其他玩家隐身。
	 * @var WeakMap<Skin, SkinData>|null
	 */
	private static ?WeakMap $skinDataCache = null;
	/** persona 占位纹理（8192 字节 = 64x64 RGBA），全体 persona 玩家静态复用，避免每次登录重复生成 */
	private static ?string $personaSkinData = null;

	/** 皮肤广播插桩，定位 1.26.52 噪点方块问题后移除 */
	private static function dbg(string $msg) : void{
		try{ \GlobalLogger::get()->info("[SkinNet] " . $msg); }catch(\Throwable){ }
	}

	public function toSkinData(Skin $skin) : SkinData{
		// persona 皮肤：直接回放缓存的完整 SkinData（真实纹理 + geometry.humanoid.custom + 完整几何 JSON）
		if(self::$skinDataCache !== null && isset(self::$skinDataCache[$skin])){
			$d = self::$skinDataCache[$skin];
			self::dbg("replay skinId=" . $skin->getSkinId() . " texLen=" . strlen($d->getSkinImage()->getData()) . " persona=" . var_export($d->isPersona(), true) . " geoName=" . $d->getGeometryName() . " geoLen=" . strlen($d->getGeometryData()) . " rpLen=" . strlen($d->getResourcePatch()));
			return $d;
		}
		$capeData = $skin->getCapeData();
		$capeImage = $capeData === "" ? new SkinImage(0, 0, "") : new SkinImage(32, 64, $capeData);
		$geometryName = $skin->getGeometryName();
		if($geometryName === ""){
			$geometryName = "geometry.humanoid.custom";
		}
		$result = new SkinData(
			$skin->getSkinId(),
			"", //TODO: playfab ID
			json_encode(["geometry" => ["default" => $geometryName]], JSON_THROW_ON_ERROR),
			SkinImage::fromLegacy($skin->getSkinData()), [],
			$capeImage,
			$skin->getGeometryData(),
			geometryName: $geometryName
		);
		self::dbg("rebuild skinId=" . $skin->getSkinId() . " texLen=" . strlen($skin->getSkinData()) . " isPlaceholder=" . var_export(self::$personaSkinData !== null && $skin->getSkinData() === self::$personaSkinData, true) . " geoName=" . $geometryName . " geoLen=" . strlen($skin->getGeometryData()) . " capeLen=" . strlen($capeData));
		return $result;
	}

	public function fromSkinData(SkinData $data) : Skin{
		if($data->isPersona()){
			self::dbg("fromSkinData PERSONA skinId=" . $data->getSkinId() . " texLen=" . strlen($data->getSkinImage()->getData()) . " tex=" . $data->getSkinImage()->getWidth() . "x" . $data->getSkinImage()->getHeight() . " geoName=" . $data->getGeometryName() . " geoLen=" . strlen($data->getGeometryData()) . " rpLen=" . strlen($data->getResourcePatch()) . " pieces=" . count($data->getPersonaPieces()));
			// persona 皮肤不再丢弃原始数据。
			// 旧实现返回 new Skin("Standard_Custom", 随机噪声) → 广播时几何名为 Standard_Custom
			// 且几何数据为空 → 1.16+ 客户端解析不出几何 → 玩家隐身。
			// 现在缓存一份"去披风"的完整 SkinData（保留真实纹理/几何/persona 部件等全部字段），
			// 由 toSkinData() 原样回放；Skin 对象内只放占位纹理供插件读取。
			$skin = new Skin($data->getSkinId(), self::$personaSkinData ??= str_repeat(random_bytes(3) . "\xff", 2048));
			$cache = self::$skinDataCache ??= new WeakMap();
			$cache[$skin] = new SkinData(
				$data->getSkinId(),
				$data->getPlayFabId(),
				$data->getResourcePatch(),
				$data->getSkinImage(),
				$data->getAnimations(),
				new SkinImage(0, 0, ""), // capeImage 清空
				$data->getGeometryData(),
				geometryDataEngineVersion: $data->getGeometryDataEngineVersion(),
				animationData: $data->getAnimationData(),
				capeId: "", // capeId 清空
				fullSkinId: $data->getFullSkinId(),
				armSize: $data->getArmSize(),
				skinColor: $data->getSkinColor(),
				personaPieces: $data->getPersonaPieces(),
				pieceTintColors: $data->getPieceTintColors(),
				isVerified: $data->isVerified(),
				premium: $data->isPremium(),
				persona: true,
				personaCapeOnClassic: false,
				isPrimaryUser: $data->isPrimaryUser(),
				override: $data->isOverride(),
				geometryName: $data->getGeometryName(),
				trustedSkinFlag: $data->getTrustedSkinFlag(),
				profileHash: $data->getProfileHash()
			);
			return $skin;
		}

		// 非 persona（经典皮肤）路径保持原版行为不变（含披风透传）
		self::dbg("fromSkinData CLASSIC skinId=" . $data->getSkinId() . " tex=" . $data->getSkinImage()->getWidth() . "x" . $data->getSkinImage()->getHeight() . " geoName=" . $data->getGeometryName() . " geoLen=" . strlen($data->getGeometryData()) . " rpLen=" . strlen($data->getResourcePatch()));
		$capeData = $data->isPersonaCapeOnClassic() ? "" : $data->getCapeImage()->getData();

		$resourcePatch = json_decode($data->getResourcePatch(), true);
		if(is_array($resourcePatch) && isset($resourcePatch["geometry"]["default"]) && is_string($resourcePatch["geometry"]["default"])){
			$geometryName = $resourcePatch["geometry"]["default"];
		}else{
			throw new InvalidSkinException("Missing geometry name field");
		}

		return new Skin($data->getSkinId(), $data->getSkinImage()->getData(), $capeData, $geometryName, $data->getGeometryData());
	}

	/**
	 * 披风兼容助手（供饰品类插件使用）：
	 * 在保留真实皮肤数据的前提下重建 Skin 并只替换披风。
	 * - persona 皮肤：Skin 内是占位纹理，直接 new Skin(...) 重建会把占位纹理广播出去。
	 *   本方法从缓存的完整 SkinData 派生新 Skin，并同步更新缓存（新披风/去披风），
	 *   广播仍走缓存回放路径 → 1.16+ 客户端可见且披风生效。
	 * - 经典皮肤：行为与直接 new Skin(...) 完全一致（披风由 Skin 字段透传）。
	 */
	public static function rebuildWithCape(Skin $skin, string $capeData) : Skin{
		$newSkin = new Skin($skin->getSkinId(), $skin->getSkinData(), $capeData, $skin->getGeometryName(), $skin->getGeometryData());
		if(self::$skinDataCache !== null && isset(self::$skinDataCache[$skin])){
			$cached = self::$skinDataCache[$skin];
			self::$skinDataCache[$newSkin] = new SkinData(
				$cached->getSkinId(),
				$cached->getPlayFabId(),
				$cached->getResourcePatch(),
				$cached->getSkinImage(),
				$cached->getAnimations(),
				$capeData === "" ? new SkinImage(0, 0, "") : new SkinImage(32, 64, $capeData),
				$cached->getGeometryData(),
				geometryDataEngineVersion: $cached->getGeometryDataEngineVersion(),
				animationData: $cached->getAnimationData(),
				capeId: "",
				fullSkinId: $cached->getFullSkinId(),
				armSize: $cached->getArmSize(),
				skinColor: $cached->getSkinColor(),
				personaPieces: $cached->getPersonaPieces(),
				pieceTintColors: $cached->getPieceTintColors(),
				isVerified: $cached->isVerified(),
				premium: $cached->isPremium(),
				persona: true,
				personaCapeOnClassic: false,
				isPrimaryUser: $cached->isPrimaryUser(),
				override: $cached->isOverride(),
				geometryName: $cached->getGeometryName(),
				trustedSkinFlag: $cached->getTrustedSkinFlag(),
				profileHash: $cached->getProfileHash()
			);
		}
		return $newSkin;
	}
}
