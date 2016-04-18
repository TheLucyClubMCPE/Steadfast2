<?php

namespace pocketmine;

use raklib\protocol\EncapsulatedPacket;
use raklib\RakLib;
use pocketmine\network\CachedEncapsulatedPacket;

class PacketSender extends \Thread {


	protected $loader;
	protected $shutdown;
	
	protected $externalQueue;
	protected $internalQueue;	

	public function __construct(\ClassLoader $loader) {
		$this->loader = $loader;
		$this->externalQueue = new \Threaded;
		$this->internalQueue = new \Threaded;
		$this->shutdown = false;	
		$this->start();
	}

	public function pushMainToThreadPacket($data) {
		$this->internalQueue[] = $data;
	}

	public function readMainToThreadPacket() {
		return $this->internalQueue->shift();
	}
	public function readThreadToMainPacket() {
		return $this->externalQueue->shift();
	}

	public function run() {
		$this->loader->register(true);
		gc_enable();
		ini_set("memory_limit", -1);
		$this->tickProcessor();
	}

	private function tickProcessor() {
		while (!$this->shutdown) {
			$start = microtime(true);
			$this->tick();
			$time = microtime(true) - $start;
			if ($time < 0.025) {
				time_sleep_until(microtime(true) + 0.025 - $time);
			}
		}
	}

	private function tick() {
		while(is_object($data = $this->readMainToThreadPacket())){
			$this->checkPacket($data);
		}
	}
	
	public function checkPacket($data) {
		$result = $this->makeBuffer($data->identifier, $data->additionalChar, $data->packet, $data->needACK, $data->identifierACK);
		$this->externalQueue[] = $result;
	}

	private function makeBuffer($identifier, $additionalChar, $fullPacket, $needACK, $identifierACK) {
		$pk = null;
		if (!$fullPacket->isEncoded) {
			$fullPacket->encode();
		} elseif (!$needACK) {
			if (isset($fullPacket->__encapsulatedPacket)) {
				unset($fullPacket->__encapsulatedPacket);
			}
			$fullPacket->__encapsulatedPacket = new CachedEncapsulatedPacket();
			$fullPacket->__encapsulatedPacket->identifierACK = null;
			$fullPacket->__encapsulatedPacket->buffer = $additionalChar . $fullPacket->buffer;
			$fullPacket->__encapsulatedPacket->reliability = 2;
			$pk = $fullPacket->__encapsulatedPacket;
		}

		if ($pk === null) {
			$pk = new EncapsulatedPacket();
			$pk->buffer = $additionalChar . $fullPacket->buffer;
			$pk->reliability = 2;

			if ($needACK === true && $identifierACK !== false) {
				$pk->identifierACK = $identifierACK;
			}
		}

		$flags = ($needACK === true ? RakLib::FLAG_NEED_ACK : RakLib::PRIORITY_NORMAL) | (RakLib::PRIORITY_NORMAL);

		$buffer = chr(RakLib::PACKET_ENCAPSULATED) . chr(strlen($identifier)) . $identifier . chr($flags) . $pk->toBinary(true);

		return $buffer;
	}
	
	public function shutdown(){
        $this->shutdown = true;
    }

}
