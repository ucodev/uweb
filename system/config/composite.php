<?php if (!defined('FROM_BASE')) { header($_SERVER['SERVER_PROTOCOL'] . ' 403'); die('Invalid requested path.'); }

$composite['limit'] = 10;
$composite['forward_header'] = 'x-composite';
$composite['allow_recursive'] = false;
$composite['allow_explicit_unsafe'] = true;
$composite['allow_unsafe_without_vars'] = false;
$composite['enforce_strict_vars'] = true;
$composite['enabled_methods'] = array('GET', 'POST', 'PATCH', 'PUT', 'DELETE', 'OPTIONS');
$composite['no_payload_methods'] = array('GET', 'OPTIONS');
$composite['max_child_connection_time'] = 5000;
$composite['max_child_execution_time'] = 30000;
$composite['lowest_error_code'] = 400;
$composite['filtered_parent_headers'] = array('accept', 'content-type', 'user-agent', 'accept-encoding', 'connection', 'content-encoding', 'cache-control');
$composite['include_child_headers'] = array('accept: application/json', 'content-type: application/json');
$composite['max_recursive_var_path'] = 16;
$composite['max_options_var_path'] = 8;
$composite['max_var_array_length'] = 64;
$composite['max_var_length'] = 256;
$composite['child_accept_encoding'] = 'gzip';
$composite['child_content_encoding'] = 'gzip';
$composite['child_basic_auth'] = NULL;
$composite['strict_header_types'] = false;
