<?php
define('JETHRO_ROOT', dirname(__FILE__));
require_once dirname(__FILE__).'/conf.php';
require_once dirname(__FILE__).'/include/init.php';
// If we can't connect to the database or something else is majorly wrong, we would have returned a 200 HTML error page to the caller by now.
// I have considered setting a custom error handler with set_error_handler() and returning HTTP 500 with a json response, but the caller should
// be treating any failure to return JSON as critical anyway.
$result = array();
$result['user'] = $_SERVER['USER'];
$result['max_input_vars'] = (integer)ini_get('max_input_vars');
$result['post_max_size'] = (integer)ini_get('post_max_size');
$result['upload_max_filesize'] = (integer)ini_get('upload_max_filesize');
$result['db_charset'] =  $GLOBALS['db']->queryOne('select @@character_set_results');
// Help diagnose charset and collation problems. https://github.com/tbar0970/jethro-pmm/pull/754
$result['db_table_charsets'] = $GLOBALS['db']->queryAll("SELECT DISTINCT ccsa.character_set_name,
                count(*) AS count
FROM information_schema.`tables` t,
     information_schema.`collation_character_set_applicability` ccsa
WHERE ccsa.collation_name = t.table_collation
  AND t.table_schema = database()
GROUP BY 1
ORDER BY 2 DESC;");
$result['db_table_collations'] = $GLOBALS['db']->queryAll("SELECT DISTINCT table_collation,
                count(*) AS count
FROM information_schema.`tables` t,
     information_schema.`collation_character_set_applicability` ccsa
WHERE ccsa.collation_name = t.table_collation
  AND t.table_schema = database()
GROUP BY 1
ORDER BY 2 DESC;");
# Jethro needs mysql, zip, xml, curl (mailchimp) and gd (photo resizing)
$required_extensions = ["pdo_mysql", "zip", "curl", "gd"];
$missing_extensions = array_diff($required_extensions, get_loaded_extensions());
$result['phpversion'] = phpversion();
$result['extensions_missing'] = $missing_extensions;
$result['mod_unique_id_loaded'] = array_key_exists('UNIQUE_ID', $_SERVER);
header('Content-Type: application/json');
print(json_encode($result, JSON_PRETTY_PRINT));
session_destroy(); // Don't accumulate sessions unnecessarily
?>
