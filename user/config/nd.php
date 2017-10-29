<?php if (!defined('FROM_BASE')) { header($_SERVER['SERVER_PROTOCOL'] . ' 403'); die('Invalid requested path.'); }

$nd['enabled'] = false;

$nd['backend']['base_url'] = 'http://localhost/nd-php/index.php';
$nd['backend']['session_lifetime'] = 7200;

$nd['auth']['public']['user_id'] = 0;
$nd['auth']['public']['api_key'] = '';

$nd['header']['user_id'] = 'nd-user-id';
$nd['header']['auth_token'] = 'nd-auth-token';

$nd['user_agent']['replace'] = false;
$nd['user_agent']['name'] = 'uWeb RESTful API Interface';
$nd['user_agent']['version'] = 'v1';

$nd['trusted_sources'] = [ '127.0.0.1' ];

$nd['models']['base_path'] = '/application/models/nd';
$nd['models']['validate']['types'] = true;
$nd['models']['validate']['input_types'] = true;
$nd['models']['validate']['output_types'] = true;

$nd['cache']['context']['generic'] = 'default';
$nd['cache']['context']['auth'] = 'default';
$nd['cache']['lifetime']['generic'] = 600;
$nd['cache']['lifetime']['auth'] = 7200;
