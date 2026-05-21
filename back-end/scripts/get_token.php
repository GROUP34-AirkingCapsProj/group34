<?php
$user = App\Models\User::first();
echo "User: {$user->user_id} | {$user->email}\n";
$token = $user->createToken('phase4-test')->plainTextToken;
echo "TOKEN: {$token}\n";
