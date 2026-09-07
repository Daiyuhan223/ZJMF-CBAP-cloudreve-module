<?php

use think\facade\Route;

$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';

// 前台接口：无需登录（购买页数据、价格计算）
Route::group('console/v1', function () {
    Route::get('cloudreve_group/product/:product_id/configoption', "\server\cloudreve_group\controller\home\ProductController@configoption");
    Route::post('cloudreve_group/product/:product_id/configoption/calculate', "\server\cloudreve_group\controller\home\ProductController@calculate");
})->allowCrossDomain([
    'Access-Control-Allow-Origin'      => $origin,
    'Access-Control-Allow-Credentials' => 'true',
    'Access-Control-Max-Age'           => 600,
])->middleware(\app\http\middleware\Check::class);

// 前台接口：需要登录（产品列表、一键登录）
Route::group('console/v1', function () {
    Route::get('cloudreve_group/host', "\server\cloudreve_group\controller\home\HostController@lists");
    Route::post('cloudreve_group/host/:host_id/custom/provision', "\server\cloudreve_group\controller\home\HostController@customProvision");
})->allowCrossDomain([
    'Access-Control-Allow-Origin'      => $origin,
    'Access-Control-Allow-Credentials' => 'true',
    'Access-Control-Max-Age'           => 600,
])->middleware(\app\http\middleware\CheckHome::class)
    ->middleware(\app\http\middleware\ParamFilter::class);

// 定时任务（无需登录）
Route::get('cron/check', 'server\cloudreve_group\controller\home\CronController@check');

// 后台接口
Route::group(DIR_ADMIN . '/v1', function () {
    // 商品用户组映射
    Route::post('cloudreve_group/product/:product_id/group_map', "\server\cloudreve_group\controller\admin\GroupMapController@create");
    Route::delete('cloudreve_group/product/:product_id/group_map/:id', "\server\cloudreve_group\controller\admin\GroupMapController@delete");

    // 自定义周期（周期价格后台可配）
    Route::get('cloudreve_group/product/:product_id/custom_cycle', "\server\cloudreve_group\controller\admin\CustomCycleController@lists");
    Route::post('cloudreve_group/product/:product_id/custom_cycle', "\server\cloudreve_group\controller\admin\CustomCycleController@create");
    Route::put('cloudreve_group/product/:product_id/custom_cycle/:id', "\server\cloudreve_group\controller\admin\CustomCycleController@update");
    Route::delete('cloudreve_group/product/:product_id/custom_cycle/:id', "\server\cloudreve_group\controller\admin\CustomCycleController@delete");
})->allowCrossDomain([
    'Access-Control-Allow-Origin'      => $origin,
    'Access-Control-Allow-Credentials' => 'true',
    'Access-Control-Max-Age'           => 600,
])->middleware(\app\http\middleware\CheckAdmin::class)
    ->middleware(\app\http\middleware\ParamFilter::class);
