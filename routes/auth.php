<?php

Route::middleware(['guest'])->group(function () {

    Route::get('signin','Auth\LoginController@showSignIn')->name('signin');
    Route::post('signin','Auth\LoginController@postSignIn')->name('signin.post');

    Route::get('signup/{refid?}','Auth\RegisterController@showSignUp')->name('signup');

    Route::post('signup','Auth\RegisterController@signUpPost')->name('signup.post');

    Route::get('mnemonic/show','Auth\RegisterController@showMnemonic')->name('mnemonic');

    Route::get('/forgotpassword', 'Auth\ForgotPasswordController@showForget')->name('forgotpassword');
    Route::get('/forgotpassword/mnemonic', 'Auth\ForgotPasswordController@showMnemonic')->name('forgotpassword.mnemonic');
    Route::get('/forgotpassword/pgp', 'Auth\ForgotPasswordController@showPGP')->name('forgotpassword.pgp');

    Route::post('/forgotpassword/mnemonic', 'Auth\ForgotPasswordController@resetMnemonic')->name('forgotpassword.mnemonic.reset');
    Route::post('/forgotpassword/pgp', 'Auth\ForgotPasswordController@sendVerify')->name('forgotpassword.pgp.verify');

    Route::get('/forgotpassword/pgp/verify', 'Auth\ForgotPasswordController@showVerify')->name('pgprecover');
    Route::post('/forgotpassword/pgp/verify', 'Auth\ForgotPasswordController@resetPgp')->name('resetpgp');

});

Route::get('verify', 'Auth\LoginController@showVerify') -> name('verify');
Route::post('verify', 'Auth\LoginController@postVerify') -> name('verify.post');


Route::post('signout','Auth\LoginController@postSignOut')->name('signout.post');


