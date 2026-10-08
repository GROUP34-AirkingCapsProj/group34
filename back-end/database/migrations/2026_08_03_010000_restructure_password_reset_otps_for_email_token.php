<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Forgot-password OTP only (not login 2FA).
 * Columns: email, hashed otp, expires_at, verified_token.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('password_reset_otps');

        Schema::create('password_reset_otps', function (Blueprint $table) {
            $table->id();
            $table->string('email', 255);
            $table->string('otp_hash', 255);
            $table->timestamp('expires_at');
            $table->string('verified_token', 64)->nullable()->unique();
            $table->timestamps();

            $table->index(['email', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_otps');

        Schema::create('password_reset_otps', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('channel', 10);
            $table->string('contact', 255);
            $table->string('otp_hash', 255);
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('user_id')->on('users')->cascadeOnDelete();
            $table->index(['user_id', 'used_at', 'expires_at']);
            $table->index(['contact', 'channel']);
        });
    }
};
