<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;

class AvatarController extends Controller
{
    /** Same public images as /storage/avatars, without depending on a symlink. */
    public function show(string $filename)
    {
        abort_unless(preg_match('/\A[A-Za-z0-9_-]+\.(?:jpg|jpeg|png|webp)\z/i', $filename), 404);
        $disk = Storage::disk('public');
        $path = 'avatars/' . $filename;
        abort_unless($disk->exists($path), 404);
        return $disk->response($path, null, [
            'Cache-Control' => 'public, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
