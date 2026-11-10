<?php
/**
 * Avatar helpers: use these in the dashboard and any other module so the
 * profile picture looks the same everywhere.
 *
 * Usage (after require_once __DIR__ . '/../includes/avatar.php'):
 *   echo avatar_html($row['full_name'], $row['profile_picture'], 48);
 */

if (!function_exists('avatar_url')) {
    /** Public URL of a profile picture file name, or '' if missing. */
    function avatar_url(?string $file): string
    {
        $file = basename((string) $file);
        if ($file === '') {
            return '';
        }
        $path = __DIR__ . '/../uploads/profiles/' . $file;
        if (!is_file($path)) {
            return '';
        }
        return '/homefor_thetander/uploads/profiles/'
            . rawurlencode($file) . '?v=' . (int) @filemtime($path);
    }
}

if (!function_exists('avatar_initials')) {
    function avatar_initials(string $name): string
    {
        $out = '';
        foreach (array_slice(preg_split('/\s+/', trim($name)), 0, 2) as $part) {
            $out .= mb_strtoupper(mb_substr($part, 0, 1));
        }
        return $out !== '' ? $out : 'U';
    }
}

if (!function_exists('avatar_html')) {
    /** Round avatar: the picture if there is one, otherwise the initials. */
    function avatar_html(string $name, ?string $file = null, int $size = 40): string
    {
        $url   = avatar_url($file);
        $style = 'display:inline-flex;align-items:center;justify-content:center;'
            . "width:{$size}px;height:{$size}px;border-radius:50%;overflow:hidden;"
            . 'background:#1b4a3a;color:#fff;font-weight:700;flex-shrink:0;'
            . 'font-size:' . round($size * 0.38) . 'px;';

        if ($url !== '') {
            return '<span style="' . $style . '"><img src="'
                . htmlspecialchars($url, ENT_QUOTES, 'UTF-8')
                . '" alt="" style="width:100%;height:100%;object-fit:cover;"></span>';
        }
        return '<span style="' . $style . '">'
            . htmlspecialchars(avatar_initials($name), ENT_QUOTES, 'UTF-8')
            . '</span>';
    }
}