<?php
declare(strict_types=1);
namespace jasonwynn10\CR\form;

use jasonwynn10\CR\Main;
use pocketmine\form\Form;
use pocketmine\form\MenuForm;
use pocketmine\form\MenuOption;
use pocketmine\Player;

class KingdomSelectionForm extends MenuForm {
	/**
	 * KingdomSelectionForm constructor.
	 */
	public function __construct() {
		$options = [];
		foreach(Main::getInstance()->getKingdomNames() as $kingdom => $locations) {
			$options[] = new MenuOption($kingdom);
		}
		parent::__construct("Kingdom Selection", "Choose a kingdom to start!", $options);
	}

	/**
	 * @param Player $player
	 *
	 * @return null|Form
	 */
	public function onClose(Player $player) : ?Form {
		return new self;
	}

	/**
	 * @param Player $player
	 *
	 * @return null|Form
	 */
	public function onSubmit(Player $player) : ?Form {
		$option = $this->getOption($this->getSelectedOptionIndex())->getText();
		if(Main::getInstance()->setPlayerKingdom($player, $option)) {
			return new TeleportLocationForm($option);
		}else{
			return new self;
		}
	}
}