<?php
$user = App\Models\User::find(2);
// Delete old test tokens
\Laravel\Sanctum\PersonalAccessToken::where('tokenable_id', 2)
    ->where('name', 'phase4-test')
    ->delete();

$token = $user->createToken('phase4-test', ['*'], now()->addHours(4))->plainTextToken;
echo "TOKEN: {$token}\n";
echo "Expires: " . now()->addHours(4) . "\n";
