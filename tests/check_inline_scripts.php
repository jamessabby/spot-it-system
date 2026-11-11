<?php
/**
 * Regression check: every inline <script> in a page must be closed before the
 * next <script> opens.
 *
 *   C:\xampp\php\php.exe tests/check_inline_scripts.php
 *
 * Why: pages/dashboard-admin.php lost a </script> (since at least commit
 * 2ab5707). The browser then parsed the following HTML comment as JavaScript,
 * threw a SyntaxError and discarded the whole block — every dashboard function
 * (event log tabs, event pop-up, staff actions) was silently undefined. PHP lint
 * cannot see this, so this checks the page source structurally. It reads files
 * only; no database or session is used.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$pages = glob(__DIR__ . '/../pages/*.php');
$problems = 0;
foreach ($pages as $file) {
    $src = file_get_contents($file);
    // Drop PHP blocks so a "<script" inside PHP strings cannot confuse the scan.
    $html = preg_replace('/<\?php.*?(\?>|$)/s', '', $src);
    $depth = 0;
    $line = 1;
    $issues = [];
    if (preg_match_all('#<(/?)script\b[^>]*>#i', $html, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[0] as $i => [$tag, $offset]) {
            $isClose = $m[1][$i][0] === '/';
            $line = substr_count($html, "\n", 0, $offset) + 1;
            $selfContained = !$isClose && preg_match('#\bsrc\s*=#i', $tag);
            if ($isClose) {
                $depth--;
                if ($depth < 0) { $issues[] = "stray </script> near line {$line}"; $depth = 0; }
            } else {
                if ($depth > 0) { $issues[] = "<script> opened near line {$line} while a previous one is still open"; }
                $depth++;
            }
        }
    }
    if ($depth > 0) $issues[] = 'a <script> is never closed';
    $name = basename($file);
    if ($issues) {
        $problems++;
        echo "  FAIL  {$name}: " . implode('; ', $issues) . " (line numbers are approximate: PHP blocks removed)\n";
    }
}
echo count($pages) . " pages checked, {$problems} with unclosed or nested <script> tags\n";
exit($problems ? 1 : 0);
