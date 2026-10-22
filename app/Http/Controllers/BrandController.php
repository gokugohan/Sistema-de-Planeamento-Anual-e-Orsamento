<?php

namespace App\Http\Controllers;

use App\Support\Branding;

/** Serves the institution's logo and browser icon; they must be public, the sign-in page shows them. */
class BrandController extends Controller
{
    public function logo()
    {
        return $this->image('logo');
    }

    public function favicon()
    {
        return $this->image('favicon');
    }

    private function image(string $which)
    {
        $path = Branding::file($which);
        abort_unless($path, 404);

        // the address carries a version (?v=), which changes when the logo is replaced: it can be cached for a long time
        return response()->file($path, ['Content-Type' => 'image/png', 'Cache-Control' => 'public, max-age=31536000, immutable']);
    }
}
