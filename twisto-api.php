<?php 
	/* 
	 *  Twisto real-time API
	 *  Copyright (C) outa[dev] 2013
	 *
	 *  timeo.php
	 *
	 *
	 *	This program is free software: you can redistribute it and/or modify
	 *  it under the terms of the GNU General Public License as published by
	 *  the Free Software Foundation, either version 3 of the License, or
	 *  (at your option) any later version.
	 *
	 *  This program is distributed in the hope that it will be useful,
	 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
	 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	 *  GNU General Public License for more details.
	 *
	 *  You should have received a copy of the GNU General Public License
	 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
	 *   
	 */

	define('API_BASE_URL', 'http://dev.actigraph.fr/actipages/twisto/module/mobile/pivk/relais.html.php');
	
	//this gets a distant page with curl, via GET and with a cookie (it's always better with cookies)
	function getDistantPage($fields, $cookie) {
		$ch = curl_init();

		curl_setopt($ch, CURLOPT_URL, API_BASE_URL . '?' . $fields);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_COOKIESESSION, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			'Cookie: als=' . urlencode($cookie)
		));

		$server_output = curl_exec($ch);

		curl_close($ch);
		return $server_output;
	}

	//this does the same, but for POST method and with no cookies :(
	function postDistantPage($fields) {
		$ch = curl_init();

		if($fields == null) {
			$fields = array();
		}

		curl_setopt($ch, CURLOPT_URL, API_BASE_URL);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

		$server_output = curl_exec($ch);

		curl_close($ch);
		return $server_output;
	}

	//we already have a cookie containing a list of the lines we want to get schedules for; send it, parse it, return a JSON object with the full schedule.
	//the cookie is of the form STOP|LINE|DIRECTION;STOP|LINE|DIRECTION;...
	function getScheduleFromCookie($cookie) {
		$content = getDistantPage("a=refresh&borne=affiche_borne&ran=1", $cookie);
		$schedule;
		$final = array();

		if($content != null && $content != '') {
			$regex = "/timeo_ligne_nom'>([a-zA-Z0-9- ']+).+timeo_titre_direction'>([a-zA-Z0-9- ']+).+timeo_titre_arret'>([a-zA-Z0-9&; '-]+).+\n.+\n.+\n.+\n((\s<li id='h[0-9]' class='timeo_horaire'>([a-zA-Z0-9&; '-]+)<\/li>\n)*)/";
			preg_match_all($regex, $content, $schedule, PREG_SET_ORDER);

			if($schedule != null) {
				for($i = 0; $i < count($schedule); $i++) {
					for($j =  0; $j < count($schedule[$i]); $j++) {
						switch ($j) {
							case 1:
								$final[$i]['line'] = $schedule[$i][$j];
								break;
							case 2:
								$final[$i]['direction'] = $schedule[$i][$j];
								break;
							case 3:
								$final[$i]['stop'] = $schedule[$i][$j];
								break;
							case 4:
								preg_match_all("/<li id='h[0-9]' class='timeo_horaire'>([a-zA-Z0-9&; -]+)<\/li>/", $schedule[$i][$j], $schedule[$i][$j]);
								
								if($schedule[$i][$j][1] != null) {
									$final[$i]['next'] = $schedule[$i][$j][1];
								}

								break;
						}
					}
				}

				echo html_entity_decode(json_encode($final));
			}	
		}
	}

	//this basically is an alias to getScheduleFromCookie that allows us to search for the next buses for a line, direction, stop instead of a raw cookie.
	//only works for one stop at a time.
	function getScheduleFromDetails($line, $direction, $stop) {
		getScheduleFromCookie($stop . '|' . $line . '|' . $direction);
	}

	//this returns a list of all the available bus lines
	function getLines() {
		$content = postDistantPage(null);
		$lines;
		$final = array();

		//returns a piece of HTML: parse it to only get insteresting info
		preg_match_all("/<option value='([a-zA-Z0-9]+)'>([a-zA-Z0-9 ]+)<\/option>/", $content, $lines, PREG_SET_ORDER);

		if($lines != null) {
			for($i = 0; $i < count($lines); $i++) {
				for($j =  0; $j < count($lines[$i]); $j++) {
					switch ($j) {
						case 1:
							$final[$i]['id'] = $lines[$i][$j];
							break;
						case 2:
							$final[$i]['name'] = $lines[$i][$j];
							break;
					}
				}
			}

			echo html_entity_decode(json_encode($final));
		}
	}

	//this gives us the available directions (A or R) for a specific line
	function getDirection($line) {
		$content = postDistantPage(array("a" => "refresh_list", "ligne" => $line));
		$directions;
		$final = array();

		//this returns a piece of JS code: we only want some of the info
		preg_match_all("/Array\('[A|R]','([a-zA-Z0-9\\\\\-' ]+)'\);/", $content, $directions);

		if($directions != null && $directions[0] != null) {
			$final[0]['id'] = 'A';
			$final[0]['name'] = str_replace("\\", '', $directions[1][0]);

			$final[1]['id'] = 'R';
			$final[1]['name'] = str_replace("\\", '', $directions[1][1]);

			echo html_entity_decode(json_encode($final));
		}
	}

	//this gives the available bus stops for a specific line/direction
	function getStops($line, $direction) {
		$content = postDistantPage(array("a" => "refresh_list", "ligne_sens" => $line . '_' . $direction));
		$stops;
		$final = array();

		preg_match_all("/Array\('([\-_0-9]+)','([a-zA-Z0-9\\\\\-' ]+)'\);/", $content, $stops, PREG_SET_ORDER);

		if($stops != null) {
			for($i = 0; $i < count($stops); $i++) {
				for($j =  0; $j < count($stops[$i]); $j++) {
					switch ($j) {
						case 1:
							$expl = explode('_', $stops[$i][$j]);
							$final[$i]['id'] = $expl[1];
							break;
						case 2:
							$final[$i]['name'] = str_replace("\\", '', $stops[$i][$j]);
							break;
					}
				}
			}

			echo html_entity_decode(json_encode($final));
		}
	}
	
	//check what we want to get
	//if we want the full schedule
	if($_GET['func'] == "getSchedule") {
		if(isset($_GET['data'])) {
			//if we already have a formed cookie
			getScheduleFromCookie($_GET['data']);
		} else if(isset($_GET['line']) && isset($_GET['direction']) && isset($_GET['stop'])) {
			//else, if we're specifying line, direction and stop of the desired schedule
			getScheduleFromDetails($_GET['line'], $_GET['direction'], $_GET['stop']);
		}
	} else if($_GET['func'] == "getLines") {
		//if we want to list the available lines
		getLines();
	} else if($_GET['func'] == "getDirections" && isset($_GET['line'])) {
		//if we want to list the available directions
		getDirection($_GET['line']);
	} else if($_GET['func'] == "getStops" && isset($_GET['line']) && isset($_GET['direction'])) {
		//if we want to list the available bus stops
		getStops($_GET['line'], $_GET['direction']);
	}

?>