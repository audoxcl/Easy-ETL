<?php

function writeLog($content){
	file_put_contents("log.txt", "\n".date("Y-m-d H:i:s ").getmypid().": ".print_r($content, true), FILE_APPEND);
}

function auth($headers){
	list($type, $authorization) = explode(" ", $headers['Authorization']);
	
	$valid_tokens = array(
		'FREETOKEN',
		'TOKEN1',
		'TOKEN2',
	);
	
	if(in_array($authorization, $valid_tokens)) return true;
	
	return false;
}

?>