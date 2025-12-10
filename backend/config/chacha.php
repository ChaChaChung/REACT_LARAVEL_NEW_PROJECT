<?php


return [
    'hn' => [
        'api_url' => env('HN_API_URL'),
        'merchant_code' => env('HN_MERCHANT_CODE'),
        'merchant_public_key' => env('HN_MERCHANT_PUBLIC_KEY'),
        'merchant_private_key' => env('HN_MERCHANT_PRIVATE_KEY'),
    ],

    'vg' => [
        'api_url' => env('VG_API_URL'),
        'agent' => env('VG_AGENT'),
        'api_key' => env('VG_API_KEY'),
        'user_suffix' => env('VG_USER_SUFFIX'),
    ],

    'gash' => [
        'api_url' => env('GASH_API_URL'),
        'cid' => env('GASH_CID'),
        'trans_pwd' => env('GASH_TRANS_PWD'),
        'trans_key_i' => env('GASH_TRANS_KEY_I'),
        'trans_key_ii' => env('GASH_TRANS_KEY_II'),
    ],
];