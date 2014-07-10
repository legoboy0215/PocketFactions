<?php

namespace pocketfactions\utils;

use pocketfactions\faction\Rank;
use pocketfactions\faction\State;
use pocketfactions\faction\Chunk;
use pocketfactions\faction\Faction;
use pocketfactions\Main;
use pocketfactions\tasks\ReadDatabaseTask;
use pocketfactions\tasks\WriteDatabaseTask;
use pocketmine\IPlayer;
use pocketmine\scheduler\AsyncTask;
use pocketmine\Server;

class FactionList{
	const MAGIC_P = "\x00\x00\xff\xffFACTION-LIST";
	const MAGIC_S = "END-OF-LIST-\xff\xff\x00\x00";
	const WILDERNESS = 0;
	const PVP = 1;
	const SAFE = 2;
	/** @var \SQLite3 */
	private $db = null;
	/**
	 * @var bool|Faction[]
	 */
	private $factions = false;
	/**
	 * @var null|AsyncTask
	 */
	public $currentAsyncTask = null;
	public function __construct(Main $main){
		$this->path = $main->getFactionsFilePath();
		$this->server = Server::getInstance();
		$this->main = $main;
		$this->load();
	}
	protected function load(){
		if(!is_file($this->path)){
			$this->factions = [];
			Faction::newInstance("PvP-Zone", "console", [new Rank(0, "staff", 0)], 0, $this->main, $this->server->getDefaultLevel()->getSafeSpawn(), $this->server->getServerName() . " server-owned PvP areas", true, self::PVP); // console is a banned name in PocketMine-MP
			Faction::newInstance("Safe-Zone", "console", [new Rank(0, "staff", 0)], 0, $this->main, $this->server->getDefaultLevel()->getSafeSpawn(), $this->server->getServerName() . " server-owned PvP-free areas", true, self::SAFE);
		}
		else{
			$this->loadFrom(fopen($this->path, "rb"));
		}
	}
	/**
	 * @param resource $res
	 */
	public function loadFrom($res){
		$this->scheduleAsyncTask(new ReadDatabaseTask($res, array($this, "setAll"), array($this, "setFactionsStates"), $this->main));
	}
	public function save(){
		$this->saveTo(fopen($this->path, "wb"));
	}
	/**
	 * @param resource $res
	 */
	public function saveTo($res){
		$this->scheduleAsyncTask(new WriteDatabaseTask($res, $this->main));
	}
	/**
	 * @param AsyncTask $asyncTask
	 */
	public function scheduleAsyncTask(AsyncTask $asyncTask){
		if(($this->currentAsyncTask instanceof AsyncTask) and !$this->currentAsyncTask->isFinished()){
			trigger_error("Attempt to schedule an I/O task at Factions database rejected due to another I/O operation at the same resource running");
		}
		$this->server->getScheduler()->scheduleAsyncTask($asyncTask);
	}
	/**
	 * @param Faction[] $factions
	 */
	public function setAll(array $factions){
		$this->factions = [];
		if($this->db instanceof \SQLite3){
			$this->db->close();
			$this->db = null;
		}
		$this->db = new \SQLite3(":memory:");
		$this->db->exec("CREATE TABLE factions (id INT, name TEXT);");
		$this->db->exec("CREATE TABLE factions_chunks (x INT, z INT, ownerid INT);");
		$this->db->exec("CREATE TABLE factions_rels (smallid INT, largeid INT, relid INT);");
		$this->db->exec("CREATE TABLE factions_members (lowname TEXT, factionid INT);");
		foreach($factions as $f){
			$this->add($f);
		}
	}
	public function add(Faction $faction){
		$this->factions[$faction->getID()] = $faction;
		$op = $this->db->prepare("INSERT INTO factions (id, name) VALUES (:id, :name);");
		$op->bindValue(":id", $faction->getID());
		$op->bindValue(":name", $faction->getName());
		$op->execute();
		foreach($faction->getChunks() as $chunk){
			$op = $this->db->prepare("INSERT INTO factions_chunks (x, z, ownerid) VALUES (:x, :z, :id);"); // can we make it faster?
			$op->bindValue(":x", $chunk->getX());
			$op->bindValue(":z", $chunk->getZ());
			$op->bindValue(":id", $faction->getID());
			$op->execute();
		}
		foreach($faction->getMembers() as $member){
			$op = $this->db->prepare("INSERT INTO factions_members (lowname, factionid) VALUES (:lowname, :id);");
			$op->bindValue(":lowname", strtolower($member));
			$op->bindValue(":id", $faction->getID());
			$op->execute();
		}
	}
	public function __destruct(){
		$this->db->close();
		$this->save();
	}
	/**
	 * @return bool|IFaction[]
	 */
	public function getAll(){
		return $this->factions;
	}
	public function getFactionBySimilarName($name){
		$op = $this->db->prepare("SELECT factionid FROM factions_members WHERE lowname LIKE :lowname;");
		$op->bindValue(":lowname", mb_strtolower($name));
		$result = $op->execute();
		$result = $result->fetchArray(SQLITE3_ASSOC);
		if($result === false){
			return false;
		}
		$id = $result["factionid"];
		return $this->factions[$id];
	}
	/**
	 * @param string|int|IPlayer|Chunk $identifier
	 * @return bool|null|Faction
	 */
	public function getFaction($identifier){
		if($this->factions === false or $this->db === null){
			return null;
		}
		switch(true){
			case is_string($identifier): // faction name
				$result = $this->db->query("SELECT id FROM factions WHERE name = '$identifier';");
				$result = $result->fetchArray(SQLITE3_ASSOC);
				if($result === false){
					return false;
				}
				$id = $result["id"];
				return $this->factions[$id];
			case is_int($identifier): // ID
				return isset($this->factions[$identifier]) ? $this->factions[$identifier]:false;
			case $identifier instanceof IPlayer:
				$result = $this->db->query("SELECT factionid FROM factions_members WHERE lowname = '".strtolower($identifier->getName())."';")->fetchArray(SQLITE3_ASSOC);
				if($result === false){
					return false;
				}
				return $this->factions[$result["factionid"]];
			case $identifier instanceof Chunk:
				$op = $this->db->prepare("SELECT ownerid FROM factions_chunks WHERE x = :x AND z = :z");
				$op->bindValue(":x", $identifier->getX());
				$op->bindValue(":z", $identifier->getZ());
				$result = $op->execute()->fetchArray(SQLITE3_ASSOC);
				if($result === false){
					return false;
				}
				return $this->factions[$result["ownerid"]];
			default:
				return false;
		}
	}
	/**
	 * @param $identifier
	 * @return bool|null|Faction|WildernessFaction
	 */
	public function getValidFaction($identifier){
		$f = $this->getFaction($identifier);
		return ($f === false ? $this->main->getWilderness():$f);
	}
	public function disband(Faction $faction){
		unset($this->factions[$faction->getID()]);
		$op = $this->db->prepare("DELETE FROM factions WHERE id = :id;");
		$op->bindValue(":id", $faction->getID());
		$op->execute();
		$op = $this->db->prepare("DELETE FROM factions_chunks WHERE ownerid = :id;");
		$op->bindValue(":id", $faction->getID());
		$op->execute();
		$op = $this->db->prepare("DELETE FROM factions_members WHERE factionid = :id;");
		$op->bindValue(":id", $faction->getID());
		$op->execute();
		$op = $this->db->prepare("DELETE FROM factions_rels WHERE smallid = :id OR largeid = :id;");
		$op->bindValue(":id", $faction->getID());
		$op->execute();
	}
	/**
	 * @param IFaction $f0
	 * @param IFaction $f1
	 * @return int
	 */
	public function getFactionsState(IFaction $f0, IFaction $f1){
		$ids = [$f0->getID(), $f1->getID()];
		$op = $this->db->prepare("SELECT relid FROM factions_rels WHERE smallid = :small AND largeid = :large");
		$op->bindValue(":small", min($ids));
		$op->bindValue(":large", max($ids));
		$result = $op->execute()->fetchArray(SQLITE3_ASSOC);
		return $result === false ? State::REL_NEUTRAL:$result["relid"];
	}
	public function setFactionsState(State $state){
		$op = $this->db->prepare("INSERT OR REPLACE INTO factions_rels (smallid, largeid, relid) VALUES (:min, :max, :state);");
		$op->bindValue(":min", $state->getSmall());
		$op->bindValue(":max", $state->getLarge());
		$op->bindValue(":state", $state->getState());
		$op->execute();
	}
	/**
	 * @param State[] $states
	 */
	public function setFactionsStates(array $states){
		foreach($states as $state){
			$this->setFactionsState($state);
		}
	}
	/**
	 * @return State[]
	 */
	public function getFactionsStates(){
		$out = [];
		$data = $this->db->query("SELECT * FROM factions_rels");
		while(($datum = $data->fetchArray(SQLITE3_ASSOC)) !== false){
			$out[] = new State($this->factions[$datum["smallid"]], $this->factions[$datum["largeid"]], $datum["relid"]);
		}
		return $out;
	}
	public function onChunkClaimed(Faction $faction, Chunk $chunk){
		$op = $this->db->prepare("INSERT INTO factions_chunks (x, z, ownerid) VALUES (:x, :z, :id);");
		$op->bindValue(":x", $chunk->getX());
		$op->bindValue(":z", $chunk->getZ());
		$op->bindValue(":id", $faction->getID());
		$op->execute();
	}
	public function onMemberJoin(Faction $faction, $name){
		$op = $this->db->prepare("INSERT INTO factions_members (factionid, lowname) VALUES (:id, :name);");
		$op->bindValue(":id", $faction->getID());
		$op->bindValue(":name", strtolower($name));
		$op->execute();
	}
	public function onMemberKick($name){
		$op = $this->db->prepare("DELETE FROM factions_members WHERE lowname = :name;");
		$op->bindValue(":name", strtolower($name));
		$op->execute();
	}
}
