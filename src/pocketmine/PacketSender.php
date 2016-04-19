<?php

namespace pocketmine;

use raklib\protocol\EncapsulatedPacket;
use raklib\RakLib;
use pocketmine\network\CachedEncapsulatedPacket;
use pocketmine\network\protocol\DataPacket;
use pocketmine\utils\Binary;
use pocketmine\network\protocol\BatchPacket;

class PacketSender extends \Thread {


	protected $classLoader;
	protected $shutdown;
	
	protected $externalQueue;
	protected $internalQueue;	

	public function __construct(\ClassLoader $loader = null) {
		$this->externalQueue = new \Threaded;
		$this->internalQueue = new \Threaded;
		$this->shutdown = false;
		$this->classLoader = $loader;
		$this->start(PTHREADS_INHERIT_NONE);
	}
	
	protected function registerClassLoader(){
		if(!interface_exists("ClassLoader", false)){
			require(\pocketmine\PATH . "src/spl/ClassLoader.php");
			require(\pocketmine\PATH . "src/spl/BaseClassLoader.php");
			require(\pocketmine\PATH . "src/pocketmine/CompatibleClassLoader.php");
		}
		if($this->classLoader !== null){
			$this->classLoader->register(true);
		}
	}
	
	public function run() {
		$this->registerClassLoader();
		gc_enable();
		ini_set("memory_limit", -1);
		$this->tickProcessor();
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

	protected function tickProcessor() {
		while (!$this->shutdown) {			
			$start = microtime(true);
			$this->tick();
			$time = microtime(true) - $start;
			if ($time < 0.025) {
				time_sleep_until(microtime(true) + 0.025 - $time);
			}
		}
	}

	protected function tick() {				
		while(($data = $this->readMainToThreadPacket())){
			$this->checkPacket($data);
		}
	}
	
	protected function checkPacket($data) {
		if($data->isBatch) {
			$str = "";
			foreach($data->packets as $p){
				if($p instanceof DataPacket){
					if(!$p->isEncoded){					
						$p->encode();
					}
					$str .= Binary::writeInt(strlen($p->buffer)) . $p->buffer;
				}else{
					$str .= Binary::writeInt(strlen($p)) . $p;
				}
			}
			$buffer = zlib_encode($str, ZLIB_ENCODING_DEFLATE, $data->networkCompressionLevel);
			$pk = new BatchPacket();
			$pk->payload = $buffer;
			$pk->encode();
			$pk->isEncoded = true;
			foreach($data->targets as $target){
				$result = $this->makeBuffer($target[0], $target[1], $pk, false, false);
			}
		} else {
			$result = $this->makeBuffer($data->identifier, $data->additionalChar, $data->packet, $data->needACK, $data->identifierACK);;
		}
		$this->externalQueue[] = $result;
	}

	protected function makeBuffer($identifier, $additionalChar, $fullPacket, $needACK, $identifierACK) {		
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
