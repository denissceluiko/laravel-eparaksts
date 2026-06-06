<?php

return [

    /**
     * User model to be used in migration generation
     */
    'user_model' => 'App\Models\User',
    
    /**
     * Field names in users table
     */
    'fields' => [
        'full_name'         => 'full_name',       
        'first_name'        => 'first_name',      
        'last_name'         => 'last_name',       
        'personal_number'   => 'personal_number', // Will be stored as PNOXX-YYYYYY-ZZZZZ
    ],

    /**
     * First, last and full name are returned all caps from eParakss platform.
     * Enabling this will attempt normalizing name on authentication and registration
     * e.g. JĀNIS -> Jānis
     * This may be relevant for case sensitive databases.
     */
    'normalize_names' => false, 

    /**
     * List of fields that must match for successful authentication.
     */
    'authentication_match' => ['personal_number', 'full_name', 'first_name', 'last_name'],
    
    /**
     * Should new users be created?
     */
    'registration_enabled' => false,

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
     * No trailing slash.
     */
    'route_prefix' => 'ep',

    /**
     * Redirect destinations used by the package controller.
     *
     * login  — fallback for redirect()->intended() after successful identification,
     *          failed identification (user_not_found), registration, and default OAuth callback.
     * logout — destination after the logout flow completes (session already flushed,
     *          so no intended URL applies).
     * error  — destination on hard errors: OAuth state mismatch and OAuth error callbacks.
     *          Also used as the fallback for redirect()->intended() on OAuth errors.
     */
    'redirects' => [
        'login'            => '/',
        'logout'           => '/',
        'error'            => '/',
        'signing_complete' => '/',
    ],

    /**
     * Whether to pipe internal errors and warnings to Laravel's logger.
     * Disable if you are concerned about API error responses (which may include
     * session metadata) appearing in your log output.
     */
    'logging' => env('EPARAKSTS_LOGGING', true),
];