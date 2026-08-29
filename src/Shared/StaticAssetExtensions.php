<?php

namespace LingoWP\Shared;

/**
 * File extensions that are never a translatable HTML page — the single list
 * RequestContext (should this request be translated at all) and
 * PublicLinkRewriter (should this href be rewritten) both check against, so
 * the two can never silently disagree on what counts as a static asset.
 */
final class StaticAssetExtensions
{
    /** @var array<string, true> */
    public const LIST = [
        'avif'  => true,
        'bmp'   => true,
        'css'   => true,
        'eot'   => true,
        'gif'   => true,
        'gz'    => true,
        'ico'   => true,
        'jpeg'  => true,
        'jpg'   => true,
        'js'    => true,
        'json'  => true,
        'map'   => true,
        'mjs'   => true,
        'mp3'   => true,
        'mp4'   => true,
        'ogg'   => true,
        'otf'   => true,
        'pdf'   => true,
        'png'   => true,
        'svg'   => true,
        'ttf'   => true,
        'txt'   => true,
        'wav'   => true,
        'webm'  => true,
        'webp'  => true,
        'woff'  => true,
        'woff2' => true,
        'xml'   => true,
        'zip'   => true,
    ];
}
