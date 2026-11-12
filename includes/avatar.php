
<?php
/**
 * Avatar helpers for admin and caretaker accounts.
 * Profile pictures are stored in uploads/profiles/.
 */

if (!function_exists('avatar_url')) {
    function avatar_url(?string $file): string
    {
        $file = basename(trim((string) $file));

        if ($file === '') {
            return '';
        }

        // Actual image location
        $path = __DIR__ . '/../uploads/profiles/' . $file;

        // Check if the image exists
        if (!is_file($path)) {
            return '';
        }

        // Browser-accessible image URL
        return '/homefor_thetander/uploads/profiles/'
            . rawurlencode($file)
            . '?v=' . (int) filemtime($path);
    }
}

if (!function_exists('avatar_initials')) {
    function avatar_initials(string $name): string
    {
        $initials = '';

        $parts = preg_split('/\s+/', trim($name));

        foreach (array_slice($parts, 0, 2) as $part) {
            if ($part !== '') {
                $initials .= mb_strtoupper(
                    mb_substr($part, 0, 1, 'UTF-8'),
                    'UTF-8'
                );
            }
        }

        return $initials !== '' ? $initials : 'U';
    }
}

if (!function_exists('avatar_html')) {
    function avatar_html(
        string $name,
        ?string $file = null,
        int $size = 40
    ): string {

        $size = max(20, min($size, 300));
        $url = avatar_url($file);

        $style = 'display:inline-flex;'
            . 'align-items:center;'
            . 'justify-content:center;'
            . "width:{$size}px;"
            . "height:{$size}px;"
            . 'border-radius:50%;'
            . 'overflow:hidden;'
            . 'background:#1b4a3a;'
            . 'color:#fff;'
            . 'font-weight:700;'
            . 'flex-shrink:0;'
            . 'font-size:' . round($size * 0.38) . 'px;';

        if ($url !== '') {
            return '<span style="' . $style . '">'
                . '<img src="'
                . htmlspecialchars($url, ENT_QUOTES, 'UTF-8')
                . '" alt="Profile picture" '
                . 'style="width:100%;height:100%;object-fit:cover;">'
                . '</span>';
        }

        return '<span style="' . $style . '">'
            . htmlspecialchars(
                avatar_initials($name),
                ENT_QUOTES,
                'UTF-8'
            )
            . '</span>';
    }
}
