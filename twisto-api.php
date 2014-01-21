<?php 
	/* 
		Twisto real-time API
		Copyright (C) outa[dev] 2013
	
		twisto-api.php
		
		
		This program is free software: you can redistribute it and/or modify
		it under the terms of the GNU General Public License as published by
		the Free Software Foundation, either version 3 of the License, or
		(at your option) any later version.
		
		This program is distributed in the hope that it will be useful,
		but WITHOUT ANY WARRANTY; without even the implied warranty of
		MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
		GNU General Public License for more details.
		
		You should have received a copy of the GNU General Public License
		along with this program.  If not, see <http://www.gnu.org/licenses/>. 
	*/

	define('API_BASE_URL', 'http://dev.actigraph.fr/actipages/twisto/module/mobile/pivk/relais.html.php');
	define('MAX_COOKIE_COUNT', 4);
	
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

		if($server_output == false) {
			throwError(curl_error($ch));
		} else {
			return $server_output;
		}

		curl_close($ch);
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

		if($server_output == false) {
			throwError(curl_error($ch));
		}

		curl_close($ch);
		return $server_output;
	}

	//we already have a cookie containing a list of the lines we want to get schedules for; send it, parse it, return a JSON object with the full schedule.
	//the cookie is of the form STOP|LINE|DIRECTION;STOP|LINE|DIRECTION;...
	function getScheduleFromCookie($cookie) {
		$tmpCookies = array();
		$cookiesList = array();
		$finalSchedules = array();

		preg_match_all("/(([0-9]+)\|([0-9a-zA-Z]+)\|(A|R))/", $cookie, $tmpCookies);
		$tmpCookies = $tmpCookies[0];

		//twisto's website will only allow us to request for four bus stops at a time, max.
		//what we're doing here to get around that, is we're splitting our eventually massive request into smaller requests
		for($i = 0; $i < count($tmpCookies) / MAX_COOKIE_COUNT; $i++) {
			//append the next four stops to the cookie
			$j = $i * MAX_COOKIE_COUNT;

			//set it to a first cookie
			$cookiesList[$i] = $tmpCookies[$j];

			//append up to three cookies to this first one
			for($k = 1; $k < MAX_COOKIE_COUNT; $k++) {
				//only append them if they exist, obviously
				if($tmpCookies[$j+$k] != null) {
					$cookiesList[$i] .= ';' . $tmpCookies[$j+$k];
				}
			}

			echo $cookiesList[$i];
		}

		//we then iterate through the list of cookies to send, and request four bus schedules at a time, max.
		for($i = 0; $i < count($cookiesList); $i++) {
			//get page using our current cookie
			$content = getDistantPage("a=refresh&borne=affiche_borne&ran=1", $cookiesList[$i]);
			
			$scheduleStr = null;
			$scheduleArray = array();

			//if we got something
			if($content != null && $content != '') {
				try {
					//a custom style is used when buses are passing now: remove those 
					$content = preg_replace("/<blink style='color:red'>([a-zA-Z0-9]+)<\/blink>/", "$1", $content);

					$regex = "/timeo_ligne_nom'>([a-zA-Z0-9- ']+).+timeo_titre_direction'>([a-zA-Z0-9-\.\- ']+).+timeo_titre_arret'>([a-zA-Z0-9&;\. '\-]+).+\n.+\n.+\n.+\n((\s<li id='h[0-9]' class='timeo_horaire'>([a-zA-Z0-9&;\. '\-]+)<\/li>\n)*)/";
					preg_match_all($regex, $content, $scheduleStr, PREG_SET_ORDER);
					
					//if we could parse the page
					if($scheduleStr != null) {
						for($j = 0; $j < count($scheduleStr); $j++) {
							//for each bus stop, get and save its information
							$scheduleArray[$j]['line'] = ucwords($scheduleStr[$j][1]);
							$scheduleArray[$j]['direction'] = ucwords($scheduleStr[$j][2]);
							$scheduleArray[$j]['stop'] = ucwords($scheduleStr[$j][3]);

							//match the next buses schedules
							preg_match_all("/<li id='h[0-9]' class='timeo_horaire'>([a-zA-Z0-9&;\.\- ]+)<\/li>/", $scheduleStr[$j][4], $scheduleStr[$j][4]);
							
							//if there are any schedules, save them
							if($scheduleStr[$j][4][1] != null) {
								$scheduleArray[$j]['next'] = preg_replace("/([a-zA-Z0-9&;\. '\-]+) vers (A|B) [A-Z0-9&;\. '\-]+/", "Ligne $2 : $1", $scheduleStr[$j][4][1]);
							} else {
								$scheduleArray[$j]['next'] = array("Pas de passage prévu");
							}
						}

						//merge the current cookie's results with the global results
						$finalSchedules = array_merge($finalSchedules, $scheduleArray);
					} else {
						throwError("Parsing error");
					}
				} catch(Exception $e) {
					throwError($e->getMessage());
				}
			} else {
				throwError("No content");
			}
		}

		//display the json results
		echo html_entity_decode(json_encode($finalSchedules));
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

		if($content != null && $content != '') {
			try {
				//returns a piece of HTML: parse it to only get insteresting info
				preg_match_all("/<option value='([a-zA-Z0-9]+)'>([a-zA-Z0-9\/\.\- ]+)<\/option>/", $content, $lines, PREG_SET_ORDER);

				if($lines != null) {
					for($i = 0; $i < count($lines); $i++) {
						$final[$i]['id'] = $lines[$i][1];
						$final[$i]['name'] = ucwords($lines[$i][2]);
					}

					echo html_entity_decode(json_encode($final));
				} else {
					throwError("Parsing error");
				}
			} catch(Exception $e) {
				throwError($e->getMessage());
			}
		} else {
			throwError("No content");
		}
	}

	//this gives us the available directions (A or R) for a specific line
	function getDirection($line) {
		$content = postDistantPage(array("a" => "refresh_list", "ligne" => $line));
		$directions;
		$final = array();

		if($content != null && $content != '') {
			try {
				//this returns a piece of JS code: we only want some of the info
				preg_match_all("/Array\('[A|R]','([a-zA-Z0-9\\\\\-'\. ]+)'\);/", $content, $directions);

				if($directions != null && $directions[0] != null) {
					$final[0]['id'] = 'A';
					$final[0]['name'] = ucwords(str_replace("\\", '', $directions[1][0]));

					$final[1]['id'] = 'R';
					$final[1]['name'] = ucwords(str_replace("\\", '', $directions[1][1]));

					echo html_entity_decode(json_encode($final));
				} else {
					throwError("Parsing error");
				}
			} catch(Exception $e) {
				throwError($e->getMessage());
			}
		} else {
			throwError("No content");
		}
	}

	//this gives the available bus stops for a specific line/direction
	function getStops($line, $direction) {
		$content = postDistantPage(array("a" => "refresh_list", "ligne_sens" => $line . '_' . $direction));
		$stops;
		$final = array();

		if($content != null && $content != '') {
			try {
				preg_match_all("/Array\('([\-_\|0-9]+)','([a-zA-Z0-9\\\\\-'\. ]+)'\);/", $content, $stops, PREG_SET_ORDER);

				if($stops != null) {
					for($i = 0; $i < count($stops); $i++) {
						$expl = explode('_', $stops[$i][1]);
						$final[$i]['id'] = $expl[1];
						$final[$i]['name'] = ucwords(str_replace("\\", '', $stops[$i][2]));
					}

					echo html_entity_decode(json_encode($final));
				} else {
					throwError("Parsing error");
				}
			} catch(Exception $e) {
				throwError($e->getMessage());
			}
		} else {
			throwError("No content");
		}
	}

	function throwError($reason) {
		//exiting with an error displayed in a JSON object
		exit('{"error":"' . $reason . '"}');
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
	} else {
		header('HTTP/1.1 400 Bad Request', true, 400);
		exit();
	}

?>