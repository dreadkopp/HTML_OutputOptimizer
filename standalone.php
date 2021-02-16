<?php

require 'vendor/autoload.php';

use dreadkopp\HTML_OutputOptimizer\OutputOptimizer;
use Predis\Client;

$target = $_SERVER['TARGET'];
$requested_path = $_SERVER['REQUEST_URI'];

$fullpath = $target.$requested_path;
$content = file_get_contents($fullpath);

function get_proxy_site_page( $url )
{
    $options = [
        CURLOPT_RETURNTRANSFER => true,     // return web page
        CURLOPT_HEADER         => true,     // return headers
        CURLOPT_FOLLOWLOCATION => true,     // follow redirects
        CURLOPT_ENCODING       => "",       // handle all encodings
        CURLOPT_AUTOREFERER    => true,     // set referer on redirect
        CURLOPT_CONNECTTIMEOUT => 120,      // timeout on connect
        CURLOPT_TIMEOUT        => 120,      // timeout on response
        CURLOPT_MAXREDIRS      => 10,       // stop after 10 redirects
        CURLOPT_USERAGENT      => $_SERVER['HTTP_USER_AGENT'],
    ];
    
    $ch = curl_init($url);
    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $header = substr($response, 0, $header_size);
    $body = substr($response, $header_size);
    curl_close($ch);
    
    return [$header,$body];
}

function get_headers_from_curl_response($headerContent)
{
    
    $headers = array();
    
    // Split the string on every "double" new line.
    $arrRequests = explode("\r\n\r\n", $headerContent);
    
    // Loop of response headers. The "count() -1" is to
    //avoid an empty row for the extra line break before the body of the response.
    for ($index = 0; $index < count($arrRequests) -1; $index++) {
        
        foreach (explode("\r\n", $arrRequests[$index]) as $i => $line)
        {
            if ($i === 0)
                $headers[$index]['http_code'] = $line;
            else
            {
                list ($key, $value) = explode(': ', $line);
                $headers[$index][$key] = $value;
            }
        }
    }
    
    return $headers;
}

$predis = new Client(
    [
        'scheme'   => 'tcp',
        'host'     => '127.0.0.1',
        'port' =>  '6379',
        'database' => 1,
        'password' => '',
    ]
);
$optimizer = new OutputOptimizer(
    $predis,
    '/var/www/optimizer',
    '/cache/',
    '/cache/',
    '',
    false,
    0
);

[$header,$body] = get_proxy_site_page($fullpath);

foreach (get_headers_from_curl_response($header) as $_header) {
    
    if (strpos($_header['http_code'] ,'301') || strpos($_header['http_code'] ,'302')) {
        continue;
    }
    foreach ($_header as $name => $__head) {
        header($name.': '.$__head);
    }
}
$optimizer->addLocalJSPath(__DIR__.'/src/Library/jquery.3.5.1.slim.min.js');
echo $optimizer->sanitize_output($body,true);

