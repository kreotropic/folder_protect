<?php
return [
    'routes' => [
        ['name' => 'admin#list', 'url' => '/api/list', 'verb' => 'GET'],
        ['name' => 'admin#protect', 'url' => '/api/protect', 'verb' => 'POST'],
        ['name' => 'admin#unprotect', 'url' => '/api/unprotect', 'verb' => 'POST'],
        ['name' => 'admin#check', 'url' => '/api/check', 'verb' => 'GET'],
        ['name' => 'admin#clearCache', 'url' => '/api/cache/clear', 'verb' => 'POST'],
    ]
];
