<?php

require_once __DIR__ . "/common.php";

$from = isset($_REQUEST["from"])
    ? +preg_replace("/[^0-9]/", "", $_REQUEST["from"])
    : time() * 1000;

$to = isset($_REQUEST["to"])
    ? +preg_replace("/[^0-9]/", "", $_REQUEST["to"])
    : time() * 1000;

if ($method == "GET") {
    header("status: 200");
    header('Content-Type: application/json;charset=utf-8;');
    $db->loadEventsInRange($from, $to, $events, $infos);
    $o = ['events' => $events, 'infos' => $infos];
    $c = json_encode($o);
    echo($c);
}