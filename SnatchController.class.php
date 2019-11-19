<?php

namespace Budabot\User\Modules;

/**
 * A command to list all currently unplanted tower fields
 *
 * @author Nadyita (RK5)
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'snatch',
 *		accessLevel = 'member',
 *		description = 'List unplanted tower fields',
 *		help        = 'snatch.txt'
 *	)
 */
class SnatchController {
	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 * @var string $moduleName
	 */
	public $moduleName;

	/**
	 * @var \Budabot\Core\Budabot $chatBot
	 * @Inject
	 */
	public $chatBot;

	/**
	 * @var \Budabot\Core\Text $text
	 * @Inject
	 */
	public $text;

	/**
	 * @var \Budabot\Core\DB $db
	 * @Inject
	 */
	public $db;

	/**
	 * @var \Budabot\Core\Http $http
	 * @Inject
	 */
	public $http;

	/**
	 * @var \Budabot\User\Modules\PlayfieldController $playfieldController
	 * @Inject
	 */
	public $playfieldController;

	/**
	 * @var \Budabot\User\Modules\TowerController $towerController
	 * @Inject
	 */
	public $towerController;

	/**
	 * An array of all unclaimed fields since the last check in format [ ["WW", 1], ["AE", 3] ]
	 *
	 * @var array|null $lastUnclaimedFields
	 */
	public $lastUnclaimedFields;

	/**
	 * The URL to the API that lists unclaimed tower fields
	 * @var string SNATCH_API
	 */
	const SNATCH_API = 'http://echtedomain.club/lc.php?faction=';

	/**
	 * The !snatch command retrieves a list of currently unclaimed tower fields
	 *
	 * @param string                     $message The received message
	 * @param string                     $channel Where was the message received ("tell", "priv" or "guild")
	 * @param string                     $sender  Name of the person sending the command
	 * @param \Budabot\Core\CommandReply $sendto  Object to send the reply with
	 * @param string[]                   $args    The arguments to the command. Empty as we don't accept any
	 * @return void
	 *
	 * @HandlesCommand("snatch")
	 * @Matches("/^snatch$/i")
	 */
	public function snatchCommand($message, $channel, $sender, $sendto, $args) {
		$this->http
				->get(static::SNATCH_API)
				->withTimeout(5)
				->withCallback(function($response) use ($sendto) {
					$this->showAllUnclaimedSites($response, $sendto);
				});
	}

	/**
	 * Send a list of all currently unclaimed tower sites to $sendto
	 *
	 * @param \StdClass                  $response The received response
	 * @param \Budabot\Core\CommandReply $sendto  Object to send the reply with
	 * @return void
	 */
	public function showAllUnclaimedSites($response, $sendto) {
		if (isset($response->error)) {
			$msg = "There was an error getting the list of unclaimed tower sites: ".
				$response->error.
				". Please try again later.";
			$sendto->reply($msg);
			return;
		}
		$sites = @json_decode($response->body, true);
		if (!is_array($sites)) {
			$msg = "There seems to have been an error getting the list of unclaimed sites. ".
				"Please try again later.";
			$sendto->reply($msg);
			return;
		}
		if (empty($sites)) {
			$msg = "There are currently no tower sites to be snatched. Try again later.";
			$sendto->reply($msg);
			return;
		}

		$numSites = 0;
		$extractSiteNum = function($site) {
			return (int)substr($site, 1);
		};
		$blobs = array();
		$this->lastUnclaimedFields = array();
		foreach ($sites as $playfieldName => $sites) {
			$playfield = $this->playfieldController->getPlayfieldByName($playfieldName);
			if ($playfield === null) {
				$msg = "Playfield '$playfieldName' could not be found.";
				$sendto->reply($msg);
				return;
			}
			$siteNumbers = array_map($extractSiteNum, array_keys($sites));
			$data = $this->getTowerSitesInPlayfield($playfield->id);
			$data = $this->getSitesInSiteList($data, $siteNumbers);

			foreach ($data as $row) {
				$this->lastUnclaimedFields[] = [$row->short_name, $row->site_number];
				$numSites++;
				$blobs []= "<pagebreak>" . $this->formatSiteInfo($row);
			}
		}
		$msg = $this->getAnnounceMessage($blobs, false);
		$sendto->reply($msg);
	}

	/**
	 * Render information about a single tower site
	 *
	 * @param \Budabot\Core\DBRow $row A row of tower_site
	 * @return string The rendered string
	 */
	protected function formatSiteInfo($row) {
		$waypointLink = $this->text->makeChatcmd($row->x_coord . "x" . $row->y_coord, "/waypoint {$row->x_coord} {$row->y_coord} {$row->playfield_id}");
		$attacksLink = $this->text->makeChatcmd("Recent attacks", "/tell <myname> attacks {$row->short_name} {$row->site_number}");
		$victoryLink = $this->text->makeChatcmd("Recent victories", "/tell <myname> victory {$row->short_name} {$row->site_number}");

		$blob = "Short name: <highlight>{$row->short_name} {$row->site_number}<end>\n";
		$blob .= "Long name: <highlight>{$row->site_name}, {$row->long_name}<end>\n";
		$blob .= "Level range: <highlight>{$row->min_ql}-{$row->max_ql}<end>\n";
		$blob .= "Center coordinates: $waypointLink\n";
		$blob .= $attacksLink . "\n";
		$blob .= $victoryLink;
		
		return $blob;
	}

	/**
	 * @Event("timer(30min)")
	 * @Description("Announce new unclaimed tower sites")
	 * @DefaultStatus("1")
	 */
	public function checkForNewUnclaimedSites() {
		$this->http
				->get(static::SNATCH_API)
				->withTimeout(20)
				->withCallback([$this, 'announceNewUnclaimedSites']);
	}

	/**
	 * Get a list of all tower sites in a playfield ID
	 *
	 * @param int $playfieldId The Playfield ID
	 * @return \Budabot\Core\DBRow[]
	 */
	protected function getTowerSitesInPlayfield($playfieldId) {
		$sql = "SELECT *, t1.playfield_id, t1.site_number ".
			"FROM tower_site t1 ".
			"JOIN playfields p ON (t1.playfield_id = p.id) ".
			"WHERE t1.playfield_id = ?";
		return $this->db->query($sql, $playfieldId);
	}

	/**
	 * Filter the database values to only contain tower sites we're looking for
	 *
	 * @param \Budabot\Core\DBRow[] $rows All sites for a playfield
	 * @param int[] $siteList List of all site numbers to show
	 * @return \Budabot\Core\DBRow[] The filtered result
	 */
	protected function getSitesInSiteList($rows, $siteList) {
		return array_values(
			array_filter(
				$rows,
				function($row) use ($siteList) {
					return in_array($row->site_number, $siteList);
				}
			)
		);
	}

	/**
	 * Send a list of all new unclaimed tower sites to the guild chat
	 *
	 * @param \StdClass $response The received response
	 * @return void
	 */
	public function announceNewUnclaimedSites($response) {
		if (isset($response->error) || !isset($response->body)) {
			return;
		}
		$sites = @json_decode($response->body, true);
		if (!is_array($sites)) {
			return;
		}
		if (empty($sites)) {
			$this->lastUnclaimedFields = array();
			return;
		}

		$extractSiteNum = function($site) {
			return (int)substr($site, 1);
		};
		$newUnclaimedFields = array();
		$blobs = array();
		foreach ($sites as $playfieldName => $fields) {
			$playfield = $this->playfieldController->getPlayfieldByName($playfieldName);
			if ($playfield === null) {
				continue;
			}
			$siteNumbers = array_map($extractSiteNum, array_keys($fields));
			$data = $this->getTowerSitesInPlayfield($playfield->id);
			$data = $this->getSitesInSiteList($data, $siteNumbers);
			foreach ($data as $row) {
				$newUnclaimedFields[] = [$row->short_name, $row->site_number];
				if (in_array([$row->short_name, $row->site_number], $this->lastUnclaimedFields)) {
					continue;
				}
				$blobs []= "<pagebreak>" . $this->formatSiteInfo($row);
			}
		}
		// If this cronjob runs for the first time, don't announce anything
		if ($this->lastUnclaimedFields === null) {
			$blobs = array();
		}
		$this->lastUnclaimedFields = $newUnclaimedFields;
		if (count($blobs) > 0) {
			$msg = ":::<highlight>ATTENTION<end>::: ".$this->getAnnounceMessage($blobs, true);
			$this->chatBot->sendGuild($msg, true);
		}
	}

	/**
	 * Construct a message to send that exclaims how many (new) sites are ready for snatching
	 *
	 * @param string[] $blobs The popup-parts to display
	 * @param boolean $new true if these are only new, formerly unannounced sites, else false
	 * @return string The complete message to send
	 */
	public function getAnnounceMessage($blobs, $new=false) {
		$numSites = count($blobs);
		$site = ($numSites === 1) ? "site" : "sites";
		$newText = $new ? "new " : "";
		$msg = "The following ".
			$this->text->makeBlob(
				(($numSites === 1) ? "" : "${numSites} ").
				"${newText}unplanted tower ${site}",
				join("\n\n", $blobs),
				"Unplanted tower ${site}"
			)." ".
			(($numSites === 1) ? "is " : "are ").
			"ready to be snatched by your org.";
		return $msg;
	}
}
