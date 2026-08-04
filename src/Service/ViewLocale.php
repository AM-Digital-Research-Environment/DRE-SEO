<?php
declare(strict_types=1);

namespace DRESeo\Service;

use Laminas\View\Renderer\PhpRenderer;

/**
 * Resolves the language of the page being rendered.
 *
 * `lang` and `siteSetting` are view *helpers*, invoked through PhpRenderer's
 * __call() — so `method_exists($view, 'lang')` is ALWAYS false and a naive guard
 * silently forces English. Resolve the helper through the plugin manager, never
 * through method_exists().
 *
 * Preference order: the active translator locale (which matches the translated
 * chrome), then the site's configured locale, then English.
 */
final class ViewLocale
{
    /** The page language as a BCP-47-ish code ("fr", "en-GB"); never empty. */
    public static function resolve(PhpRenderer $view): string
    {
        try {
            $helpers = $view->getHelperPluginManager();
            if ($helpers->has('lang')) {
                $lang = trim((string) $view->lang());
                if ($lang !== '') {
                    return $lang;
                }
            }
            if ($helpers->has('siteSetting')) {
                $lang = trim((string) ($view->siteSetting('locale') ?? ''));
                if ($lang !== '') {
                    return $lang;
                }
            }
        } catch (\Throwable $e) {
            // fall through to the default
        }
        return 'en';
    }

    /**
     * The page language narrowed to a locale {@see CitationFormatter} knows.
     * The formatter carries EN and FR tables, so anything not French formats in
     * English.
     */
    public static function forCitation(PhpRenderer $view): string
    {
        return str_starts_with(strtolower(self::resolve($view)), 'fr') ? 'fr' : 'en';
    }
}
