<?php
$base = 'bell2.0';
$protocol = (
	(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
	$_SERVER['SERVER_PORT'] == 443 ||
	(!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
    ) ? "https" : "http";

//$API_BASED = $protocol . "://" . $_SERVER['HTTP_HOST'] . "/" . $base . "/api/";
$API_BASED = $protocol . "://" . $_SERVER['HTTP_HOST'] . "/api/";