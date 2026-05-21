<?php
use Laravel\Sanctum\PersonalAccessToken;

$tokenStr = "44|qBEjzRkAMFj3RBWu4RpNJ6IKQvySXxpIWJye6eXa71749d9a";
$parts = explode('|', $tokenStr, 2);
$id = $parts[0];
$plainToken = $parts[1];

$pat = PersonalAccessToken::find($id);
if (!$pat) {
    echo "Token ID=$id not found in DB\n";
} else {
    echo "Found token: id={$pat->id} name={$pat->name} user_id={$pat->tokenable_id}\n";
    // Verify hash
    $valid = hash_equals($pat->token, hash('sha256', $plainToken));
    echo "Hash valid: " . ($valid ? 'YES' : 'NO') . "\n";
    echo "Expires: " . ($pat->expires_at ?? 'never') . "\n";
}

// Also check total token count
$count = PersonalAccessToken::count();
echo "Total tokens in DB: $count\n";
