<?php

require_once __DIR__ . "/common.php";

$id = preg_replace("/[^A-Za-z0-9]/", "", $_REQUEST["id"]);

if ($method == "GET") {
    $nt_changed = +preg_replace("/[^0-9]/", "", $_REQUEST["nt_changed"] ?? '0');
    $c = $db->loadNote($id);
    if (!$c) {
        http_response_code(404);
        exit();
    }
    $o = json_decode($c, true);
    if ($o['changed'] > $nt_changed) {
        http_response_code(200);
        header('Content-Type: application/json;charset=utf-8;');
        echo($c);
    } else {
        http_response_code(202);
    }
} else if ($method == "POST") {
    $content = file_get_contents("php://input");
    $note = json_decode($content, true);
    $db->storeNote($id, $note);
    http_response_code(202);
} else if ($method == "DELETE") {
    $db->deleteNote($id);
    http_response_code(202);
}
