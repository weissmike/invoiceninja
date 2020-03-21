<?php
/*
 * Signup Routes
 */

Route::get('/', 'BaseController@flutterRoute')->middleware('guest');
Route::get('setup', 'SetupController@index')->middleware('guest');
Route::post('setup', 'SetupController@doSetup')->middleware('guest');

/*
 *  Password Reset Routes...
 */

Route::get('password/reset', 'Auth\ForgotPasswordController@showLinkRequestForm')->name('password.request');
Route::post('password/email', 'Auth\ForgotPasswordController@sendResetLinkEmail')->name('password.email');
Route::get('password/reset/{token}', 'Auth\ResetPasswordController@showResetForm')->name('password.reset');
Route::post('password/reset', 'Auth\ResetPasswordController@reset')->name('password.update');

/*
 * Social authentication
 */

// Route::get('auth/{provider}', 'Auth\LoginController@redirectToProvider');
// Route::get('auth/{provider}/create', 'Auth\LoginController@redirectToProviderAndCreate');

/*
 * Inbound routes requiring DB Lookup
 */
Route::group(['middleware' => ['url_db']], function () {
    Route::get('/user/confirm/{confirmation_code}', 'UserController@confirm');
});
