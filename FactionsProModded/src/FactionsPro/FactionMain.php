<?php

namespace FactionsPro;

/*
 * 
 * v1.3.0 To Do List
 * [X] Separate into Command, Listener, and Main files
 * [X] Implement commands (plot claim, plot del)
 * [X] Get plots to work
 * [X] Add plot to config
 * [X] Add faction description /f desc <faction>
 * [X] Only leaders can edit motd, only members can check
 * [X] More beautiful looking (and working) config
 */

use _64FF00\PureChat\PureChat;
use awzaw\antispampro\AntiSpamPro;
use pocketmine\level\Level;
use pocketmine\level\Position;
use pocketmine\plugin\PluginBase;
use pocketmine\command\CommandSender;
use pocketmine\command\Command;
use pocketmine\event\Listener;
use pocketmine\Player;
use pocketmine\scheduler\PluginTask;
use pocketmine\utils\TextFormat;
use pocketmine\utils\Config;
use pocketmine\block\Snow;
use pocketmine\math\Vector3;

class FactionMain extends PluginBase implements Listener {


	public static $instance;
	/** @var \SQLite3 $db */
	public $db;
	/** @var Config $prefs */
	public $prefs;
	public $war_req = [];
	public $wars = [];
	public $war_players = [];
	/** @var AntiSpamPro $antispam */
	public $antispam;
	/** @var PureChat $purechat */
	public $purechat;
	public $factionChatActive = [];
	public $allyChatActive = [];
	/** @var FactionCommands $fCommand */
	private $fCommand;

	public function onEnable() {
		self::$instance = $this;

		@mkdir($this->getDataFolder());

		if (!file_exists($this->getDataFolder() . "BannedNames.txt")) {
			$file = fopen($this->getDataFolder() . "BannedNames.txt", "w");
			$txt = "Admin:admin:Staff:staff:Owner:owner:Builder:builder:Op:OP:op";
			fwrite($file, $txt);
		}


		$this->getServer()->getPluginManager()->registerEvents(new FactionListener($this), $this);

		$this->antispam = $this->getServer()->getPluginManager()->getPlugin("AntiSpamPro");
		if ($this->antispam) {
			$this->getLogger()->info("AntiSpamPro Integration Enabled");
		}
		$this->purechat = $this->getServer()->getPluginManager()->getPlugin("PureChat");
		if ($this->purechat) {
			$this->getLogger()->info("PureChat Integration Enabled");
		}

		$this->fCommand = new FactionCommands($this);

		$this->prefs = new Config($this->getDataFolder() . "Prefs.yml", CONFIG::YAML, array(
			"MaxFactionNameLength" => 15,
			"MaxPlayersPerFaction" => 5,
			"OnlyLeadersAndOfficersCanInvite" => true,
			"OfficersCanClaim" => false,
			"PlotSize" => 25,
			"PlayersNeededInFactionToClaimAPlot" => 7,
			"PowerNeededToClaimAPlot" => 1000,
			"PowerNeededToSetOrUpdateAHome" => 0,
			"PowerGainedPerPlayerInFaction" => 50,
			"PowerGainedPerKillingAnEnemy" => 10,
			"PowerGainedPerAlly" => 100,
			"AllyLimitPerFaction" => 0,
			"TheDefaultPowerEveryFactionStartsWith" => 0,
			"EnableOverClaim" => true,
			"ClaimWorlds" => ["world"],
			"AllowChat" => true,
			"AllowFactionPvp" => false,
			"AllowAlliedPvp" => false
		));
		$this->db = new \SQLite3($this->getDataFolder() . "FactionsPro.db");
		$this->db->exec("CREATE TABLE IF NOT EXISTS master (player TEXT PRIMARY KEY COLLATE NOCASE, faction TEXT, rank TEXT);");
		$this->db->exec("CREATE TABLE IF NOT EXISTS confirm (player TEXT PRIMARY KEY COLLATE NOCASE, faction TEXT, invitedby TEXT, timestamp INT);");
		$this->db->exec("CREATE TABLE IF NOT EXISTS alliance (player TEXT PRIMARY KEY COLLATE NOCASE, faction TEXT, requestedby TEXT, timestamp INT);");
		$this->db->exec("CREATE TABLE IF NOT EXISTS motdrcv (player TEXT PRIMARY KEY, timestamp INT);");
		$this->db->exec("CREATE TABLE IF NOT EXISTS motd (faction TEXT PRIMARY KEY, message TEXT);");
		$this->db->exec("CREATE TABLE IF NOT EXISTS plots(faction TEXT PRIMARY KEY, x1 INT, z1 INT, x2 INT, z2 INT);");
		$this->db->exec("CREATE TABLE IF NOT EXISTS home(faction TEXT PRIMARY KEY, x INT, y INT, z INT, world TEXT);");
		$this->db->exec("CREATE TABLE IF NOT EXISTS strength(faction TEXT PRIMARY KEY, DTR INT);");
		$this->db->exec("CREATE TABLE IF NOT EXISTS allies(ID INT PRIMARY KEY,faction1 TEXT, faction2 TEXT);");
		$this->db->exec("CREATE TABLE IF NOT EXISTS enemies(ID INT PRIMARY KEY,faction1 TEXT, faction2 TEXT);");
		$this->db->exec("CREATE TABLE IF NOT EXISTS alliescountlimit(faction TEXT PRIMARY KEY, count INT);");
		$this->getServer()->getScheduler()->scheduleRepeatingTask(new class($this) extends PluginTask{
			public function onRun(int $currentTick) {
				/** @var FactionMain $plugin */
				$plugin = $this->getOwner();
				foreach($plugin->getServer()->getOnlinePlayers() as $player) {
					$fac = $plugin->getFaction($player->getName());
					if($fac !== null)
						if($plugin->getFactionPower($fac) <= 0)
							$plugin->db->query("DELETE FROM plots WHERE faction='$fac';");
				}
			}
		}, 20);
	}

	public static function getInstance() {
		return self::$instance;
	}

	public function onCommand(CommandSender $sender, Command $command, string $label, array $args) :bool {
		return $this->fCommand->onCommand($sender, $command, $label, $args);
	}

	public function setEnemies($faction1, $faction2) {
		$stmt = $this->db->prepare("INSERT INTO enemies (faction1, faction2) VALUES (:faction1, :faction2);");
		$stmt->bindValue(":faction1", $faction1);
		$stmt->bindValue(":faction2", $faction2);
		$stmt->execute();
	}

	public function areEnemies($faction1, $faction2) {
		$result = $this->db->query("SELECT ID FROM enemies WHERE faction1 = '$faction1' AND faction2 = '$faction2';");
		$resultArr = $result->fetchArray(SQLITE3_ASSOC);
		return empty($resultArr) == false;
	}

	public function isInFaction($player) {
		$result = $this->db->query("SELECT player FROM master WHERE player='$player';");
		$array = $result->fetchArray(SQLITE3_ASSOC);
		return empty($array) == false;
	}

	public function getFaction($player) {
		$faction = $this->db->query("SELECT faction FROM master WHERE player='$player';");
		$factionArray = $faction->fetchArray(SQLITE3_ASSOC);
		return $factionArray["faction"];
	}

	public function setFactionPower($faction, $DTR) {
		if ($DTR < 0) {
			$DTR = 0;
		}
		$stmt = $this->db->prepare("INSERT OR REPLACE INTO strength (faction, DTR) VALUES (:faction, :DTR);");
		$stmt->bindValue(":faction", $faction);
		$stmt->bindValue(":DTR", $DTR);
		$stmt->execute();
	}

	public function setAllies($faction1, $faction2) {
		$stmt = $this->db->prepare("INSERT INTO allies (faction1, faction2) VALUES (:faction1, :faction2);");
		$stmt->bindValue(":faction1", $faction1);
		$stmt->bindValue(":faction2", $faction2);
		$stmt->execute();
	}

	public function areAllies($faction1, $faction2) {
		$result = $this->db->query("SELECT ID FROM allies WHERE faction1 = '$faction1' AND faction2 = '$faction2';");
		$resultArr = $result->fetchArray(SQLITE3_ASSOC);
		return empty($resultArr) == false;
	}

	public function updateAllies($faction) {
		$stmt = $this->db->prepare("INSERT OR REPLACE INTO alliescountlimit(faction, count) VALUES (:faction, :count);");
		$stmt->bindValue(":faction", $faction);
		$result = $this->db->query("SELECT ID FROM allies WHERE faction1='$faction';");
		$i = 0;
		while ($resultArr = $result->fetchArray(SQLITE3_ASSOC)) {
			$i = $i + 1;
		}
		$stmt->bindValue(":count", (int) $i);
		$stmt->execute();
	}

	public function getAlliesCount($faction) {

		$result = $this->db->query("SELECT count FROM alliescountlimit WHERE faction = '$faction';");
		$resultArr = $result->fetchArray(SQLITE3_ASSOC);
		return (int) $resultArr["count"];
	}

	public function getAlliesLimit() {
		return (int) $this->prefs->get("AllyLimitPerFaction");
	}

	public function deleteAllies($faction1, $faction2) {
		$stmt = $this->db->prepare("DELETE FROM allies WHERE faction1 = '$faction1' AND faction2 = '$faction2';");
		$stmt->execute();
	}

	public function getFactionPower($faction) {
		$result = $this->db->query("SELECT DTR FROM strength WHERE faction = '$faction';");
		$resultArr = $result->fetchArray(SQLITE3_ASSOC);
		return (int) $resultArr["DTR"];
	}

	public function addFactionPower($faction, $DTR) {
		if ($this->getFactionPower($faction) + $DTR < 0) {
			$DTR = $this->getFactionPower($faction);
			$this->db->query("DELETE FROM plots WHERE faction='$faction';");
		}
		$stmt = $this->db->prepare("INSERT OR REPLACE INTO strength (faction, DTR) VALUES (:faction, :DTR);");
		$stmt->bindValue(":faction", $faction);
		$stmt->bindValue(":DTR", $this->getFactionPower($faction) + $DTR);
		$stmt->execute();
	}

	public function subtractFactionPower($faction, $DTR) {
		if ($this->getFactionPower($faction) - $DTR < 0) {
			$DTR = $this->getFactionPower($faction);
			$this->db->query("DELETE FROM plots WHERE faction='$faction';");
		}
		$stmt = $this->db->prepare("INSERT OR REPLACE INTO strength (faction, DTR) VALUES (:faction, :DTR);");
		$stmt->bindValue(":faction", $faction);
		$stmt->bindValue(":DTR", $this->getFactionPower($faction) - $DTR);
		$stmt->execute();
	}

	public function isLeader($player) {
		$faction = $this->db->query("SELECT rank FROM master WHERE player='$player';");
		$factionArray = $faction->fetchArray(SQLITE3_ASSOC);
		return $factionArray["rank"] == "Leader";
	}

	public function isOfficer($player) {
		$faction = $this->db->query("SELECT rank FROM master WHERE player='$player';");
		$factionArray = $faction->fetchArray(SQLITE3_ASSOC);
		return $factionArray["rank"] == "Officer";
	}

	public function isMember($player) {
		$faction = $this->db->query("SELECT rank FROM master WHERE player='$player';");
		$factionArray = $faction->fetchArray(SQLITE3_ASSOC);
		return $factionArray["rank"] == "Member";
	}

	public function getPlayersInFactionByRank(CommandSender $s, $faction, $rank) {

		if ($rank != "Leader") {
			$rankname = $rank . 's';
		} else {
			$rankname = $rank;
		}
		$team = "";
		$result = $this->db->query("SELECT player FROM master WHERE faction='$faction' AND rank='$rank';");
		$row = array();
		$i = 0;

		while ($resultArr = $result->fetchArray(SQLITE3_ASSOC)) {
			$row[$i]['player'] = $resultArr['player'];
			if ($this->getServer()->getPlayerExact($row[$i]['player']) instanceof Player) {
				$team .= TextFormat::ITALIC . TextFormat::AQUA . $row[$i]['player'] . TextFormat::GREEN . "[ON]" . TextFormat::RESET . TextFormat::WHITE . "||" . TextFormat::RESET;
			} else {
				$team .= TextFormat::ITALIC . TextFormat::AQUA . $row[$i]['player'] . TextFormat::RED . "[OFF]" . TextFormat::RESET . TextFormat::WHITE . "||" . TextFormat::RESET;
			}
			$i = $i + 1;
		}

		$s->sendMessage($this->formatMessage("~ *<$rankname> of |$faction|* ~", true));
		$s->sendMessage($team);
	}

	public function getAllAllies(CommandSender $s, $faction) {

		$team = "";
		$result = $this->db->query("SELECT faction2 FROM allies WHERE faction1='$faction';");
		$row = array();
		$i = 0;
		while ($resultArr = $result->fetchArray(SQLITE3_ASSOC)) {
			$row[$i]['faction2'] = $resultArr['faction2'];
			$team .= TextFormat::ITALIC . TextFormat::RED . $row[$i]['faction2'] . TextFormat::RESET . TextFormat::WHITE . "||" . TextFormat::RESET;
			$i = $i + 1;
		}

		$s->sendMessage($this->formatMessage("~ Allies of *$faction* ~", true));
		$s->sendMessage($team);
	}

	public function sendListOfTop10FactionsTo(CommandSender $s) {
		$result = $this->db->query("SELECT faction FROM strength ORDER BY DTR DESC LIMIT 10;");
		$i = 0;
		$s->sendMessage($this->formatMessage("~ Top 10 strongest factions ~", true));
		while ($resultArr = $result->fetchArray(SQLITE3_ASSOC)) {
			$j = $i + 1;
			$cf = $resultArr['faction'];
			$pf = $this->getFactionPower($cf);
			$df = $this->getNumberOfPlayers($cf);
			$s->sendMessage(TextFormat::ITALIC . TextFormat::GOLD . "$j -> " . TextFormat::GREEN . "$cf" . TextFormat::GOLD . " with " . TextFormat::RED . "$pf STR" . TextFormat::GOLD . " and " . TextFormat::LIGHT_PURPLE . "$df PLAYERS" . TextFormat::RESET);
			$i = $i + 1;
		}
	}

	public function getPlayerFaction($player) {
		$faction = $this->db->query("SELECT faction FROM master WHERE player='$player';");
		$factionArray = $faction->fetchArray(SQLITE3_ASSOC);
		return $factionArray["faction"];
	}

	public function getLeader($faction) {
		$leader = $this->db->query("SELECT player FROM master WHERE faction='$faction' AND rank='Leader';");
		$leaderArray = $leader->fetchArray(SQLITE3_ASSOC);
		return $leaderArray['player'];
	}

	public function factionExists($faction) {
		$result = $this->db->query("SELECT player FROM master WHERE faction='$faction';");
		$array = $result->fetchArray(SQLITE3_ASSOC);
		return empty($array) == false;
	}

	public function sameFaction($player1, $player2) {
		$faction = $this->db->query("SELECT faction FROM master WHERE player='$player1';");
		$player1Faction = $faction->fetchArray(SQLITE3_ASSOC);
		$faction = $this->db->query("SELECT faction FROM master WHERE player='$player2';");
		$player2Faction = $faction->fetchArray(SQLITE3_ASSOC);
		return $player1Faction["faction"] == $player2Faction["faction"];
	}

	public function getNumberOfPlayers($faction) {
		$query = $this->db->query("SELECT COUNT(player) as count FROM master WHERE faction='$faction';");
		$number = $query->fetchArray();
		return $number['count'];
	}

	public function isFactionFull($faction) {
		return $this->getNumberOfPlayers($faction) >= $this->prefs->get("MaxPlayersPerFaction");
	}

	public function isNameBanned($name) {
		$bannedNames = file_get_contents($this->getDataFolder() . "BannedNames.txt");
		$isbanned = false;
		if (isset($name) && $this->antispam && $this->antispam->getProfanityFilter()->hasProfanity($name)) $isbanned = true;

		return (strpos(strtolower($bannedNames), strtolower($name)) > 0 || $isbanned);
	}

	public function newPlot($faction, $x1, $z1, $x2, $z2) {
		$stmt = $this->db->prepare("INSERT OR REPLACE INTO plots (faction, x1, z1, x2, z2) VALUES (:faction, :x1, :z1, :x2, :z2);");
		$stmt->bindValue(":faction", $faction);
		$stmt->bindValue(":x1", $x1);
		$stmt->bindValue(":z1", $z1);
		$stmt->bindValue(":x2", $x2);
		$stmt->bindValue(":z2", $z2);
		$stmt->execute();
	}

	public function drawPlot(CommandSender $sender, $faction, $x, $y, $z, Level $level, $size) {
		$arm = ($size - 1) / 2;
		$block = new Snow();
		if ($this->cornerIsInPlot($x + $arm, $z + $arm, $x - $arm, $z - $arm)) {
			$claimedBy = $this->factionFromPoint($x, $z);
			$DTR_claimedBy = $this->getFactionPower($claimedBy);
			$DTR_sender = $this->getFactionPower($faction);

			if ($this->prefs->get("EnableOverClaim")) {
				if ($DTR_sender < $DTR_claimedBy) {
					$sender->sendMessage($this->formatMessage("This area is aleady claimed by $claimedBy with $DTR_claimedBy STR. Your faction has $DTR_sender DTR. You don't have enough DTR to overclaim this plot."));
				} else {
					$sender->sendMessage($this->formatMessage("This area is aleady claimed by $claimedBy with $DTR_claimedBy STR. Your faction has $DTR_sender DTR. Type /f overclaim to overclaim this plot if you want."));
				}
				return false;
			} else {
				$sender->sendMessage($this->formatMessage("Overclaiming is disabled."));
				return false;
			}
		}
		$level->setBlock(new Vector3($x + $arm, $y, $z + $arm), $block);
		$level->setBlock(new Vector3($x - $arm, $y, $z - $arm), $block);
		$this->newPlot($faction, $x + $arm, $z + $arm, $x - $arm, $z - $arm);
		return true;
	}

	public function isInPlot(Player $player) {
		$x = $player->getFloorX();
		$z = $player->getFloorZ();
		$result = $this->db->query("SELECT faction FROM plots WHERE $x <= x1 AND $x >= x2 AND $z <= z1 AND $z >= z2;");
		$array = $result->fetchArray(SQLITE3_ASSOC);
		return empty($array) == false;
	}

	public function factionFromPoint($x, $z) {
		$result = $this->db->query("SELECT faction FROM plots WHERE $x <= x1 AND $x >= x2 AND $z <= z1 AND $z >= z2;");
		$array = $result->fetchArray(SQLITE3_ASSOC);
		return $array["faction"];
	}

	public function inOwnPlot(Player $player) {
		$playerName = $player->getName();
		$x = $player->getFloorX();
		$z = $player->getFloorZ();
		return $this->getPlayerFaction($playerName) == $this->factionFromPoint($x, $z);
	}

	public function pointIsInPlot($x, $z) {
		$result = $this->db->query("SELECT faction FROM plots WHERE $x <= x1 AND $x >= x2 AND $z <= z1 AND $z >= z2;");
		$array = $result->fetchArray(SQLITE3_ASSOC);
		return !empty($array);
	}

	public function cornerIsInPlot($x1, $z1, $x2, $z2) {
		return($this->pointIsInPlot($x1, $z1) || $this->pointIsInPlot($x1, $z2) || $this->pointIsInPlot($x2, $z1) || $this->pointIsInPlot($x2, $z2));
	}

	public function formatMessage($string, $confirm = false) {
		if ($confirm) {
			return TextFormat::GREEN . "$string";
		} else {
			return TextFormat::YELLOW . "$string";
		}
	}

	public function motdWaiting($player) {
		$stmt = $this->db->query("SELECT player FROM motdrcv WHERE player='$player';");
		$array = $stmt->fetchArray(SQLITE3_ASSOC);
		return !empty($array);
	}

	public function getMOTDTime($player) {
		$stmt = $this->db->query("SELECT timestamp FROM motdrcv WHERE player='$player';");
		$array = $stmt->fetchArray(SQLITE3_ASSOC);
		return $array['timestamp'];
	}

	public function setMOTD($faction, $player, $msg) {
		$stmt = $this->db->prepare("INSERT OR REPLACE INTO motd (faction, message) VALUES (:faction, :message);");
		$stmt->bindValue(":faction", $faction);
		$stmt->bindValue(":message", $msg);
		$stmt->execute();

		$this->db->query("DELETE FROM motdrcv WHERE player='$player';");
	}

	public function getHome($faction) {
		$stmt = $this->db->query("SELECT * FROM home WHERE faction='$faction';");
		$array = $stmt->fetchArray(SQLITE3_ASSOC);
		return new Position($array["x"], $array["y"], $array["z"], $array["world"]);
	}

	public function isHomeSet($faction) {
		$stmt = $this->db->query("SELECT * FROM home WHERE faction='$faction';");
		$array = $stmt->fetchArray(SQLITE3_ASSOC);
		return !empty($array);
	}

	public function setHome($faction, $x, $y, $z, $worldName) {
		$stmt = $this->db->prepare("INSERT OR REPLACE INTO home (faction, x, y, z, world) VALUES (:faction, :x, :y, :z, :world);");
		$stmt->bindValue(":faction", $faction);
		$stmt->bindValue(":x", $x);
		$stmt->bindValue(":y", $y);
		$stmt->bindValue(":z", $z);
		$stmt->bindValue(":world", $worldName);
		$stmt->execute();
	}

	public function updateTag($playername) {
		$p = $this->getServer()->getPlayer($playername);
		$f = $this->getPlayerFaction($playername);
		if (!$this->isInFaction($playername)) {
			if(isset($this->purechat)){
				$levelName = $this->purechat->getConfig()->get("enable-multiworld-chat") ? $p->getLevel()->getName() : null;
				$nameTag = $this->purechat->getNametag($p, $levelName);
				$p->setNameTag($nameTag);
			}else{
				$p->setNameTag(TextFormat::ITALIC . TextFormat::YELLOW . "<$playername>");
			}
		}elseif(isset($this->purechat)) {
			$levelName = $this->purechat->getConfig()->get("enable-multiworld-chat") ? $p->getLevel()->getName() : null;
			$nameTag = $this->purechat->getNametag($p, $levelName);
			$p->setNameTag($nameTag);
		} else {
			$p->setNameTag(TextFormat::ITALIC . TextFormat::GOLD . "<$f> " .
				TextFormat::ITALIC . TextFormat::YELLOW . "<$playername>");
		}
	}
	public function onDisable() {
		$this->db->close();
	}
}
