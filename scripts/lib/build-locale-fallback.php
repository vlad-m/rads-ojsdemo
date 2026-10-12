<?php

/**
 * Build English fallback translations for OJS interface languages.
 *
 * OJS shows "##some.key##" when a string is missing from a language. For each
 * requested locale this script collects every key that exists in English but
 * is missing (or empty) in that locale — across the core, lib/pkp and all
 * plugins — and writes them, with the English text, to
 *   OUT_DIR/<locale>/fallback.po
 * The locale-completion plugin registers OUT_DIR, so such strings appear in
 * English instead of as raw keys. Keys that already have a translation
 * (including the plugin's own Russian translations) are not touched.
 *
 * Usage: php build-locale-fallback.php OJS_DIR OUT_DIR locale [locale…]
 */

if (PHP_SAPI !== 'cli' || $argc < 4) {
    fwrite(STDERR, "Usage: php build-locale-fallback.php OJS_DIR OUT_DIR locale [locale...]\n");
    exit(1);
}
[, $ojsDir, $outDir] = $argv;
$locales = array_slice($argv, 3);
$ojsDir = rtrim(realpath($ojsDir), '/');

/** @return array<string, array{0: string, 1: string[]}> key => [value, raw msgstr lines] */
function readPo(string $file): array
{
    $entries = [];
    $lines = @file($file, FILE_IGNORE_NEW_LINES) ?: [];
    $count = count($lines);
    for ($i = 0; $i < $count; $i++) {
        if (!preg_match('/^msgid "(.*)"$/', $lines[$i], $m)) {
            continue;
        }
        $key = $m[1];
        $j = $i + 1;
        while ($j < $count && preg_match('/^"(.*)"$/', $lines[$j], $mm)) { // multi-line msgid
            $key .= $mm[1];
            $j++;
        }
        if ($j >= $count || !preg_match('/^msgstr "(.*)"$/', $lines[$j], $ms)) {
            continue;
        }
        $raw = [$lines[$j]];
        $value = $ms[1];
        $j++;
        while ($j < $count && preg_match('/^"(.*)"$/', $lines[$j], $mm)) {
            $raw[] = $lines[$j];
            $value .= $mm[1];
            $j++;
        }
        if ($key !== '') {
            $entries[$key] = [$value, $raw];
        }
        $i = $j - 1;
    }
    return $entries;
}

$roots = array_merge(
    glob("{$ojsDir}/locale", GLOB_ONLYDIR) ?: [],
    glob("{$ojsDir}/lib/pkp/locale", GLOB_ONLYDIR) ?: [],
    glob("{$ojsDir}/plugins/*/*/locale", GLOB_ONLYDIR) ?: [],
    glob("{$ojsDir}/lib/pkp/plugins/*/*/locale", GLOB_ONLYDIR) ?: []
);

// Everything translated in each locale (any file, any root) and all English strings
$english = [];
$translated = array_fill_keys($locales, []);
foreach ($roots as $root) {
    foreach (glob("{$root}/en/*.po") ?: [] as $file) {
        foreach (readPo($file) as $key => $entry) {
            if ($entry[0] !== '') {
                $english[$key] ??= $entry[1];
            }
        }
    }
    foreach ($locales as $locale) {
        foreach (glob("{$root}/{$locale}/*.po") ?: [] as $file) {
            foreach (readPo($file) as $key => $entry) {
                if ($entry[0] !== '') {
                    $translated[$locale][$key] = true;
                }
            }
        }
    }
}

foreach ($locales as $locale) {
    if ($locale === 'en') {
        continue;
    }
    $dir = "{$outDir}/{$locale}";
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        fwrite(STDERR, "Cannot create {$dir}\n");
        exit(1);
    }
    $out = "msgid \"\"\nmsgstr \"\"\n\"Content-Type: text/plain; charset=UTF-8\\n\"\n\"Language: {$locale}\\n\"\n\n";
    $n = 0;
    foreach ($english as $key => $raw) {
        if (isset($translated[$locale][$key])) {
            continue;
        }
        $out .= 'msgid "' . $key . "\"\n" . implode("\n", $raw) . "\n\n";
        $n++;
    }
    file_put_contents("{$dir}/fallback.po", $out);
    printf("  %-8s %5d strings fall back to English\n", $locale, $n);
}
