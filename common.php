<?php

define("DB_ALLOW_CREATE_TABLES", false); // set to true only temporarily to create the tables for your customer

if (true) { // set to false to not use SqliteDB
    /* SqliteDB configure connection: */
    define("DB_TYPE", "SqliteDB"); 
    // end of SqliteDB configure connection */
} else if (false) { // set to true to use MysqlDB
    /* MysqlDB configure connection: */
    define("DB_TYPE", "MysqlDB");
    define("DB_HOST", "127.0.0.1");
    define("DB_PORT", "3306");
    define("DB_NAME", "db123");
    define("DB_USER", "user");
    define("DB_PASSWORD", "12345");
    // end of MysqlDB configure connection */
} else if (false) { // set to true to use PgsqlDB
    /* PgsqlDB configure connection: */
    define("DB_TYPE", "PgsqlDB");
    define("DB_TYPE", "pgsql"); 
    define("DB_HOST", "127.0.0.1");
    define("DB_PORT", "5432");
    define("DB_NAME", "template1");
    define("DB_USER", "postgres");
    define("DB_PASSWORD", "12345");
    // end of PgsqlDB configure connection */
} else {
    exit();
}
define("PARTS_CHUNK", 3900);
define("SYNC_OVERRIDE_SEND_IMIT", 20000);

define("DEBUG", false);
if (DEBUG) $log = fopen(__DIR__ . "/debug.log", "a");

function debugLog($message) {
    global $log;
    if (DEBUG) fwrite($log, date("c") . " " . $message . "\n");
}

$allowed = ['https://time2.emphasize.de', 'https://time2.dev.emphasize.de', 'http://localhost:3000'];
$origin = isset($_SERVER['HTTP_ORIGIN']) ? preg_replace("/[^A-Za-z0-9:\/\.-_]/", '', $_SERVER['HTTP_ORIGIN']) : null;
$method = $_SERVER['REQUEST_METHOD'];

if (!isset($_REQUEST["topic"])) {
    exit();
}
$topic = preg_replace("/[^A-Za-z0-9 \\-]/", '', $_REQUEST['topic'] ?? 'test');

if (isset($origin) && in_array($origin, $allowed)) {
    header('Access-Control-Allow-Origin: ' . $origin);
} else {
    header('Access-Control-Allow-Origin: *');
}
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($method == 'OPTIONS') {
    exit();
}

$customer = preg_replace("/[^A-Za-z0-9]/", "", $_REQUEST["topic"]);
if (strlen($customer) == 0) {
    exit();
}

define("SUSPEND_SECONDS", 15);

$start = time();
$accepted = false;

$method = $_SERVER["REQUEST_METHOD"];

if ($method == "OPTIONS") {
    exit();
}

require_once __DIR__ . "/utils.php";

if (DB_TYPE == 'MysqlDB') {
    require_once __DIR__ . "/MysqlDB.php";
    $db = new MysqlDB($customer);
} else if (DB_TYPE == 'PgsqlDB') {
    require_once __DIR__ . "/PgsqlDB.php";
    $db = new PgsqlDB($customer);
} else if (DB_TYPE == 'SqliteDB') {
    require_once __DIR__ . "/SqliteDB.php";
    $db = new SqliteDB($customer);
} else {
    exit();
}

$userId = isset($_REQUEST["u"])
    ? preg_replace("/[^A-Za-z0-9]/", "", $_REQUEST["u"])
    : "extern";
