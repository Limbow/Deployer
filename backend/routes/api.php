<?php

use App\Http\Controllers\Api\BuildController;
use App\Http\Controllers\Api\DeployController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\ProjectFilesController;
use App\Http\Controllers\Api\ServerController;
use App\Http\Controllers\Api\ServerFilesController;
use App\Http\Controllers\Api\SettingsController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::apiResource('servers', ServerController::class);
Route::post('servers/{server}/test', [ServerController::class, 'test']);
Route::get('servers/{server}/files', [ServerFilesController::class, 'index']);
Route::get('servers/{server}/files/download', [ServerFilesController::class, 'download']);
Route::delete('servers/{server}/files', [ServerFilesController::class, 'destroy']);

Route::get('settings', [SettingsController::class, 'index']);
Route::put('settings', [SettingsController::class, 'update']);
Route::get('projects/scan', [ProjectController::class, 'scan']);
Route::post('projects/visibility', [ProjectController::class, 'visibility']);
Route::apiResource('projects', ProjectController::class);
Route::post('projects/{project}/bump-version', [ProjectController::class, 'bumpVersion']);
Route::post('projects/{project}/build', [BuildController::class, 'store']);
Route::get('projects/{project}/builds/latest', [BuildController::class, 'latest']);
Route::get('projects/{project}/deploys/active', [DeployController::class, 'active']);
Route::get('builds/{build}', [BuildController::class, 'show']);
Route::get('projects/{project}/files', [ProjectFilesController::class, 'index']);
Route::get('deploys', [DeployController::class, 'index']);
Route::post('deploys', [DeployController::class, 'store']);
Route::get('deploys/{deploy}', [DeployController::class, 'show']);
