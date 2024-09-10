<?php
define('JETHRO_ROOT', dirname(__FILE__));
require_once dirname(__FILE__).'/conf.php';
require_once dirname(__FILE__).'/include/init.php';
$result = array();
$result['user'] = $_SERVER['USER'];
$result['max_input_vars'] = (integer)ini_get('max_input_vars');
$result['post_max_size'] = (integer)ini_get('post_max_size');
$result['upload_max_filesize'] = (integer)ini_get('upload_max_filesize');
$result['db_charset_results'] =  $GLOBALS['db']->queryOne('select @@character_set_results');
# Jethro needs mysql, zip, xml, curl (mailchimp) and gd (photo resizing)
$result['extensions'] = get_loaded_extensions();
$result['mod_unique_id_loaded'] = array_key_exists('UNIQUE_ID', $_SERVER);
header('Content-Type: application/json');
print(json_encode($result));
session_destroy(); // Don't accumulate sessions unnecessarily
?>
