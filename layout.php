<?php

require_once __DIR__ . "/common.php";

$at = isset($_REQUEST["at"])
    ? +preg_replace("/[^0-9]/", "", $_REQUEST["at"])
    : time() * 1000;

if ($method == "GET") {
    header("status: 200");
    header('Content-Type: application/json;charset=utf-8;');
    $db->loadLayoutAndChanged($at, $layout, $changed);
    $o = ['layout' => $layout, 'changed' => $changed];
    $c = json_encode($o);
    echo($c);
}