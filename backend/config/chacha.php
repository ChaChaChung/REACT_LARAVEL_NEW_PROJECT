<?php


return [
    'hn' => [
        'api_url' => env('HN_API_URL'),
        'merchant_code' => env('MERCHANT_CODE'),
        'merchant_public_key' => env('MERCHANT_PUBLIC_KEY'),
        'merchant_private_key' => env('MERCHANT_PRIVATE_KEY'),
    ],

    'gash' => [
        'api_url' => env('GASH_API_URL'),
        'cid' => env('GASH_CID'),
        'trans_pwd' => env('GASH_TRANS_PWD'),
        'trans_key_i' => env('GASH_TRANS_KEY_I'),
        'trans_key_ii' => env('GASH_TRANS_KEY_II'),
    ],
];