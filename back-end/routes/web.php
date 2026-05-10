<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

use App\Mail\SendOTPMail;
use Illuminate\Support\Facades\Mail;

Route::get('/test-mail', function () {
    try {
        Mail::to('test@example.com')->send(new SendOTPMail('123456'));
        return "Email sent successfully! Check your Mailtrap inbox.";
    } catch (\Exception $e) {
        return "Error: " . $e->getMessage();
    }
});