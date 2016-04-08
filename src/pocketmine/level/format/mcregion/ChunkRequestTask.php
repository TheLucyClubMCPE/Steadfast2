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

namespace pocketmine\level\format\mcregion;

use pocketmine\level\Level;
use pocketmine\nbt\NBT;
use pocketmine\scheduler\AsyncTask;
use pocketmine\Server;
use pocketmine\tile\Spawnable;
use pocketmine\utils\Binary;
use pocketmine\utils\ChunkException;
use pocketmine\utils\BinaryStream;
use pocketmine\network\protocol\FullChunkDataPacket;

class ChunkRequestTask extends AsyncTask {

	protected $levelId;
	protected $chunkX;
	protected $chunkZ;
	protected $tiles;
	protected $blockExtraDataArray;
	protected $blockIdArray;
	protected $blockDataArray;
	protected $blockSkyLightArray;
	protected $blockLightArray;
	protected $heightMapArray;
	protected $biomeColorArray;

	public function __construct(McRegion $level, $levelId, $chunkX, $chunkZ) {
		$this->levelId = $levelId;
		$this->chunkX = $chunkX;
		$this->chunkZ = $chunkZ;
		$chunk = $level->getChunk($chunkX, $chunkZ, false);
		if (!($chunk instanceof Chunk)) {
			throw new ChunkException("Invalid Chunk sent");
		}
		$tiles = "";
		$nbt = new NBT(NBT::LITTLE_ENDIAN);
		foreach ($chunk->getTiles() as $tile) {
			if ($tile instanceof Spawnable) {
				$nbt->setData($tile->getSpawnCompound());
				$tiles .= $nbt->write();
			}
		}

		$this->tiles = $tiles;

		$this->blockExtraDataArray = $chunk->getBlockExtraDataArray();
		$this->blockIdArray = $chunk->getBlockIdArray();
		$this->blockDataArray = $chunk->getBlockDataArray();
		$this->blockSkyLightArray = $chunk->getBlockSkyLightArray();
		$this->blockLightArray = $chunk->getBlockLightArray();
		$this->heightMapArray = $chunk->getHeightMapArray();
		$this->biomeColorArray = $chunk->getBiomeColorArray();
	}

	public function onRun() {

		$extraData = new BinaryStream();
		$extraData->putLInt(count($this->blockExtraDataArray));
		foreach ($this->blockExtraDataArray as $key => $value) {
			$extraData->putLInt($key);
			$extraData->putLShort($value);
		}

		$ordered = $this->blockIdArray .
				$this->blockDataArray .
				$this->blockSkyLightArray .
				$this->blockLightArray .
				pack("C*", ...$this->heightMapArray) .
				pack("N*", ...$this->biomeColorArray) .
				$extraData->getBuffer() .
				$this->tiles;

		$pk = new FullChunkDataPacket();
		$pk->chunkX = $this->chunkX;
		$pk->chunkZ = $this->chunkZ;
		$pk->order = FullChunkDataPacket::ORDER_COLUMNS;
		$pk->data = $ordered;
		$pk->encode();
		$str = Binary::writeInt(strlen($pk->buffer)) . $pk->buffer;
		$ordered = zlib_encode($str, ZLIB_ENCODING_DEFLATE, 7);
		$this->setResult($ordered);
	}

	public function onCompletion(Server $server) {
		$level = $server->getLevel($this->levelId);
		if ($level instanceof Level and $this->hasResult()) {
			$level->chunkRequestCallback($this->chunkX, $this->chunkZ, $this->getResult());
		}
	}

}
