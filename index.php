<?php

include 'functions.php';
include 'config.php';

global $config;

function HubSpotConnectorCurl($params){
	$url = $config['HubSpotConnector']['url'];
	$url .= '?'.http_build_query($params);
    $headers = [
        'Authorization:Bearer '.$params['token'],
	];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $output = curl_exec($ch);
    curl_close($ch);
	return($output);
}

if(!isset($argv) && function_exists('getallheaders')){
	$headers = getallheaders();
	if(function_exists('auth') && auth($headers) == false) die(json_encode(array("error_code" => "401", "error_description" => "Unauthorized")));
}

if(isset($argv)){
	writeLog("argv: ".print_r($argv, true));
	$_REQUEST = ['hapikey' => $config['hapikey']];
	foreach(["action", "object", "properties", "associations"] as $arg_key => $arg_value)
		$_REQUEST[$arg_value] = $argv[$arg_key+1];
}
writeLog("_REQUEST: ".print_r($_REQUEST, true));

if($_REQUEST["action"] == "getHubSpotRecords"){
	writeLog("Starting...");
	$params = [
		'token' => "FREETOKEN",
		'action' => "getRecords",
		'object' => "properties/".$_REQUEST["object"],
		'hapikey' => $_REQUEST['hapikey'],
		'limit' => 100,
	];
	$properties = [];
	foreach(json_decode(HubSpotConnectorCurl($params)) as $property){
		// if( ($property->hidden != true) && ($property->hubspotDefined != true) ) $properties[] = $property->name;
		$properties[] = $property->name;
	}
	$properties_total = count($properties);
	writeLog("properties_total: $properties_total");
	// $properties_group = 100;
	$properties_group = 50;

	$params['object'] = $_REQUEST["object"];

	try {
		$pdo = new PDO('mysql:host=localhost;dbname=connectors', $config['db']['user'], $config['db']['password'], [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
		]);
	} catch (PDOException $e) {
		die("Connection failed: " . $e->getMessage());
	}

	$query_where_default = [
		'service' => $_REQUEST["service"],
		'software' => 'HubSpot',
	];
	$query_where = [];
	foreach($query_where_default as $column => $value) $query_where[] = "$column = " . "'" . addslashes($value) . "'";
	$query_where = implode(' AND ', $query_where);

	$query_delete = "DELETE FROM connectors WHERE $query_where AND object = '".$params['object']."'";
	writeLog("Deleting records...");
	$pdo->exec($query_delete);
	writeLog("Records deleted");
	
	$flag = 1;
	if(true){
	for($i=0; $i<ceil($properties_total/$properties_group); $i++){
		// continue;
		writeLog($i);
		writeLog("Memory: ".number_format(memory_get_usage()));
		$params['properties'] = implode(",", array_slice($properties, $i*$properties_group, $properties_group));
		if($i == 0 && !empty($_REQUEST["associations"])) $params['associations'] = $_REQUEST["associations"];
		// $params['properties'] = implode(",", array_slice($properties, $i*$properties_group, 5));
		writeLog("properties: ".count(explode(',', $params['properties'])));
		// writeLog($params['properties']);
		writeLog("Getting records from HubSpot...");
		$records = json_decode(HubSpotConnectorCurl($params), true);
		// $records = array_slice($records, 0, 10); // for debugging
		writeLog("records: ".count($records));
		if(!empty($records)){
			writeLog("Updating records in database...");
			foreach($records as $record){

				$json_update = json_encode([
					'properties' => $record['properties'] ?? "",
					'associations' => $record['associations'] ?? "",
				]);
				
				// I should also update updatedAt and archived but there is an error with record
				$query = "
	INSERT INTO connectors (id, service, software, object, object_id, data, status)
	VALUES (
		UUID(),
		'".$query_where_default['service']."',
		'".$query_where_default['software']."',
		'".$params['object']."',
		'".$record['id']."',
		'".addslashes(json_encode($record))."',
		NULL
		)
	ON DUPLICATE KEY UPDATE
	data = JSON_MERGE_PATCH(data, '".addslashes($json_update)."'),
	status = NULL
	";
				// writeLog("id: ".$record['id']." updatedAt: ".$record['updatedAt']." archived: ".$record['archived']);
				$pdo->exec($query);

			}
		}
		unset($records);
	}
	$stmt = null;
	}

	writeLog("Records ready!");

	writeLog("Printing response...");
	writeLog("Memory: ".number_format(memory_get_usage()));

	$query_from = "FROM connectors where $query_where AND object = '".$params['object']."' AND (status IS NULL OR status in ('Todo', 'In Progress'))";
	// Prepare statements outside the loop for speed (reusable)
	$stmt_update = $pdo->prepare("UPDATE connectors SET status = :status WHERE $query_where AND object = :object AND object_id = :object_id");
	$query_select = "SELECT object_id, data $query_from LIMIT 1000";

	$properties_default = ['id', 'createdAt', 'updatedAt', 'archived'];
	$assoc_list = !empty($params['associations']) ? explode(',', $params['associations']) : [];

	$fp_records = fopen($_REQUEST["object"].".csv", 'w');
	$fp_associations = fopen($_REQUEST["object"]."_associations.csv", 'w');

	$first_row = true;

	$i = 0;
	$j = 0;
	// Continuous processing loop
	while (true) {
		// Fetch a batch of 1000
		$i++;
		$rows = $pdo->query($query_select)->fetchAll(PDO::FETCH_ASSOC);
		writeLog("i: $i / rows: ".count($rows));
		writeLog("Memory: ".number_format(memory_get_usage()));
		if (empty($rows)) break;

		foreach ($rows as $row) {
			$j++;
			// writeLog("j: $j / object_id : ".$row['object_id']);
			// Mark as In Progress immediately to avoid re-fetching the same record
			$stmt_update->execute([
				'status' => 'In Progress',
				'object' => $params['object'],
				'object_id' => $row['object_id']
				]);

			// Decode JSON once (using associative arrays is slightly faster/lighter than objects)
			$record = json_decode(utf8_decode($row['data']), true, 512, JSON_INVALID_UTF8_IGNORE);
			if (!$record) continue;

			// Write Headers only once
			if ($first_row) {
				fputcsv($fp_records, array_merge($properties_default, $properties));
				fputcsv($fp_associations, ['id', 'association', 'association_id', 'association_type']);
				$first_row = false;
			}

			// Build CSV row efficiently
			$row2 = [];
			foreach ($properties_default as $p) $row2[] = $record[$p] ?? '';
			foreach ($properties as $p) {
				$val = $record['properties'][$p] ?? '';
				$row2[] = str_replace(";", "", $val);
			}
			fputcsv($fp_records, $row2);

			// Process Associations
			foreach ($assoc_list as $assoc) {
				$results = $record['associations'][$assoc]['results'] ?? [];
				foreach ($results as $res) {
					fputcsv($fp_associations, [$record['id'], $assoc, $res['id'], $res['type']]);
				}
			}

			// Finalize status
			$stmt_update->execute([
				'status' => 'Done',
				'object' => $params['object'],
				'object_id' => $row['object_id'],
				]);
		}
		
		// Clear batch from memory
		unset($rows); 
	}

	fclose($fp_records);
	fclose($fp_associations);

	// $pdo->exec($query_delete);

	$pdo = null;

	writeLog("Memory: ".number_format(memory_get_usage()));
	writeLog("Ready!");
}

?>