<?php

declare(strict_types=1);

return [
    'show_title'     => 'Cozy News Corner',
    'show_tagline'   => 'Your source for Open Source news',

    'sections' => [
        'vulnerability' => 'Vulnerability',
        'news'          => 'News',
    ],

    'db_path' => __DIR__ . '/../var/shownotes.sqlite',

    'scrape_timeout'   => 5,
    'scrape_useragent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 '
                        . '(KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    'max_redirects'    => 5,

    // WebSocket server address; bound to all interfaces so LAN clients can connect.
    // Change to '127.0.0.1' to restrict to localhost only.
    'ws_host' => '0.0.0.0',
    'ws_port' => 9001,
];
