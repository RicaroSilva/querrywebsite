<?php
declare(strict_types=1);

namespace App\Core;

/** Built-in stroke icon set (Lucide-style, MIT-compatible paths drawn for this app). */
final class Icons
{
    private const PATHS = [
        'dashboard'  => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
        'database'   => '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v6c0 1.7 3.6 3 8 3s8-1.3 8-3V5"/><path d="M4 11v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/>',
        'terminal'   => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="m7 9 3 3-3 3"/><path d="M13 15h4"/>',
        'file-code'  => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="m10 13-2 2 2 2"/><path d="m14 13 2 2-2 2"/>',
        'star'       => '<path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1 6.2L12 17.3 6.5 20.2l1-6.2L3 9.6l6.2-.9z"/>',
        'folder'     => '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
        'chart'      => '<path d="M3 3v18h18"/><rect x="7" y="12" width="3" height="6" rx=".5"/><rect x="12" y="8" width="3" height="10" rx=".5"/><rect x="17" y="5" width="3" height="13" rx=".5"/>',
        'history'    => '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l3 2"/>',
        'settings'   => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
        'play'       => '<path d="M7 4.5v15l12-7.5z"/>',
        'play-sel'   => '<path d="M5 5v14l8-7z"/><path d="M16 6h4M16 12h4M16 18h4"/>',
        'stop'       => '<rect x="6" y="6" width="12" height="12" rx="2"/>',
        'save'       => '<path d="M5 3h11l5 5v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M7 3v5h8V3"/><rect x="7" y="13" width="10" height="8" rx="1"/>',
        'plus'       => '<path d="M12 5v14M5 12h14"/>',
        'x'          => '<path d="M18 6 6 18M6 6l12 12"/>',
        'search'     => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
        'download'   => '<path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/>',
        'copy'       => '<rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v1"/>',
        'trash'      => '<path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>',
        'edit'       => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
        'table'      => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 10h18M9 10v10M15 10v10"/>',
        'eye'        => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        'function'   => '<path d="M9 20c2 0 2.5-1.5 3-4l2-10c.5-2.5 1-4 3-4"/><path d="M7 10h9"/>',
        'layers'     => '<path d="m12 3 9 5-9 5-9-5z"/><path d="m3 13 9 5 9-5"/>',
        'key'        => '<circle cx="8" cy="15" r="4"/><path d="m11 12 9-9M17 6l3 3M15 8l2 2"/>',
        'columns'    => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M12 3v18"/>',
        'chevron'    => '<path d="m9 6 6 6-6 6"/>',
        'chevron-down'=> '<path d="m6 9 6 6 6-6"/>',
        'refresh'    => '<path d="M21 12a9 9 0 0 1-15.5 6.3L3 16"/><path d="M3 12A9 9 0 0 1 18.5 5.7L21 8"/><path d="M21 3v5h-5M3 21v-5h5"/>',
        'logout'     => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
        'sun'        => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
        'moon'       => '<path d="M21 12.8A9 9 0 1 1 11.2 3 7 7 0 0 0 21 12.8z"/>',
        'wand'       => '<path d="m15 4 5 5L9 20l-5-5z"/><path d="M13 6l5 5"/><path d="M4 4l1 2 2 1-2 1-1 2-1-2-2-1 2-1zM20 15l.7 1.3 1.3.7-1.3.7L20 19l-.7-1.3L18 17l1.3-.7z"/>',
        'plug'       => '<path d="M9 2v6M15 2v6"/><path d="M6 8h12v4a6 6 0 0 1-12 0z"/><path d="M12 18v4"/>',
        'check'      => '<path d="m5 12 5 5L20 7"/>',
        'alert'      => '<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/>',
        'clock'      => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'rows'       => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 9h18M3 14h18"/>',
        'users'      => '<circle cx="9" cy="8" r="4"/><path d="M2 21a7 7 0 0 1 14 0"/><path d="M16 4a4 4 0 0 1 0 8M22 21a7 7 0 0 0-4-6.3"/>',
        'shield'     => '<path d="M12 3 4 6v6c0 5 3.5 8 8 9 4.5-1 8-4 8-9V6z"/>',
        'menu'       => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'type'       => '<path d="M4 7V5h16v2M9 19h6M12 5v14"/>',
        'pie'        => '<path d="M21 12A9 9 0 1 1 12 3v9z"/><path d="M15 3.5A9 9 0 0 1 20.5 9H15z"/>',
        'line'       => '<path d="M3 3v18h18"/><path d="m7 15 4-5 3 3 5-7"/>',
        'kpi'        => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M7 15v-3M11 15V9M15 15v-5"/>',
        'filter'     => '<path d="M3 5h18l-7 8v6l-4 2v-8z"/>',
        'link'       => '<path d="M10 14a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1 1"/><path d="M14 10a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1-1"/>',
        'list'       => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
        'zap'        => '<path d="M13 2 4 14h7l-1 8 9-12h-7z"/>',
        'grip'       => '<circle cx="9" cy="6" r="1"/><circle cx="15" cy="6" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="9" cy="18" r="1"/><circle cx="15" cy="18" r="1"/>',
        'info'       => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>',
        'external'   => '<path d="M14 4h6v6"/><path d="M20 4 10 14"/><path d="M19 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1h5"/>',
        'up'         => '<path d="m6 15 6-6 6 6"/>',
        'down'       => '<path d="m6 9 6 6 6-6"/>',
    ];

    public static function svg(string $name, string $class = ''): string
    {
        $paths = self::PATHS[$name] ?? self::PATHS['info'];
        return '<svg class="icon ' . htmlspecialchars($class, ENT_QUOTES) . '" viewBox="0 0 24 24" fill="none" '
            . 'stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . $paths . '</svg>';
    }

    public static function all(): array
    {
        return self::PATHS;
    }
}
