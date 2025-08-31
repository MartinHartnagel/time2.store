<?php
define("DB_TYPE", "sqlite"); // or mysql, configure below

/* mysql configure connection:

define("DB_HOST", "127.0.0.1");
define("DB_PORT", "3306");
define("DB_NAME", "db123");
define("DB_USER", "user");
define("DB_PASSWORD", "12345");

// end of mysql configure connection */

/*
-- mysql create schema, replace "test" with your customer/topic and execute on mysql instance:

CREATE TABLE IF NOT EXISTS test_LAYOUT (time bigint NOT NULL UNIQUE, value text CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL);
CREATE TABLE IF NOT EXISTS test_EVENT (time bigint NOT NULL UNIQUE, name varchar(256) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL, color varchar(7) NOT NULL, end bigint);
CREATE TABLE IF NOT EXISTS test_INFO (time bigint NOT NULL UNIQUE, info text CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL);
CREATE TABLE IF NOT EXISTS test_INVOICE (`key` varchar(256) NOT NULL UNIQUE, `value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL);
*/

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

if (DB_TYPE == 'mysql') {
    require_once __DIR__ . "/MysqlDB.php";
    $db = new MysqlDB($customer);
} else if (DB_TYPE == 'sqlite') {
    require_once __DIR__ . "/SqliteDB.php";
    $db = new SqliteDB($customer);
} else {
    exit();
}

$userId = isset($_REQUEST["u"])
    ? preg_replace("/[^A-Za-z0-9]/", "", $_REQUEST["u"])
    : "extern";
