<?php
error_log('ob_get_level at start: ' . ob_get_level());
header('Content-Type: application/json');
echo json_encode(['ping' => 'pong']);
error_log('ob_get_level at end: ' . ob_get_level());
error_log('output sent so far: ' . var_export(ob_get_contents(), true));