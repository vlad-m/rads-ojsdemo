<?php

/**
 * Set "key = value" inside a [section] of an INI file (OJS config.inc.php),
 * keeping comments and layout. An active (uncommented) key is replaced; if
 * only a commented example exists, the new line is inserted right after it;
 * otherwise it is appended at the end of the section (the section is created
 * when missing). A backup is written once per run as FILE.bak-YYYYmmddHHMMSS.
 *
 * Usage: php ini-set.php FILE SECTION KEY VALUE
 *   VALUE is written verbatim (quote it yourself if needed: '"text"').
 */

if (PHP_SAPI !== 'cli' || $argc !== 5) {
    fwrite(STDERR, "Usage: php ini-set.php FILE SECTION KEY VALUE\n");
    exit(1);
}
[, $file, $section, $key, $value] = $argv;

$lines = file($file, FILE_IGNORE_NEW_LINES);
if ($lines === false) {
    fwrite(STDERR, "Cannot read {$file}\n");
    exit(1);
}

$newLine = "{$key} = {$value}";
$keyRe = '/^\s*' . preg_quote($key, '/') . '\s*=/';
$commentedRe = '/^\s*;\s*' . preg_quote($key, '/') . '\s*=/';

$inSection = false;
$sectionStart = $sectionEnd = $active = $commented = null;
foreach ($lines as $i => $line) {
    if (preg_match('/^\s*\[([^\]]+)\]/', $line, $m)) {
        if ($inSection) {
            $sectionEnd = $i;
            break;
        }
        if (trim($m[1]) === $section) {
            $inSection = true;
            $sectionStart = $i;
        }
        continue;
    }
    if ($inSection) {
        if ($active === null && preg_match($keyRe, $line)) {
            $active = $i;
        } elseif ($commented === null && preg_match($commentedRe, $line)) {
            $commented = $i;
        }
    }
}
if ($inSection && $sectionEnd === null) {
    $sectionEnd = count($lines);
}

if ($active !== null) {
    if (trim($lines[$active]) === $newLine) {
        exit(0); // unchanged
    }
    $lines[$active] = $newLine;
} elseif ($commented !== null) {
    array_splice($lines, $commented + 1, 0, [$newLine]);
} elseif ($sectionStart !== null) {
    // insert before trailing blank lines of the section
    $pos = $sectionEnd;
    while ($pos > $sectionStart + 1 && trim($lines[$pos - 1]) === '') {
        $pos--;
    }
    array_splice($lines, $pos, 0, [$newLine]);
} else {
    $lines[] = '';
    $lines[] = "[{$section}]";
    $lines[] = $newLine;
}

$backup = $file . '.bak-' . date('YmdHis');
if (!file_exists($backup)) {
    copy($file, $backup);
    chmod($backup, 0600);
}
file_put_contents($file, implode("\n", $lines) . "\n");
echo "  {$section}.{$key} = {$value}\n";
