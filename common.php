<?php

require_once __DIR__ . "/config.php";

define("SYNC_OVERRIDE_SEND_INIT", 20000);
define("BAG_RETRY", 2);

define("DEBUG", false);
if (DEBUG) $log = fopen(__DIR__ . "/debug.log", "a");

function debugLog($message) {
    global $log;
    if (DEBUG) fwrite($log, date("c") . " " . $message . "\n");
}

$allowed = ['https://time2.emphasize.de', 'https://time2-dev.emphasize.de', 'http://localhost:3000'];
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

$customer = $topic;
if (strlen($customer) == 0) {
    exit();
}

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
