<?php if (!defined('FROM_BASE')) { header($_SERVER['SERVER_PROTOCOL'] . ' 403'); die('Invalid requested path.'); }

/* RESTful interface settings */
$restful['enabled'] = false;

$restful['debug']['enabled'] = false;
$restful['debug']['level'] = 1;

$restful['request']['encoding']['process'] = true;
$restful['response']['default']['status_code'] = '400';

$restful['log']['enabled'] = false;
$restful['log']['request_body'] = false;
$restful['log']['response_body'] = false;
$restful['log']['encode_body'] = true;
$restful['log']['truncate_values'] = 128; /* Set to 0 to disable. If positive, must be greater than 16 bytes */
$restful['log']['discard_huge_body'] = 15360; /* Maximum number of bytes accepted before ignoring body contents (only valid if encode_body is set to true) */
$restful['log']['secure_fields'] = array('password', 'email');
$restful['log']['interface'] = 'http_json';
$restful['log']['destination']['url'] = 'http://localhost:8080'; /* Used by http_json interface */
$restful['log']['destination']['host'] = 'localhost'; /* Used by udp_json interface */
$restful['log']['destination']['port'] = 12000; /* Used by udp_json and syslog interface */
$restful['log']['destination']['timeout']['connect'] = 2500; /* in ms (http_json) */
$restful['log']['destination']['timeout']['execute'] = 4000; /* in ms (http_json) */
$restful['log']['source']['name'] = 'uweb_api';
$restful['log']['source']['version'] = 'v1';
$restful['log']['source']['environment'] = 'development';
$restful['log']['source']['company'] = 'uCodev';
$restful['log']['header']['user_id'] = 'uweb-user-id';
$restful['log']['header']['auth_token'] = 'uweb-auth-token';
$restful['log']['header']['tracker'] = 'uweb-tracker-id';
$restful['log']['header']['geolocation'] = 'geolocation';
$restful['log']['header']['timestamp'] = 'origin-timestamp';
$restful['log']['default']['user_id'] = 0;

$restful['event']['enabled'] = false;
$restful['event']['context'] = 'default';
