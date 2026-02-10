<?php 

return [

    /**
     * Users table used for migrations
     */
    'users_table' => 'users',

    /**
     * Individually issued for legal entities
     * @link https://www.eparaksts.lv/en/for_developers/Application_test_environment 
     */
    'username'      => env('EPARAKSTS_USERNAME'),
    'password'      => env('EPARAKSTS_PASSWORD'),

    /**
     * Use a full URI.
     */
    'redirect'      => env('EPARAKSTS_REDIRECT', '/eparaksts/callback'),

    /**
     * Refer to the Eparaksts docs
     * @link https://developers.eparaksts.lv/
     * 
     * At the moment of writing however.
     * Development: https://eidas-demo.eparaksts.lv
     * Production:  https://eidas.eparaksts.lv
     */
    'host'          => env('EPARAKSTS_HOST', 'https://eidas-demo.eparaksts.lv'),

    
    /**
     * Refer to the Eparaksts docs
     * @link https://developers.eparaksts.lv/
     * 
     * At the moment of writing however.
     * Development: https://signapi-prep.eparaksts.lv
     * Production:  https://signapi.eparaksts.lv
     */
    'signapi_host'  => env('SIGNAPI_HOST', 'https://signapi-prep.eparaksts.lv'),

    'session_prefix' => 'eparaksts_',
    
    /**
     * No trailing slash please.
     */
    'route_prefix' => 'ep',
];