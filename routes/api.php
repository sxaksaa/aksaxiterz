use App\Http\Controllers\PaymentController;

Route::post('/midtrans-callback', [PaymentController::class, 'midtransCallback']);