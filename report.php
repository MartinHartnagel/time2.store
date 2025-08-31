<?php

require_once __DIR__ . "/common.php";

$from = isset($_REQUEST["from"])
    ? +preg_replace("/[^0-9]/", "", $_REQUEST["from"])
    : time() * 1000;

$to = isset($_REQUEST["to"])
    ? +preg_replace("/[^0-9]/", "", $_REQUEST["to"])
    : time() * 1000;

$group = isset($_REQUEST["group"])
    ? preg_replace("/[^a-z]/", "", $_REQUEST["group"])
    : 'day';

$quantisize = isset($_REQUEST["quantisize"])
    ? +preg_replace("/[^0-9]/", "", $_REQUEST["quantisize"])
    : 60000;

$includeInfos = isset($_REQUEST["includeInfos"])
    ? preg_replace("/[^a-z]/", "", $_REQUEST["includeInfos"]) == 'true'
    : false;

$includeColors = isset($_REQUEST["includeColors"])
    ? preg_replace("/[^a-z]/", "", $_REQUEST["includeColors"]) == 'true'
    : false;

if ($method == "GET") {
    header("status: 200");
    header('Content-Type: application/json;charset=utf-8;');
    $db->loadEventsInRange($from, $to, $events, $infos);
    foreach($events as $i=>$e) {
        if (+$e['s'] < $from) {
            $events[$i]['s'] = "".$from;
        }
        if (!isset($e['e']) || +$e['e'] > $to) {
            $events[$i]['e'] = "".$to;
        }
    }
    $entries = [];
    $offset = $from;
    while($offset < $to) {
        $sum = null;
        switch($group) {
            case 'overall':
                $next = $to;
                break;
            case 'day':
                $next = strtotime("+1 day", $offset / 1000) * 1000;
                break;
            case 'week':
                $next = strtotime("+1 week", $offset / 1000) * 1000;
                break;
            case 'month':
                $next = strtotime("+1 month", $offset / 1000) * 1000;
                break;
            case 'year':
                $next = strtotime("+1 year", $offset / 1000) * 1000;
                break;
        }
        $map = [];
        $io = [];
        foreach($infos as $info) {
            if ($info['s'] >= $offset && $info['s'] < $next) {
                $io[$info['s']] = $info;
            }
        }
        foreach($events as $i=>$event) {
            if (+$event['s'] <= $next && +$event['e'] >= $offset) {
                $s = max(+$event['s'], $offset);
                $e = min(+$event['e'], $next);
                $t = $e - $s;
                if (!key_exists($event['n'], $map)) {
                    $map[$event['n']] = ['time' => 0, 'infos' => []];
                }
                $map[$event['n']]['time'] += $t;
                foreach($infos as $info) {
                    if ($info['s'] >= $s && $info['s'] < $e) {
                        $map[$event['n']]['infos'][] = $info;
                        unset($io[$info['s']]);
                    }
                }
                $sum += $t;
            }
        }
        foreach($map as $name=>$o) {
            $entry = ['start' => $offset, 'name' => $name, 'time' => round($o['time'] / $quantisize) * $quantisize];
            if (count($o['infos']) && $includeInfos) {
                $entry['infos'] = $o['infos'];
            }
            if (+$entry['time'] > 0 || isset($entry['infos'])) {
                $entries[] = $entry;
            }
        }
        $entry = ['start' => $offset, 'sum' => round($sum / $quantisize) * $quantisize];
        if (count($io) && $includeInfos) {
            $entry['infos'] = array_values($io);
        }
        if (+$entry['sum'] > 0 || isset($entry['infos'])) {
            $entries[] = $entry;
        }
        $offset = $next;
    }
    $o = ['entries' => $entries];
    $c = json_encode($o);
    echo($c);
}