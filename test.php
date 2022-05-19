<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$getdata = http_build_query(
    [
        "event" => "ONCRMINVOICEADD",
        "data" => [
            "FIELDS" => ["ID" => 13],
        ],
        "ts" => "1608612091",
        "auth" => [
                "domain" => "zelinskygroup.bitrix24.ru",
                "client_endpoint" => "https://zelinskygroup.bitrix24.ru/rest/",
                "server_endpoint" => "https://oauth.bitrix.info/rest/",
                "member_id" => "952d69b7d65076bc08402f96716544ea",
                "application_token" => "96w4seimao74eruyyhllkav2vxnyajb8",
        ]
    ]
);

//echo "<pre>".print_r($getdata, true)."</pre>";
    
$opts = array('http' =>
     array(
        'header' => "Content-Type: application/x-www-form-urlencoded\r\n".
                    "Content-Length: ".strlen($getdata)."\r\n".
                    "User-Agent:MyAgent/1.0\r\n",         
        'method'  => 'POST',
        'content' => $getdata
    )
);
    
$context  = stream_context_create($opts);
    
$result = file_get_contents('https://webhooks.6hi.ru/zelenskiy/', false, $context);
?><!DOCTYPE html>
<html>
<head><meta charset="utf-8"></head>
<body>
>>>>
<?echo "<pre>".print_r($result, true)."</pre>";?>
<<<
</body>
</html>
