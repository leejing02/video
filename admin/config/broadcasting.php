<?php

/**
 * Laravel admin 不再发广播，WebSocket 完全走 Go API。
 * 这里默认 log 驱动，避免空 KEY 触发 Pusher 实例化报错。
 */
return [
    'default' => env('BROADCAST_CONNECTION', 'log'),

    'connections' => [

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

        // 想恢复 Reverb 的话把下面解开 + .env 配 REVERB_APP_KEY 等；
        // 但我们的架构里实时推送由 Go 实现，没必要再用 Laravel 广播。
        //
        // 'reverb' => [
        //     'driver'  => 'reverb',
        //     'key'     => env('REVERB_APP_KEY'),
        //     'secret'  => env('REVERB_APP_SECRET'),
        //     'app_id'  => env('REVERB_APP_ID'),
        //     'options' => [
        //         'host'   => env('REVERB_HOST'),
        //         'port'   => env('REVERB_PORT', 443),
        //         'scheme' => env('REVERB_SCHEME', 'https'),
        //         'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
        //     ],
        // ],
    ],
];
