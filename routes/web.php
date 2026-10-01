<?php

use App\Http\Controllers\AppChatController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BookingsController;
use App\Http\Controllers\ClientsController;
use App\Http\Controllers\DeskController;
use App\Http\Controllers\PayController;
use App\Http\Controllers\PaymentsController;
use App\Http\Controllers\PaymentWebhookController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SimulatorController;
use App\Http\Controllers\TemplatesController;
use App\Http\Controllers\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

// ---- Webhooks (CSRF-exempt, see bootstrap/app.php) --------------------------
Route::get('/webhooks/whatsapp', [WhatsAppWebhookController::class, 'verify']);
Route::post('/webhooks/whatsapp', [WhatsAppWebhookController::class, 'receive'])->middleware('throttle:600,1');
Route::post('/webhooks/payments', PaymentWebhookController::class)->middleware('throttle:600,1');

// ---- The Safara app (clients chat here instead of on WhatsApp) -------------
Route::get('/', [AppChatController::class, 'show'])->name('app');
Route::prefix('app')->group(function () {
    Route::post('/start', [AppChatController::class, 'start'])->middleware('throttle:20,1')->name('app.start');
    Route::get('/messages', [AppChatController::class, 'messages'])->middleware('throttle:240,1')->name('app.messages');
    Route::post('/send', [AppChatController::class, 'send'])->middleware('throttle:60,1')->name('app.send');
    Route::get('/documents/{message}', [AppChatController::class, 'document'])->middleware('throttle:60,1')->name('app.document');
    Route::post('/forget', [AppChatController::class, 'forget'])->middleware('throttle:10,1')->name('app.forget');
});

// ---- Client pay pages (public, opened from WhatsApp) ------------------------
Route::prefix('pay/{token}')->middleware('throttle:60,1')->group(function () {
    Route::get('/', [PayController::class, 'show'])->name('pay.show');
    Route::post('/', [PayController::class, 'start'])->name('pay.start');
    Route::get('/return', [PayController::class, 'back'])->name('pay.return');
    Route::get('/done', [PayController::class, 'done'])->name('pay.done');
    Route::post('/requote', [PayController::class, 'requote'])->middleware('throttle:3,10')->name('pay.requote');
    Route::get('/test', [PayController::class, 'fake'])->name('pay.fake');
    Route::post('/test', [PayController::class, 'fakeComplete'])->name('pay.fake.complete');
});

// ---- Desk login -------------------------------------------------------------
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'show'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
});
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

// ---- Operator desk ----------------------------------------------------------
Route::middleware('auth')->group(function () {
    Route::get('/desk', [DeskController::class, 'index'])->name('desk');

    Route::get('/bookings', [BookingsController::class, 'index'])->name('bookings.index');
    Route::get('/bookings/export', [BookingsController::class, 'export'])->name('bookings.export');
    Route::get('/bookings/new', [BookingsController::class, 'create'])->name('bookings.create');
    Route::post('/bookings', [BookingsController::class, 'store'])->name('bookings.store');
    Route::get('/bookings/{booking}', [BookingsController::class, 'show'])->name('bookings.show');
    Route::post('/bookings/{booking}/search', [BookingsController::class, 'search'])->name('bookings.search');
    Route::post('/bookings/{booking}/issue', [BookingsController::class, 'issue'])->name('bookings.issue');
    Route::post('/bookings/{booking}/requote', [BookingsController::class, 'requote'])->name('bookings.requote');
    Route::post('/bookings/{booking}/cancel', [BookingsController::class, 'cancel'])->name('bookings.cancel');
    Route::get('/bookings/{booking}/review', [BookingsController::class, 'review'])->name('bookings.review');
    Route::post('/bookings/{booking}/review', [BookingsController::class, 'decide'])->name('bookings.decide');
    Route::get('/bookings/{booking}/chat', [BookingsController::class, 'chat'])->name('bookings.chat');
    Route::post('/bookings/{booking}/chat', [BookingsController::class, 'reply'])->name('bookings.reply');
    Route::post('/bookings/{booking}/bot', [BookingsController::class, 'toggleBot'])->name('bookings.bot');
    Route::put('/bookings/{booking}/passports/{passport}', [BookingsController::class, 'updatePassport'])->name('bookings.passport');
    Route::get('/bookings/{booking}/passports/{passport}/photo', [BookingsController::class, 'photo'])->name('bookings.photo');
    Route::get('/bookings/{booking}/ticket', [BookingsController::class, 'ticket'])->name('bookings.ticket');

    Route::get('/clients', [ClientsController::class, 'index'])->name('clients.index');
    Route::put('/clients/{client}/notes', [ClientsController::class, 'notes'])->name('clients.notes');
    Route::put('/clients/{client}/language', [ClientsController::class, 'language'])->name('clients.language');
    Route::delete('/clients/{client}/passports/{passport}', [ClientsController::class, 'destroyPassport'])->name('clients.passport.destroy');
    Route::get('/clients/{client}/passports/{passport}/photo', [ClientsController::class, 'photo'])->name('clients.photo');

    Route::get('/payments', [PaymentsController::class, 'index'])->name('payments.index');
    Route::post('/payments/{payment}/refund', [PaymentsController::class, 'refund'])->name('payments.refund');
    Route::post('/payments/{payment}/refunded', [PaymentsController::class, 'markSent'])->name('payments.sent');
    Route::post('/payments/{payment}/resend', [PaymentsController::class, 'resend'])->name('payments.resend');

    Route::get('/templates', [TemplatesController::class, 'index'])->name('templates.index');
    Route::put('/templates/{template}', [TemplatesController::class, 'update'])->name('templates.update');
    Route::post('/templates/{template}/test', [TemplatesController::class, 'test'])->name('templates.test');

    Route::get('/settings', [SettingsController::class, 'edit'])->name('settings');
    Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');

    Route::get('/dev/simulator', [SimulatorController::class, 'index'])->name('simulator');
    Route::post('/dev/simulator', [SimulatorController::class, 'send'])->name('simulator.send');
    Route::post('/dev/simulator/control', [SimulatorController::class, 'control'])->name('simulator.control');
});
