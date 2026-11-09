<?php

namespace App\Support;

use App\Models\SystemSetting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * The institution's logo and browser icon. An administrator uploads one image in Settings: it is resized for the menu and
 * the sign-in page, and a square icon is made from it. Both are kept in storage and served by the application itself
 * (/brand/logo, /brand/favicon), so nothing depends on a public folder or a storage link. A logo can also be an address
 * or a file inside public/ (the sample institution uses one); the browser icon then falls back to that image. Without
 * any, the application's own logo (public/logo.png) is the logo and the browser icon. Printed documents carry only the
 * institution's own logo.
 */
final class Branding
{
    public const LOGO = 'branding/logo.png';

    public const FAVICON = 'branding/favicon.png';

    private const DISK = 'local';

    /** The application's own logo, inside public/: the logo and the browser icon until the institution sets its own. */
    public const DEFAULT = 'logo.png';

    /** Who implemented the system: shown as a credit on the sign-in page and in the menu, unless Settings names another. */
    public const IMPLEMENTER = 'Unidade para o Digital';

    /** The address of the logo: the institution's, else the application's own; null when there is neither. */
    public static function logoUrl(): ?string
    {
        $logo = SystemSetting::get('organization_logo');
        if (! $logo) {
            return self::defaultUrl();
        }
        if ($logo === self::LOGO) {
            return route('brand.logo', ['v' => self::version()], false);
        }

        return str_starts_with($logo, 'http') ? $logo : asset(ltrim($logo, '/'));
    }

    /** The browser icon: the generated one, else the logo itself, else the application's default. */
    public static function faviconUrl(): string
    {
        if (SystemSetting::get('organization_favicon') === self::FAVICON) {
            return route('brand.favicon', ['v' => self::version()], false);
        }

        return self::logoUrl() ?? asset('favicon.ico');
    }

    /** Has the institution set its own logo (rather than showing the application's)? */
    public static function hasOwnLogo(): bool
    {
        return (bool) SystemSetting::get('organization_logo');
    }

    /** The application's own logo, when the file is there. */
    public static function defaultUrl(): ?string
    {
        return is_file(public_path(self::DEFAULT)) ? asset(self::DEFAULT) : null;
    }

    /**
     * The logo as a data: address, for printed documents (the PDF renderer does not fetch pictures from the application's own
     * addresses). Null when there is no logo, or when it is only an address on another site. Kept in the cache until the logo
     * changes; the reports of a year are printed many times and the picture is read from disk once.
     */
    public static function logoDataUri(): ?string
    {
        return StructureCache::remember('logo-data-uri', 3600, function () {
            $path = self::logoPath();
            if ($path === null) {
                return null;
            }
            $mime = mime_content_type($path) ?: 'image/png';

            return 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($path));
        });
    }

    /**
     * The file of the institution's own logo, for documents (the PDF reports, the Excel workbooks). Null when there is none,
     * or when it is only an address on another site; outside public/ and the logo store, nothing is read.
     */
    public static function logoPath(): ?string
    {
        $logo = (string) SystemSetting::get('organization_logo');
        if ($logo === '' || str_starts_with($logo, 'http')) {
            return null;
        }
        $path = $logo === self::LOGO ? self::file('logo') : realpath(public_path(ltrim($logo, '/')));
        if (! $path || ! is_file($path)) {
            return null;
        }
        if ($logo !== self::LOGO && ! str_starts_with($path, realpath(public_path()).DIRECTORY_SEPARATOR)) {
            return null; // a setting such as "../.env" never reaches a document
        }

        return $path;
    }

    public static function version(): string
    {
        return (string) SystemSetting::get('organization_logo_version', '1');
    }

    public static function file(string $which): ?string
    {
        $path = $which === 'favicon' ? self::FAVICON : self::LOGO;

        return Storage::disk(self::DISK)->exists($path) ? Storage::disk(self::DISK)->path($path) : null;
    }

    /** Keep an uploaded image as the logo, and make the browser icon from it. */
    public static function store(UploadedFile $upload): void
    {
        $image = @imagecreatefromstring((string) file_get_contents($upload->getRealPath()));
        if (! $image) {
            throw new RuntimeException(__('The image could not be read.'));
        }
        imagealphablending($image, false);
        imagesavealpha($image, true);

        $logo = self::fit($image, 640, 640);
        $icon = self::square($image, 64);
        $disk = Storage::disk(self::DISK);
        $disk->put(self::LOGO, self::png($logo));
        $disk->put(self::FAVICON, self::png($icon));

        SystemSetting::set('organization_logo', self::LOGO, 'string', 'Path to organization logo file');
        SystemSetting::set('organization_favicon', self::FAVICON, 'string', 'Browser icon made from the logo');
        SystemSetting::set('organization_logo_version', (string) time(), 'string', 'Changes when the logo is replaced, so that browsers fetch the new one');
        StructureCache::bump();
    }

    /** Take the logo away: the application goes back to its own logo and icon. */
    public static function clear(): void
    {
        Storage::disk(self::DISK)->delete([self::LOGO, self::FAVICON]);
        SystemSetting::set('organization_logo', null, 'string');
        SystemSetting::set('organization_favicon', null, 'string');
        SystemSetting::set('organization_logo_version', (string) time(), 'string');
        StructureCache::bump();
    }

    /** @return \GdImage a copy no larger than the box, in proportion, with transparency kept */
    private static function fit(\GdImage $src, int $maxW, int $maxH): \GdImage
    {
        [$w, $h] = [imagesx($src), imagesy($src)];
        $scale = min(1, $maxW / $w, $maxH / $h);
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $dst = self::canvas($nw, $nh);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

        return $dst;
    }

    /** @return \GdImage the whole image inside a transparent square */
    private static function square(\GdImage $src, int $size): \GdImage
    {
        [$w, $h] = [imagesx($src), imagesy($src)];
        $scale = $size / max($w, $h);
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $dst = self::canvas($size, $size);
        imagecopyresampled($dst, $src, intdiv($size - $nw, 2), intdiv($size - $nh, 2), 0, 0, $nw, $nh, $w, $h);

        return $dst;
    }

    private static function canvas(int $w, int $h): \GdImage
    {
        $img = imagecreatetruecolor($w, $h);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));

        return $img;
    }

    private static function png(\GdImage $img): string
    {
        ob_start();
        imagepng($img, null, 9);

        return (string) ob_get_clean();
    }
}
