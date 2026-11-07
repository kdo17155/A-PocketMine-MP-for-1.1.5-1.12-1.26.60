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

namespace pocketmine\data;

use GlobalLogger;
use pocketmine\network\mcpe\compression\ZlibCompressor;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\utils\AssumptionFailedError;
use pocketmine\utils\Filesystem;
use pocketmine\utils\SingletonTrait;
use pocketmine\utils\Utils;
use pocketmine\VersionInfo;
use Symfony\Component\Filesystem\Path;
use Throwable;
use function constant;
use function file_exists;
use function igbinary_serialize;
use function igbinary_unserialize;
use function is_dir;
use function json_encode;
use function mkdir;
use function preg_match;
use function scandir;
use function unlink;
use const JSON_THROW_ON_ERROR;
use const SCANDIR_SORT_NONE;

/**
 * Persistent cache for data which is expensive to construct and is shared between server runs.
 *
 * @internal
 */
class FilesystemCache{
	use SingletonTrait;

	private const FORMAT_VERSION = 2; //bumped: 1.1.x protocol handling changed
	private const METADATA_KEY = ".metadata";
	private const LOCK_FILE = ".lock";

	private FilesystemMutex $mutex;

	private static function make() : self{
		return new self(Path::join((string) constant("DATA_PATH"), "cache_data"));
	}

	public function __construct(private string $path){
		if(!is_dir($this->path) && !@mkdir($this->path, 0777, true) && !is_dir($this->path)){
			throw new \RuntimeException("Cache directory cannot be created");
		}

		$this->mutex = new FilesystemMutex(Path::join($this->path, self::LOCK_FILE));
		$this->mutex->do(function() : void{
			$version = json_encode([
				self::FORMAT_VERSION,
				VersionInfo::VERSION()->getFullVersion(true),
				VersionInfo::GIT_HASH(),
				VersionInfo::BUILD_NUMBER(),
				ProtocolInfo::MINECRAFT_VERSION,
				ProtocolInfo::CURRENT_PROTOCOL,
				ProtocolInfo::ACCEPTED_PROTOCOL,
			], JSON_THROW_ON_ERROR);

			if($this->get(self::METADATA_KEY) !== $version){
				GlobalLogger::get()->debug("Cached data has expired.");
				$this->clear();
			}
			$this->put(self::METADATA_KEY, $version);
		});
	}

	public function put(string $key, mixed $value) : void{
		$this->mutex->do(function() use ($value, $key) : void{
			$file = Path::join($this->path, $this->toPathName($key));
			Filesystem::safeFilePutContents(
				$file,
				ZlibCompressor::getInstance()->compress(
					igbinary_serialize($value) ?? throw new AssumptionFailedError("Failed to serialize filesystem cache data")
				)
			);
		});
	}

	public function has(string $key) : bool{
		return $this->mutex->do(function() use ($key) : bool{
			return file_exists(Path::join($this->path, $this->toPathName($key)));
		});
	}

	public function get(string $key) : mixed{
		return $this->mutex->do(function() use ($key) : mixed{
			$file = Path::join($this->path, $this->toPathName($key));
			if(!file_exists($file)){
				return null;
			}

			try{
				return igbinary_unserialize(
					ZlibCompressor::getInstance()->decompress(Filesystem::fileGetContents($file))
				);
			}catch(Throwable $throwable){
				$logger = GlobalLogger::get();
				$logger->error("Data for $key is corrupted.");
				$logger->logException($throwable);
				$this->remove($key);
				return null;
			}
		});
	}

	public function remove(string $key) : void{
		$this->mutex->do(function() use ($key) : void{
			$file = Path::join($this->path, $this->toPathName($key));
			if(file_exists($file)){
				Utils::assumeNotFalse(unlink($file), "Cache file cannot be removed");
			}
		});
	}

	private function clear() : void{
		$entries = Utils::assumeNotFalse(scandir($this->path, SCANDIR_SORT_NONE));
		foreach($entries as $entry){
			if($entry === "." || $entry === ".." || $entry === self::LOCK_FILE){
				continue;
			}
			Filesystem::recursiveUnlink(Path::join($this->path, $entry));
		}
	}

	private function toPathName(string $key) : string{
		if(preg_match('/^[a-zA-Z0-9_.-]+$/D', $key) !== 1){
			throw new \InvalidArgumentException("Invalid filesystem cache key \"$key\"");
		}
		return $key . ".dat";
	}
}
