<?php

use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/health', function () {
        return response()->json([
            'success' => true,
            'data'    => ['status' => 'ok'],
            'message' => 'OK',
            'meta'    => [],
        ]);
    });
});

require app_path('Modules/Auth/Routes/api.php');
require app_path('Modules/Organizations/Routes/api.php');
require app_path('Modules/Documents/Routes/api.php');
require app_path('Modules/Compliance/Routes/api.php');
require app_path('Modules/Superadmin/Routes/api.php');
require app_path('Modules/Workflow/Routes/api.php');
