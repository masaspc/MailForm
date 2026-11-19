<?php

namespace App\Http\Controllers;

use App\Models\Theme;
use App\Services\ThemeAssets;
use App\Services\ThemeCatalog;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

final class ThemeAssetController
{
    public function __invoke(Request $request, string $slug, string $path): Response
    {
        abort_unless(ThemeAssets::validSlug($slug) && ThemeAssets::validPath($path), 404);
        $theme = Theme::where('slug', $slug)->firstOrFail();
        if ($theme->pendingReview()) {
            $user = $request->user();
            abort_unless($user?->role === 'admin' && (!$request->session()->has(Auth::guard()->getName())
                || $request->session()->get('mailform_role_version') === (int) $user->role_version), 404);
        }
        $descriptor = $theme->assets[$path] ?? null;
        abort_unless(ThemeAssets::matchesPath($path, $descriptor), 404);
        $bytes = ThemeAssets::read($descriptor);
        abort_if($bytes === null, 404);
        if ($descriptor['mime'] === 'text/css') {
            $bytes .= ThemeCatalog::logoOverride($theme, $path);
        }

        return response($bytes, 200, ['Content-Type' => $descriptor['mime']]);
    }
}
