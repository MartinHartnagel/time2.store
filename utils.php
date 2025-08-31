<?php

function postSSE($customer, $userId, $target, $content) {
    $options = array(
          'http' => array(
                'header'  => "Content-type: text/plain;charset=UTF-8\r\nUser-Agent: Mozilla/5.0 (iPad; U; CPU OS 3_2 like Mac OS X; en-us) AppleWebKit/531.21.10 (KHTML, like Gecko) Version/4.0.4 Mobile/7B334b Safari/531.21.102011-10-16 20:23:10\r\n",
                'method'  => 'POST',
                'content' => $content,
            )
    );
    $context  = stream_context_create($options);
    $result = file_get_contents('https://time2.event.emphasize.de/?topic='.$customer.'&u='.$userId.'&target='. $target, false, $context);
}
