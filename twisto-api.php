<?php 
	/* 
		Twisto Real-Time API
		Copyright (C) Baptiste Candellier 2013-2014
	
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

	/**
	 * The base URL of the relais.html.php page, on which we will make the requests.
	 */
	define('API_BASE_URL', 'http://dev.actigraph.fr/actipages/twisto/module/mobile/pivk/relais.html.php');

	/**
	 * The maximum number of stops we can request at a time (depends of the source site).
	 */
	define('MAX_COOKIE_COUNT', 4);

	define('MAX_STOPS_COUNT', 50);
	define('CACHE_DIRECTORY', 'cache/');
	define('CACHE_DURATION', 3 * 60 * 60);
	
	/**
	 * Gets a distant page with curl, via GET and with a cookie (it's always better with cookies).
	 *
	 * @param string $fields The GET parameters in the form of an urlencoded string. (foo=bar&bar=foo...)
	 * @param string $cookie The 'als' cookie that will be sent along with the request.
	 *
	 * @return string The raw output from the server.
	 */
	function getDistantPage($fields, $cookie) {
		$ch = curl_init();

		curl_setopt($ch, CURLOPT_URL, API_BASE_URL . '?' . $fields);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_COOKIESESSION, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			'Cookie: als=' . urlencode($cookie)
		));

		$server_output = curl_exec($ch);
		checkServerResult($server_output, $ch);

		return $server_output;
	}

	/**
	 * Gets a distant page with curl, via POST.
	 *
	 * @param array $fields The POST parameters in the form of a named array.
	 * @return string The raw output from the server.
	 */
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
		checkServerResult($server_output, $ch);
		
		return $server_output;
	}

	/**
	 * Checks whether a request to Twisto's website was successful or not.
	 *
	 * @param string $server_output The raw server output.
	 * @param curl $ch The cURL handle.
	 */
	function checkServerResult($server_output, $ch) {
		$network_error_message = array();

		if($server_output == false) {
			curl_close($ch);
			throwError(curl_error($ch));
		} else if (empty($server_output)) {
			curl_close($ch);
			throwError("Service indisponible");
		} else if(preg_match_all("/<div class=(\"|')message reseau bloquant(\"|')>\n.+\n<p class=(\"|')corps_message(\"|')>(.+)<\/p>\n<\/div>/", $server_output, $network_error_message)
			&& $network_error_message[5][0] != null) {
			curl_close($ch);
			throwError("Service indisponible", html_entity_decode($network_error_message[5][0]));
		}

		curl_close($ch);
	}

	/**
	 * Use this function if you already have a cookie containing a list of the lines you want to get schedules for.
	 * 
	 * @param string $cookie The raw cookie that will be sent to the website. 
	 * 	The cookie is of the form STOP|LINE|DIRECTION;STOP|LINE|DIRECTION;...
	 *
	 * @return array An array of schedules corresponding to the input. 
	 * Note : there will never be more schedules in the output than there 
	 * were stops in the input, but there may be less, if no valid data could be fetched for one or several stops.
	 */
	function getScheduleFromCookie($cookie) {
		$tmpCookies = array();
		$cookiesList = array();
		$finalSchedules = array();

		preg_match_all("/(([0-9]+)\|([0-9a-zA-Z]+)\|(A|R))/", $cookie, $tmpCookies);
		$tmpCookies = $tmpCookies[0];

		if(count($tmpCookies) > MAX_STOPS_COUNT) {
			throwError("Trop d'arrêts demandés (maximum : " . MAX_STOPS_COUNT . ")");
		}

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
		}

		//we then iterate through the list of cookies to send, and request four bus schedules at a time, max.
		for($i = 0; $i < count($cookiesList); $i++) {
			//get page using our current cookie
			$content = getDistantPage("a=refresh&borne=affiche_borne&ran=1", $cookiesList[$i]);
			
			$regexMatches = null;
			$currentStopsSchedulesArray = array();

			try {
				//a custom style is used when buses are passing now: remove those 
				$content = preg_replace("/<blink style='color:red'>([a-zA-Z0-9]+)<\/blink>/", "$1", $content);

				//match all the stops, with their line, direction, name, schedules, and possible traffic messages
				$regex_name = "timeo_ligne_nom'>([a-zA-Z0-9- ']+).+";
				$regex_direction = "timeo_titre_direction'>([a-zA-Z0-9-\.\- ']+).+";
				$regex_stop = "timeo_titre_arret'>Arr&ecirc;t&nbsp;([a-zA-Z0-9&;\.\/ '\-]+)";
				$regex_message = "(<div class='message ligne non_bloquant'>\n<p class='titre_message'>(.+)<\/p>\n<p class='corps_message'>([^<]*?(\n))+[^<]*?<\/p>\n<\/div>\n)?";
				$regex_schedules = "((\s<li id='h[0-9]' class='timeo_horaire'>[a-zA-Z0-9&;\. '\-]+<\/li>\n)*)";

				$regex = "/" . $regex_name . $regex_direction . $regex_stop . "<\/span><\/div>\n<div class='bloc_message'>\n" . $regex_message . "<\/div>\n<span class='timeo_titre_arret'>Prochains passages :<\/span><ul>\n" . $regex_schedules . "/";
				preg_match_all($regex, $content, $regexMatches, PREG_SET_ORDER);

				//if we could parse the page
				if($regexMatches != null) {
					for($j = 0; $j < count($regexMatches); $j++) {
						//for each bus stop, get and save its information
						$currentStopsSchedulesArray[$j]['line'] = ucsmart($regexMatches[$j][1]);
						$currentStopsSchedulesArray[$j]['direction'] = ucsmart($regexMatches[$j][2]);
						$currentStopsSchedulesArray[$j]['stop'] = ucsmart($regexMatches[$j][3]);

						//match the next buses schedules
						preg_match_all("/<li id='h[0-9]' class='timeo_horaire'>([a-zA-Z0-9&;\.\- ]+)<\/li>/", $regexMatches[$j][8], $regexMatches[$j][8]);
						
						//if there are any schedules, save them
						if($regexMatches[$j][8][1] != null) {
							//reformat the direction for the tramway; it's more convenient like this
							$currentStopsSchedulesArray[$j]['next'] = preg_replace("/([a-zA-Z0-9&;\. '\-]+) vers (A|B) [A-Z0-9&;\. '\-]+/", "Ligne $2 : $1", $regexMatches[$j][8][1]);
						} else {
							//if there are no schedules, just say it
							$currentStopsSchedulesArray[$j]['next'] = array("Pas de passage prévu");
						}

						//add traffic messages if necessary
						if(!empty($regexMatches[$j][5]) && !empty($regexMatches[$j][6])) {
							$currentStopsSchedulesArray[$j]['message']['title'] = $regexMatches[$j][5];
							$currentStopsSchedulesArray[$j]['message']['body'] = $regexMatches[$j][6];
						}
					}

					//merge the current cookie's results with the global results
					$finalSchedules = array_merge($finalSchedules, $currentStopsSchedulesArray);
				} else {
					throwError("Erreur lors de la récupération des horaires");
				}
			} catch(Exception $e) {
				throwError($e->getMessage());
			}
		}

		return $finalSchedules;
	}

	/** 
	 * An alias to getScheduleFromCookie that allows us to search for the next buses for a line, direction, stop instead of a raw cookie.
	 * Only works for one stop at a time.
	 *
	 * @param string $line The ID of the line
	 * @param string $direction The ID of the direction (A or R)
	 * @param string $stop The ID of the stop
	 * 
	 * @return array An array containing one schedule, that corresponds to the specified stop.
	 *
	 * @see getScheduleFromCookie
	 */
	function getScheduleFromDetails($line, $direction, $stop) {
		return getScheduleFromCookie($stop . '|' . $line . '|' . $direction);
	}

	/**
	 * Returns a list of all the available bus lines.
	 *
	 * @return array An array containing a list of all the bus lines available in the API.
	 */
	function getLines() {
		$content = postDistantPage(null);
		$lines;
		$final = array();

		try {
			//returns a piece of HTML: parse it to only get the interesting info
			preg_match_all("/<option value=(\"|')([a-zA-Z0-9]+)(\"|')>([a-zA-Z0-9\/\.\- ]+)<\/option>/", $content, $lines, PREG_SET_ORDER);

			if($lines != null) {
				for($i = 0; $i < count($lines); $i++) {
					$final[$i]['id'] = $lines[$i][2];
					$final[$i]['name'] = ucsmart($lines[$i][4]);
				}

				return $final;
			} else {
				throwError("Erreur lors de l'énumération des lignes");
			}
		} catch(Exception $e) {
			throwError($e->getMessage());
		}
	}

	/**
	 * Returns the available directions (A or R) for a specified line.
	 *
	 * @param string $line The ID of the line.
	 * @return array An array containing the available directions for this line. (usually A and R, may only be A)
	 */
	function getDirection($line) {
		$content = postDistantPage(array("a" => "refresh_list", "ligne" => $line));
		$directions;
		$final = array();

		try {
			//this returns a piece of JS code: we only want some of the info
			preg_match_all("/Array\('[A|R]','([a-zA-Z0-9\\\\\-'\. ]+)'\);/", $content, $directions);

			if($directions != null && $directions[1] != null) {
				if($directions[1][0] != null) {
					$final[0]['id'] = 'A';
					$final[0]['name'] = ucsmart(str_replace("\\", '', $directions[1][0]));
				}

				if($directions[1][1] != null) {
					$final[1]['id'] = 'R';
					$final[1]['name'] = ucsmart(str_replace("\\", '', $directions[1][1]));
				}
				
				return $final;
			} else {
				throwError("Erreur lors de l'énumération des directions");
			}
		} catch(Exception $e) {
			throwError($e->getMessage());
		}
	}

	/**
	 * Returns the available bus stops for a specified line and direction.
	 *
	 * @param string $line The ID of the line.
	 * @param string $direction The ID of the direction. (A or R)
	 *
	 * @return array An array containing all the stops corresponding to the given line and direction.
	 */
	function getStops($line, $direction) {
		$content = postDistantPage(array("a" => "refresh_list", "ligne_sens" => $line . '_' . $direction));
		$stops;
		$final = array();

		try {
			preg_match_all("/Array\('([\-_\|0-9]+)','([a-zA-Z0-9\\\\\-'\.\/ ]+)'\);/", $content, $stops, PREG_SET_ORDER);

			if($stops != null) {
				for($i = 0; $i < count($stops); $i++) {
					$expl = explode('_', $stops[$i][1]);
					$final[$i]['id'] = $expl[1];
					$final[$i]['name'] = ucsmart(str_replace("\\", '', $stops[$i][2]));
				}

				return $final;
			} else {
				throwError("Erreur lors de l'énumération des arrêts");
			}
		} catch(Exception $e) {
			throwError($e->getMessage());
		}
	}

	/**
	 * Capitalizes the first letter of every word, like ucwords; except it does it WELL.
	 * 
	 * @param string $text The text to capitalize.
	 * @return string The capitalized text.
	 */
	function ucsmart($text) {
		return preg_replace_callback('/([^a-z0-9]|^)([a-z0-9]*)/', function($matches) {
			
			//these words will never be capitalized
			$determinants = array('de', 'du', 'des', 'au', 'aux', 'à', 'la', 'le', 'les', 'd', 'et', 'l');
			//these words will always be capitalized
			$specialWords = array('sncf', 'chu', 'chr', 'crous', 'suaps', 'fpa', 'za', 'zi', 'zac', 'cpam', 'efs', 'mjc');

			if($matches[1] != '' && in_array($matches[2], $determinants)) {
				//if the word is a determinant and is not in the first word of the string, don't capitalize it
				return $matches[1] . $matches[2];
			} else if(in_array($matches[2], $specialWords)) {
				//if the word is an acronym, fully capitalize it
				return $matches[1] . strtoupper($matches[2]);
			} else {
				//else, only capitalize the first letter of the word
				return $matches[1] . ucfirst($matches[2]);
			}
		}, strtolower($text));
	}

	/**
	 * Throws an error and kills the script.
	 * 
	 * @param string $reason The reason of the error, in the form of a brief message.
	 * @param string $message A detailed message about the error (optional).
	 *
	 * @see throwErrorWithHttpCode
	 */
	function throwError($reason, $message) {
		throwErrorWithHttpCode($reason, $message, "500 Internal Server Error", 500);
	}

	/**
	 * Throws an error and kills the script, specifying the HTTP error code.
	 * 
	 * @param string $reason The reason of the error, in the form of a brief message.
	 * @param string $message A detailed message about the error (optional).
	 * @param string $httpCodeMessage The HTTP error message.
	 * @param int $httpCode The HTTP error code.
	 *
	 * @see throwError
	 */
	function throwErrorWithHttpCode($reason, $message, $httpCodeMessage, $httpCode) {
		//empty output buffer
		ob_end_clean();
		header('HTTP/1.1 ' . $httpCodeMessage, true, $httpCode);

		//exiting with an error displayed in a JSON object
		if($message == null) {
			exit('{"error":' . json_encode($reason) . '}');
		} else {
			exit('{"error":' . json_encode($reason) . ', "message":' . json_encode($message) . '}');
		}
	}

	/**
	 * Gets a GET or POST variable.
	 * 
	 * @param string $var The name of the parameter.
	 * @return mixed If $_GET[$var] was set : returns the GET variable with this name, else, returns the POST variable.
	 */
	function getVar($var) {
		return isset($_GET[$var]) ? $_GET[$var] : $_POST[$var];
	}

	/**
	 * Saves a JSON response to a file.
	 * 
	 * @param string $category The name of the cache file.
	 */
	function saveCache($category, $content) {
		@file_put_contents(CACHE_DIRECTORY . $category, $content);
	}

	/**
	 * Returns the content of a cache file if available and relevant.
	 * 
	 * @param string $category The name of the cache file.
	 * @param string &$content The variable in which the cache will be saved if available.
	 * @return boolean True if and only if the cache is retrievable.
	 */
	function getCache($category, &$content) {
		$category = str_replace("/", "", $category);

		if(!file_exists(CACHE_DIRECTORY . $category) 
			|| (filemtime(CACHE_DIRECTORY . $category) + CACHE_DURATION) < date_timestamp_get(date_create())) {
			return false;
		} else {
			$content = stripslashes(file_get_contents(CACHE_DIRECTORY . $category));
			return true;
		}
	}

	/**
	 * Main function: processes the data requested.
	 */
	function processAndDisplayURLRequest() {
		$func = getVar('func');
		$line = getVar('line');
		$direction = getVar('direction');
		$stop = getVar('stop');
		$data = getVar('data');

		$res = "";
		$cache = "";

		//check for cache
		if($func == 'getLines' && getCache('lines', $cache)
			|| $func == 'getDirections' && getCache('directions_' . $line, $cache)
			|| $func == 'getStops' && getCache('stops_' . $line . '_' . $direction, $cache)) {
			//if there's cache available, display it
			echo $cache;
		} else {
			//if no cache is available
			//check what we want to get and process
			switch ($func) {
				case 'getSchedule':
					if($data != NULL) {
						//if we already have a nice, tasty cookie
						$res = getScheduleFromCookie($data);
					} else if($line != NULL && $direction != NULL && $stop != NULL) {
						//else, if we're specifying line, direction and stop of the desired schedule
						$res = getScheduleFromDetails($line, $direction, $stop);
					}

					break;
				case 'getLines':
					//if we want to list the available lines
					$res = getLines();

					saveCache('lines', html_entity_decode(json_encode($res)));
					break;
				case 'getDirections':
					//if we want to list the available directions
					$res = getDirection($line);

					saveCache('directions_' . $line, html_entity_decode(json_encode($res)));
					break;
				case 'getStops':
					//if we want to list the available bus stops
					$res = getStops($line, $direction);

					saveCache('stops_' . $line . '_' . $direction, html_entity_decode(json_encode($res)));
					break;
				default:
					throwErrorWithHttpCode("Pas assez d'arguments, RTFM.", NULL, "400 Bad Request", 400);
					break;
			}

			if(!empty($res)) {
				//display the result of the request
				echo html_entity_decode(json_encode($res));
			}
		}
		
	}

	//start output buffer, process, and flush
	ob_start();
	error_reporting(0);
	header("Content-Encoding: UTF-8");
	processAndDisplayURLRequest();
	ob_end_flush();

?>
