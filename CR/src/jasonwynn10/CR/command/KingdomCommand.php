<?php
declare(strict_types=1);
namespace jasonwynn10\CR\command;

use jasonwynn10\CR\form\KingdomInformationForm;
use jasonwynn10\CR\Main;
use pocketmine\command\CommandSender;
use pocketmine\command\PluginCommand;
use pocketmine\Player;

class KingdomCommand extends PluginCommand {
	/**
	 * KingdomCommand constructor.
	 *
	 * @param Main $plugin
	 */
	public function __construct(Main $plugin) {
		parent::__construct("kingdom", $plugin);
		$this->setUsage("/k");
		$this->setDescription(""); //TODO: command description
		$this->setPermission("cr.command.kingdom");
		$this->setAliases(["k"]);
	}

	/**
	 * @param CommandSender $sender
	 * @param string        $commandLabel
	 * @param array         $args
	 *
	 * @return bool|mixed
	 */
	public function execute(CommandSender $sender, string $commandLabel, array $args) {
		if($this->testPermission($sender)) {
			if($sender instanceof Player) {
				Main::sendPlayerDelayedForm($sender, new KingdomInformationForm($sender));
			}
			return true;
		}
		return false;
	}
}