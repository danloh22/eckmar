<?php


Route::get('index', 'Admin\AdminController@index') -> name('admin.index');

Route::get('wallet/withdrawals', 'Admin\WalletController@withdrawals')->name('admin.wallet.withdrawals');
Route::get('wallets', 'Admin\WalletController@wallets')->name('admin.wallets');
Route::post('wallets/{wallet}/status/{status}', 'Admin\WalletController@setWalletStatus')->name('admin.wallets.status');
Route::post('wallets/{wallet}/adjust', 'Admin\WalletController@adjustWallet')->name('admin.wallets.adjust');
Route::post('wallet/withdrawals/{withdrawal}/approve', 'Admin\WalletController@approve')->name('admin.wallet.withdrawals.approve');
Route::post('wallet/withdrawals/{withdrawal}/reject', 'Admin\WalletController@reject')->name('admin.wallet.withdrawals.reject');
Route::post('wallet/withdrawals/{withdrawal}/resolve-failed', 'Admin\WalletController@resolveFailedWithdrawal')->name('admin.wallet.withdrawals.resolve-failed');
Route::get('wallet/exchanges', 'Admin\WalletController@exchanges')->name('admin.wallet.exchanges');
Route::post('wallet/fee-addresses/{coin}', 'Admin\WalletController@updateFeeWallet')->name('admin.wallet.fee-addresses.update');
Route::post('wallet/liquidity/{coin}', 'Admin\WalletController@adjustMarketLiquidity')->name('admin.wallet.liquidity.adjust');

// Categories routes
Route::get('categories', 'Admin\AdminController@categories') -> name('admin.categories');
Route::post('category/new', 'Admin\AdminController@newCategory') -> name('admin.categories.new');
Route::get('category/delete/{id}', 'Admin\AdminController@removeCategory') -> name('admin.categories.delete');
Route::get('category/{id}', 'Admin\AdminController@editCategoryShow') -> name('admin.categories.show');
Route::post('category/{id}', 'Admin\AdminController@editCategory') -> name('admin.categories.edit');

// Mass message routes
Route::get('message', 'Admin\AdminController@massMessage') -> name('admin.messages.mass');
Route::post('message/send', 'Admin\AdminController@sendMessage') -> name('admin.messages.send');

// User routes
Route::get('users','Admin\UserController@users')->name('admin.users');
Route::post('users/query','Admin\UserController@usersPost')->name('admin.users.query');
Route::get('users/{user?}','Admin\UserController@userView')->name('admin.users.view');

Route::post('users/edit/group/{user}','Admin\UserController@editUserGroup')->name('admin.user.edit.group');
Route::post('users/edit/info/{user}','Admin\UserController@editBasicInfo')->name('admin.user.edit.info');

Route::post('users/ban/{user}', 'Admin\UserController@banUser')->name('admin.user.ban');
Route::post('users/{user}/withdrawal-pin/reset', 'Admin\UserController@resetWithdrawalPin')->name('admin.user.withdrawal-pin.reset');
Route::get('users/remove/ban/{ban}', 'Admin\UserController@removeBan')->name('admin.ban.remove');


// Log
Route::get('log','Admin\LogController@showLog')->name('admin.log');

// Products
Route::get('products','Admin\ProductController@products')->name('admin.products');
Route::post('products/query','Admin\ProductController@productsPost')->name('admin.products.query');
Route::post('product/delete','Admin\ProductController@deleteProduct')->name('admin.product.delete');
Route::get('product/{id}/{section?}', 'Admin\ProductController@editProduct') -> name('admin.product.edit');

Route::get('purchases', 'Admin\ProductController@purchases')->name('admin.purchases');


// Bitmessage
Route::get('bitmessage','Admin\BitmessageController@show')->name('admin.bitmessage');

// Disputes
Route::get('disputes', 'Admin\AdminController@disputes') -> name('admin.disputes');
Route::get('purchase/{purchase}', 'Admin\AdminController@purchase') -> name('admin.purchase');

// Support tickets
Route::get('tickets', 'Admin\AdminController@tickets') -> name('admin.tickets');
Route::get('ticket/{ticket}', 'Admin\AdminController@viewTicket') -> name('admin.tickets.view');
Route::get('ticket/{ticket}/solve', 'Admin\AdminController@solveTicket') -> name('admin.tickets.solve');

// Vendor purchases
Route::get('vendor/purchases', 'Admin\AdminController@vendorPurchases') -> name('admin.vendor.purchases');

// Featured products

Route::get('products/featured','Admin\ProductController@featuredProductsShow')->name('admin.featuredproducts.show');
Route::get('products/featured/mark/{product}','Admin\ProductController@markAsFeatured')->name('admin.product.markfeatured');

Route::post('products/featured/remove','Admin\ProductController@removeFromFeatured')->name('admin.featuredproducts.remove');

// Remove tickets

Route::post('tickets/remove','Admin\AdminController@removeTickets')->name('admin.tickets.remove');
