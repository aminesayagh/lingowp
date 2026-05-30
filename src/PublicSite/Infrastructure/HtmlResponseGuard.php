<?php

namespace TransLangs\PublicSite\Infrastructure;

/**
 * Decides whether a captured output buffer is an HTML document worth parsing.
 *
 * Output buffering can capture things that are technically text but are not
 * HTML pages (a JSON response, an XML document, an empty body). Running those
 * through an HTML translator would corrupt them, so we gate on a few cheap,
 * allocation-free checks before any expensive parsing happens.
 */
class HtmlResponseGuard
{
    /**
     * Maximum document size we are willing to parse, in bytes.
     * Very large pages are skipped to protect memory and response time.
     */
    private const MAX_BYTES = 5_000_000; // 5 MB

    public function isTranslatableHtml(string $html, ?string $contentType = null): bool
    {
        // Empty or whitespace-only output: nothing to do.
        if (trim($html) === '') {
            return false;
        }

        // Oversized: bail rather than risk memory/time blowups.
        if (strlen($html) > self::MAX_BYTES) {
            return false;
        }

        // If a Content-Type header was emitted, trust it: only translate HTML.
        if ($contentType !== null && $contentType !== '') {
            $type = strtolower($contentType);

            if (strpos($type, 'text/html') === false && strpos($type, 'application/xhtml') === false) {
                return false;
            }
        }

        // Sniff the first non-whitespace bytes. A real page starts with a
        // doctype or an HTML tag; JSON/XML declarations do not.
        $head = ltrim(substr($html, 0, 512));
        $head = strtolower($head);

        if ($head === '') {
            return false;
        }

        // Reject obvious non-HTML payloads.
        if ($head[0] === '{' || $head[0] === '[') {
            return false; // JSON
        }

        if (strpos($head, '<?xml') === 0) {
            return false; // XML/feed/sitemap
        }

        // Accept documents that look like HTML.
        $looksHtml = strpos($head, '<!doctype html') === 0
            || strpos($head, '<html') !== false
            || strpos($head, '<head') !== false
            || strpos($head, '<body') !== false;

        return $looksHtml;
    }

    /**
     * Read the response Content-Type from emitted headers, if available.
     */
    public function detectContentType(): ?string
    {
        if (!function_exists('headers_list')) {
            return null;
        }

        foreach (headers_list() as $header) {
            if (stripos($header, 'content-type:') === 0) {
                return trim(substr($header, strlen('content-type:')));
            }
        }

        return null;
    }
}