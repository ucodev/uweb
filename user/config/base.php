<?php if (!defined('FROM_BASE')) { header($_SERVER['SERVER_PROTOCOL'] . ' 403'); die('Invalid requested path.'); }

/* Base settings */
$base['url'] = NULL; /* If not NULL, this value will override the default base_url() output */
$base['path'] = '/v1'; /* NULL for autodetect, or set base URI string if autodetection isn't working properly */
$base['fallback_resource'] = false; /* Set to 'index.php' if webserver fallback resource directive is enabled. Otherwise, set to false. */
$base['fallback_enforce'] = false; /* If set to true, requests with fallback resource present in the URI will be rejected */
$base['controller'] = 'test'; /* Default controller */
$base['acceptable_uri_regex'] = '/^[a-zA-Z0-9\ \~\%\.\:\_\\-\+\=\/]+$/'; /* Acceptable URI charaters */
