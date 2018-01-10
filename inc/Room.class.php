<?php
// this may not be run directly
if(!defined('_APP')) die('Cannot be executed directly!');

// includes needed
global $config;
if(!class_exists('Module')) {
	require $config->app_base_dir . '/inc/Module.class.php';
}
if(!class_exists('Common')) {
	require $config->app_base_dir . '/inc/Common.class.php';
}

// class definition
class Room extends Module {
	// private properties
	protected $guid;
	protected $room_uri;
	protected $room_name;
	protected $max_users;
	protected $owner_guid;

	// constructor method
	function __construct($action, $secondary, $parameters) {
		$this->action = $action;
		$this->secondary = $secondary;
		$this->parameters = $parameters;
	}

	// required function definition for modules
	function process_action() {
		// tell the logger what we're doing
		global $logger;
		$logger->emit($logger::LOGGER_INFO, __CLASS__, __FUNCTION__, "Processing action '{$this->action}'");

		// check if there even is a secondary term
		if(!isset($this->secondary) || strlen($this->secondary) == 0) {
			$logger->emit($logger::LOGGER_WARN, __CLASS__, __FUNCTION__, "No secondary term supplied");

			// if it's an XHR, handle this via JSON response
			if(!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
				$response = array(
					'response' => 'error',
					'message' => 'No secondary term supplied'
				);
				echo json_encode($response);
				return false;
			}

			// otherwise, just send the user to a 404 page
			else {
				global $config;
				include $config->get_theme_location() . '/page-404.php';
				return false;
			}
		}

		// switch on the action term
		switch($this->action) {
			case 'room-admin':
				// is the user an admin?
				if(!isset($_SESSION['user_object']) || !$_SESSION['user_object']->is_admin()) {
					$logger->emit($logger::LOGGER_WARN, __CLASS__, __FUNCTION__, "Access to an administrative level function by non-admin not allowed");
					$response = array(
						'response' => 'error',
						'message' => 'Non-admin access not allowed'
					);
					break;
				}

				// were parameters passed? (all secondaries here need them)
				if(!isset($this->parameters) || !is_array($this->parameters) || count($this->parameters) == 0) {
					$logger->emit($logger::LOGGER_WARN, __CLASS__, __FUNCTION__, "Parameters not passed when trying to {$this->secondary} a room");
					$response = array(
						'response' => 'error',
						'message' => 'Parameters not passed'
					);
					break;
				}

				// this will tell us what is going on
				$logger->emit($logger::LOGGER_INFO, __CLASS__, __FUNCTION__, "Handling '{$this->secondary}' call");

				// is the caller requesting information on a room?
				if($this->secondary == 'get-info') {
					// we need a GUID
					if(!isset($this->parameters['guid'])) {
						$logger->emit($logger::LOGGER_WARN, __CLASS__, __FUNCTION__, "GUID not passed while attempting to get info");
						$response = array(
							'response' => 'error',
							'message' => 'GUID not passed'
						);
						break;
					}
					else {
						// populate the room GUID
						$this->guid = $this->parameters['guid'];

						// fill the rest of the room properties
						$response = $this->get_room_info();
						if(!isset($response) || !is_array($response)) {
							$logger->emit($logger::LOGGER_WARN, __CLASS__, __FUNCTION__, "Function call failure");
							$response = array(
								'response' => 'error',
								'message' => 'Function call failure'
							);
							break;
						}
						else {
							$logger->emit($logger::LOGGER_INFO, __CLASS__, __FUNCTION__, "Room info sent for room with GUID '{$this->guid}'");
							$response['response'] = 'ok';
							$response['message'] = 'Room details successfully retrieved';
							break;
						}
					}
					break;
				}

				// is this a call to create a room?
				if($this->secondary == 'create') {
					// check for missing parameters
					if(!isset($this->parameters['room_name']) || !isset($this->parameters['room_uri']) || !isset($this->parameters['owner_guid'])) {
						$logger->emit($logger::LOGGER_WARN, __CLASS__, __FUNCTION__, "Parameters missing - creation process aborted");
						$response = array(
							'response' => 'error',
							'message' => 'One or more parameters missing'
						);
						break;
					}

					// max_users is a special case - should be between 2 and room_global_max_users, and if it isn't (or it doesn't exist), fudge it
					global $config;
					$room_global_max_users = $config->get_value('room_global_max_users');
					if(!isset($this->parameters['max_users'])) {
						$this->parameters['max_users'] = $room_global_max_users;
					}
					else if($this->parameters['max_users'] < 2) {
						$this->parameters['max_users'] = 2;
					}
					else if($this->parameters['max_users'] > $room_global_max_users) {
						$this->parameters['max_users'] = $room_global_max_users;
					}

					// check that the function call goes through
					if(!$this->create_room($this->parameters['room_name'], $this->parameters['room_uri'], $this->parameters['max_users'], $this->parameters['owner_guid'])) {
						$logger->emit($logger::LOGGER_WARN, __CLASS__, __FUNCTION__, "Function call failed");
						$response = array(
							'response' => 'error',
							'message' => 'Function call failed'
						);
						break;
					}

					// getting here means success
					$logger->emit($logger::LOGGER_INFO, __CLASS__, __FUNCTION__, "Function call successful");
					$response = array(
						'response' => 'ok',
						'message' => 'Function call successful'
					);
					break;
				}

				// is this a call to delete a room?
				else if($this->secondary == 'delete') {
					// check for missing parameters
					$missing = false;
					if(!isset($this->parameters['guid'])) {
						$logger->emit($logger::LOGGER_WARN, __CLASS__, __FUNCTION__, "GUID not passed - deletion process aborted");
						$response = array(
							'response' => 'error',
							'message' => 'GUID not passed'
						);
						break;
					}

					// populate the room GUID
					$this->guid = $this->parameters['guid'];

					// check that the function call goes through
					if(!$this->delete_room($this->parameters['guid'])) {
						$logger->emit($logger::LOGGER_WARN, __CLASS__, __FUNCTION__, "Function call failed");
						$response = array(
							'response' => 'error',
							'message' => 'Function call failed'
						);
						break;
					}

					// getting here means success
					$logger->emit($logger::LOGGER_INFO, __CLASS__, __FUNCTION__, "Function call successful");
					$response = array(
						'response' => 'ok',
						'message' => 'Function call successful'
					);
					break;
				}

				// or is this a call to modify a room?
				else if($this->secondary == 'modify') {
					// we need a GUID, or else things are bad
					if(!isset($this->parameters['guid'])) {
						$logger->emit($logger::LOGGER_WARN, __CLASS__, __FUNCTION__, "GUID not passed - modification operation aborted");
						$response = array(
							'response' => 'error',
							'message' => 'GUID not passed'
						);
						break;
					}

					// populate the room GUID otherwise
					else {
						$this->guid = $this->parameters['guid'];
					}

					// for each parameter we receive, send it individually to the method for processing
					foreach(array('room_name', 'uri', 'max_users', 'owner_guid') as $property) {
						if(isset($this->parameters[$property])) {
							if(!$this->modify_room($property, $this->parameters[$property])) {
								$logger->emit($logger::LOGGER_WARN, __CLASS__, __FUNCTION__, "Failed to modify '${property}'");
								$response = array(
									'response' => 'error',
									'message' => "Function call failed on ${property}"
								);
								break;
							}
						}
					}

					// if there is no response yet, it's successful
					if(!isset($response) || !is_array($response)) {
						$logger->emit($logger::LOGGER_INFO, __CLASS__, __FUNCTION__, "Function call successful");
						$response = array(
							'response' => 'ok',
							'message' => 'Function call successful'
						);
						break;
					}
				}

				// otherwise, it's not valid
				$logger->emit($logger::LOGGER_WARN, __CLASS__, __FUNCTION__, "Invalid secondary term specified");
				$response = array(
					'response' => 'error',
					'message' => 'Invalid term'
				);
				break;

			case 'room':
				// let's see if it exists first
				$query = "SELECT guid, room_uri, room_name, owner_guid FROM rooms WHERE room_uri = '{$this->secondary}' LIMIT 1";
				global $db;
				$result = $db->query($query);
				if(!isset($result) || !is_array($result)) {
					$logger->emit($logger::LOGGER_WARN, __CLASS__, __FUNCTION__, "Database query failure");
					return false;
				}
				else if(count($result) == 0) {
					$logger->emit($logger::LOGGER_WARN, __CLASS__, __FUNCTION__, "No room found with URI '{$this->secondary}'");
					return false;
				}

				// great, it exists
				else {
					// set the instance variables from the query results
					$this->guid = $result[0]['guid'];
					$this->room_uri = $result[0]['room_uri'];
					$this->room_name = $result[0]['room_name'];
					$this->owner_guid = $result[0]['owner_guid'];

					// load the page
					global $config;
					include $config->get_theme_location() . '/page-room.php';
				$logger->emit($logger::LOGGER_INFO, __CLASS__, __FUNCTION__, "Room loaded with GUID '{$this->guid}'");
				}
				break;

			default:
				// a whole lot of nothing
				return false;
				break;
			}

			// return the response, if any
			if(isset($response) && is_array($response)) {
				echo json_encode($response);
			}
		}

	// additional functions
	function create_room($room_name, $room_uri, $max_users, $owner_guid) {
		// log the function call
		global $logger;
		$logger->emit($logger::LOGGER_INFO, __CLASS__, __FUNCTION__, "Function called");

		// get a GUID and build the query
		$room_guid = Common::get_guid();
		$query = "INSERT INTO rooms (room_name, owner_guid, max_users, room_uri, guid) VALUES ('${room_name}', '${owner_guid}', '${max_users}', '${room_uri}', '${room_guid}')";
		global $db;
		if(!$result = $db->query($query)) {
			$logger->emit($logger::LOGGER_WARN, __CLASS__, __FUNCTION__, "Database query failure");
			return false;
		}
		else {
			// populate object variables
			$this->guid = $room_guid;
			$this->room_uri = $room_uri;
			$this->room_name = $room_name;
			$this->max_users = $max_users;
			$this->owner_guid = $owner_guid;

			// log this event
			$logger->emit($logger::LOGGER_INFO, __CLASS__, __FUNCTION__, "Room '{$this->room_name}' created successfully (GUID: '{$this->guid}')");
			return true;
		}
	}

	function delete_room() {
		// log the function call
		global $logger;
		$logger->emit($logger::LOGGER_INFO, __CLASS__, __FUNCTION__, "Function called");

		// build the database query and execute it
		$query = "DELETE FROM rooms WHERE guid = '{$this->guid}'";
		global $db;
		if(!$db->query($query)) {
			$logger->emit($logger::LOGGER_WARN, __CLASS__, __FUNCTION__, "Database query failure");
			return false;
		}
		else {
			// log the event and finish
			$logger->emit($logger::LOGGER_INFO, __CLASS__, __FUNCTION__, "Room with GUID '{$this->guid}' deleted");
			return true;
		}
	}

	function modify_room($property, $new_value) {
		// log the function call
		global $logger;
		$logger->emit($logger::LOGGER_INFO, __CLASS__, __FUNCTION__, "Function called");

		// is this even a valid property?
		if(!in_array($property, array('room_name', 'owner_guid', 'max_users', 'room_uri'))) {
			$logger->emit($logger::LOGGER_WARN, __CLASS__, __FUNCTION__, "Requested property invalid");
			return false;
		}

		// build the database query
		$query = "UPDATE rooms SET ${property} = '${new_value}' WHERE guid = '{$this->guid}'";

		// execute it
		global $db;
		if(!$db->query($query)) {
			$logger->emit($logger::LOGGER_WARN, __CLASS__, __FUNCTION__, "Database query failure");
			return false;
		}
		else {
			// update the object instance as well
			$this->$property = $new_value;

			// log the event and finish
			$logger->emit($logger::LOGGER_INFO, __CLASS__, __FUNCTION__, "Room with GUID '{$this->guid}' modified");
			return true;
		}
	}

	function get_room_info() {
		// log the function call
		global $logger;
		$logger->emit($logger::LOGGER_INFO, __CLASS__, __FUNCTION__, "Function called");

		// query the database to get the other details necessary
		$query = "SELECT room_name, owner_guid, max_users, room_uri FROM rooms WHERE guid = '{$this->guid}' LIMIT 1";
		global $db;
		$result = $db->query($query);

		// hopefully, something came back, but check for that
		if(!isset($result) || !is_array($result)) {
			$logger->emit($logger::LOGGER_WARN, __CLASS__, __FUNCTION__, "Database query failure");
			return false;
		}
		else {
			$logger->emit($logger::LOGGER_INFO, __CLASS__, __FUNCTION__, "Database query success - returning results");
			return $result[0];
		}
	}

	function register_user() {
		// log the function call
		global $logger;
		$logger->emit($logger::LOGGER_INFO, __CLASS__, __FUNCTION__, "Function called");

		// user GUID will be needed in a more accessible form in this function
		$user_guid = $_SESSION['user_object']->get_guid();

		// database connection will be needed as well
		global $db;

		// check if the user is already in the room
		if($this->is_registered()) {
			// the user is probably logged in elsewhere (i.e. testing/devel), or it's an old session that will be expired at some point
			$logger->emit($logger::LOGGER_INFO, __CLASS__, __FUNCTION__, "User already found in the room with GUID '{$this->guid}' - updating last_seen");
			$current_timestamp = date('Y-m-d H:i:s');
			$query = "UPDATE users_in_rooms SET last_seen = '${current_timestamp}' WHERE user_guid = '${user_guid}' AND room_guid = '{$this->guid}'";
			if(!$db->query($query)) {
				$logger->emit($logger::LOGGER_INFO, __CLASS__, __FUNCTION__, "Database query failure");
				return false;
			}
			else {
				return true;
			}
		}

		// associate the user with the channel via DB query
		$current_timestamp = date('Y-m-d H:i:s');
		$query = "INSERT INTO users_in_rooms (room_guid, user_guid, last_seen) VALUES ('{$this->guid}', '${user_guid}', '${current_timestamp}')";
		if(!$db->query($query)) {
			$logger->emit($logger::LOGGER_WARN, __CLASS__, __FUNCTION__, "Database query failure");
			return false;
		}
		else {
			$logger->emit($logger::LOGGER_INFO, __CLASS__, __FUNCTION__, "User successfully registered to the room with GUID '{$this->guid}'");
			return true;
		}
	}

	function unregister_user() {
		// log the function call
		global $logger;
		$logger->emit($logger::LOGGER_INFO, __CLASS__, __FUNCTION__, "Function called");

		// associate the user with the channel via DB query
		$user_guid = $_SESSION['user_object']->get_guid();
		$query = "DELETE FROM users_in_rooms WHERE user_guid = '${user_guid}' AND room_guid = '{$this->guid}'";
		global $db;
		if(!$db->query($query)) {
			$logger->emit($logger::LOGGER_WARN, __CLASS__, __FUNCTION__, "Database query failure");
			return false;
		}
		else {
			$logger->emit($logger::LOGGER_INFO, __CLASS__, __FUNCTION__, "User successfully unregistered from the room with GUID '{$this->guid}'");
			return true;
		}
	}

	function is_registered() {
		// log the function call
		global $logger;
		$logger->emit($logger::LOGGER_INFO, __CLASS__, __FUNCTION__, "Function called");

		// associate the user with the channel via DB query
		$user_guid = $_SESSION['user_object']->get_guid();
		$query = "SELECT user_guid FROM users_in_rooms WHERE room_guid = '{$this->guid}' AND user_guid = '${user_guid}'";
		global $db;
		$result = $db->query($query);
		if(!isset($result) || !is_array($result)) {
			$logger->emit($logger::LOGGER_WARN, __CLASS__, __FUNCTION__, "Database query failure");
			return false;
		}
		else if(count($result) === 0) {
			$logger->emit($logger::LOGGER_INFO, __CLASS__, __FUNCTION__, "No user record found in the room with GUID '{$this->guid}'");
			return false;
		}
		else {
			$logger->emit($logger::LOGGER_INFO, __CLASS__, __FUNCTION__, "User successfully found in the room with GUID '{$this->guid}'");
			return true;
		}
	}

	// this will most likely only ever be called procedurally
	static function get_room_list() {
		// log the function call
		global $logger;
		$logger->emit($logger::LOGGER_INFO, __CLASS__, __FUNCTION__, "Function called");

		// query the database
		$query = "SELECT guid, room_uri, room_name, max_users, owner_guid FROM rooms ORDER BY room_name ASC";
		global $db;
		$result = $db->query($query);

		// only fail if the database had an error
		if(!isset($result) || !is_array($result)) {
			$logger->emit($logger::LOGGER_WARN, __CLASS__, __FUNCTION__, "Database query failure");
			return false;
		}
		else {
			$logger->emit($logger::LOGGER_INFO, __CLASS__, __FUNCTION__, "Room list returned");
			return $result;
		}
	}

	static function get_global_max_users() {
		// log the function call
		global $logger;
		$logger->emit($logger::LOGGER_INFO, __CLASS__, __FUNCTION__, "Function called");

		// query the database to get the other details necessary
		$query = "SELECT option_value FROM config WHERE option_name = 'room_global_max_users' LIMIT 1";
		global $db;
		$result = $db->query($query);

		// hopefully, something came back, but check for that
		if(!isset($result) || !is_array($result)) {
			$logger->emit($logger::LOGGER_WARN, __CLASS__, __FUNCTION__, "Database query failure");
			return false;
		}
		else {
			$logger->emit($logger::LOGGER_INFO, __CLASS__, __FUNCTION__, "Database query success - returning results");
			return $result[0]['option_value'];
		}
	}

	static function get_max_users($guid) {
		// log the function call
		global $logger;
		$logger->emit($logger::LOGGER_INFO, __CLASS__, __FUNCTION__, "Function called");

		// query the database to get the other details necessary
		$query = "SELECT max_users FROM rooms WHERE guid = '${guid}' LIMIT 1";
		global $db;
		$result = $db->query($query);

		// hopefully, something came back, but check for that
		if(!isset($result) || !is_array($result)) {
			$logger->emit($logger::LOGGER_WARN, __CLASS__, __FUNCTION__, "Database query failure");
			return false;
		}
		else {
			$logger->emit($logger::LOGGER_INFO, __CLASS__, __FUNCTION__, "Database query success - returning results");
			return $result[0]['max_users'];
		}
	}

	static function get_users($guid) {
		// log the function call
		global $logger;
		$logger->emit($logger::LOGGER_INFO, __CLASS__, __FUNCTION__, "Function called");

		// query the database to get the other details necessary
		$query = "SELECT user_guid FROM users_in_rooms WHERE room_guid = '${guid}'";
		global $db;
		$result = $db->query($query);

		// hopefully, something came back, but check for that
		if(!isset($result)) {
			$logger->emit($logger::LOGGER_WARN, __CLASS__, __FUNCTION__, "Database query failure");
			return false;
		}
		else if(!is_array($result)) {
			$logger->emit($logger::LOGGER_INFO, __CLASS__, __FUNCTION__, "Database query returned zero results");
			return 0;
		}
		else {
			$logger->emit($logger::LOGGER_INFO, __CLASS__, __FUNCTION__, "Database query success - returning results");
			return count($result);
		}
	}
}
