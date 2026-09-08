<?php
/**
 * dead-code-finder.php
 *
 * Scan a PHP project to find functions/methods/classes/interfaces/traits/
 * enums that are defined but never appear to be called or used anywhere
 * in the project.
 *
 * Usage:
 *   php dead-code-finder.php <project-path> [options]
 *
 * Options:
 *   --exclude=dir1,dir2     Extra folders to skip (relative to the project path).
 *                           Default: vendor,node_modules,.git,storage,cache,build,dist
 *   --no-methods            Don't check methods inside classes, only global functions & classes.
 *   --no-classes            Don't check classes/interfaces/traits/enums, only functions.
 *   --json                  Output as JSON instead of plain text.
 *   --min-len=N             Skip symbol names shorter than N characters (default 1, no skip).
 *   --help                  Show this help.
 *
 * How it works (summary):
 *   1. Scan every *.php file under the project path (skipping excluded folders).
 *   2. Tokenize each file with PHP's built-in token_get_all() (not regex),
 *      recording every definition: global functions, methods inside
 *      classes, and classes/interfaces/traits/enums.
 *   3. Re-tokenize the whole project to count how many times each name is
 *      used elsewhere (function calls, instantiation, extends, implements,
 *      type hints, etc.), INCLUDING references inside string literals (to
 *      catch dynamic calls such as call_user_func('name') or
 *      Class::class-style string references).
 *   4. Any symbol whose usage count is zero outside its own definition is
 *      reported as "possibly dead code".
 *
 * IMPORTANT - THIS IS A HEURISTIC, NOT A PERFECT ANALYSIS:
 *   - Magic methods (__construct, __toString, etc.) are always skipped
 *     since they are called automatically by the PHP engine, not by an
 *     explicit name.
 *   - Methods that implement an interface/abstract/parent class can be
 *     false positives (flagged as dead even though they're called
 *     polymorphically through a parent/interface reference).
 *   - Truly dynamic calls (variable functions/methods, reflection,
 *     dependency injection containers, or a route file that stores the
 *     name as a string different from the actual identifier) can slip
 *     past detection even with the string-literal check.
 *   - Always review manually before deleting anything reported here.
 */

error_reporting(E_ALL & ~E_DEPRECATED);

// Real-world projects can easily contain tens of thousands of tokens per
// file across thousands of files. Bump the memory limit for this run
// (unless it's already unlimited) so scanning a large codebase doesn't hit
// PHP's default 128M CLI limit and die with "Allowed memory size exhausted".
$currentMemLimit = ini_get('memory_limit');
if ($currentMemLimit !== '-1') {
    $currentBytes = dcf_parse_size($currentMemLimit);
    if ($currentBytes !== null && $currentBytes < 1024 * 1024 * 1024) {
        @ini_set('memory_limit', '1024M');
    }
}

function dcf_parse_size(string $val): ?int
{
    $val = trim($val);
    if ($val === '') {
        return null;
    }
    $unit = strtolower(substr($val, -1));
    $num = (int) $val;
    return match ($unit) {
        'g' => $num * 1024 * 1024 * 1024,
        'm' => $num * 1024 * 1024,
        'k' => $num * 1024,
        default => (int) $val,
    };
}

function dcf_usage_and_exit(int $code = 0): void
{
    global $argv;
    $script = basename($argv[0]);
    echo <<<TXT
dead-code-finder.php - find PHP functions/methods/classes that appear to be unused

Usage:
  php {$script} <project-path> [options]

Options:
  --exclude=dir1,dir2   Extra folders to skip (relative to the project path).
                        Default: vendor,node_modules,.git,storage,cache,build,dist
  --no-methods          Don't check methods inside classes.
  --no-classes          Don't check classes/interfaces/traits/enums.
  --json                Output as JSON.
  --min-len=N           Skip symbol names shorter than N characters.
  --help                Show this help.

Examples:
  php {$script} .
  php {$script} /path/to/project --exclude=vendor,tests --json

TXT;
    exit($code);
}

// ---------- Parse CLI arguments ----------

$args = $argv;
array_shift($args);

if (empty($args) || in_array('--help', $args, true) || in_array('-h', $args, true)) {
    dcf_usage_and_exit(empty($args) ? 1 : 0);
}

$projectPath = null;
$excludeDirs = ['vendor', 'node_modules', '.git', 'storage', 'cache', 'build', 'dist'];
$checkMethods = true;
$checkClasses = true;
$jsonOutput = false;
$minLen = 1;

foreach ($args as $arg) {
    if ($arg === '--no-methods') {
        $checkMethods = false;
    } elseif ($arg === '--no-classes') {
        $checkClasses = false;
    } elseif ($arg === '--json') {
        $jsonOutput = true;
    } elseif (str_starts_with($arg, '--exclude=')) {
        $extra = substr($arg, strlen('--exclude='));
        foreach (explode(',', $extra) as $d) {
            $d = trim($d);
            if ($d !== '') {
                $excludeDirs[] = $d;
            }
        }
    } elseif (str_starts_with($arg, '--min-len=')) {
        $minLen = max(1, (int) substr($arg, strlen('--min-len=')));
    } elseif (str_starts_with($arg, '-')) {
        fwrite(STDERR, "Unknown option: {$arg}\n");
        dcf_usage_and_exit(1);
    } else {
        $projectPath = $arg;
    }
}

if ($projectPath === null) {
    fwrite(STDERR, "Project path is missing.\n\n");
    dcf_usage_and_exit(1);
}

$projectPath = rtrim($projectPath, '/\\');
if (!is_dir($projectPath)) {
    fwrite(STDERR, "Not a valid folder: {$projectPath}\n");
    exit(1);
}
$projectPathReal = realpath($projectPath);

// List of magic methods that are always skipped (called automatically by PHP)
$MAGIC_METHODS = [
    '__construct', '__destruct', '__call', '__callstatic', '__get', '__set',
    '__isset', '__unset', '__sleep', '__wakeup', '__serialize', '__unserialize',
    '__tostring', '__invoke', '__set_state', '__clone', '__debuginfo', '__autoload',
];

// ---------- 1. Collect the list of .php files ----------

function dcf_collect_php_files(string $root, array $excludeDirs): array
{
    // Deliberately NOT using RecursiveDirectoryIterator here: on Windows it
    // can throw "UnexpectedValueException: ...The directory name is invalid"
    // when it misreports an ordinary file (often one with a cloud-sync /
    // reparse-point attribute, e.g. a OneDrive-synced favicon.ico) as having
    // children. A plain scandir()-based walk avoids that SPL bug entirely
    // and behaves the same on Windows, macOS, and Linux.
    $files = [];
    $excludeSet = array_fill_keys($excludeDirs, true);
    $stack = [rtrim($root, '/\\')];

    while (!empty($stack)) {
        $dir = array_pop($stack);
        $entries = @scandir($dir);
        if ($entries === false) {
            continue;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (isset($excludeSet[$entry])) {
                continue; // skip excluded folder (or file) by name, at any depth
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;

            if (is_link($path)) {
                // Skip symlinks/junctions to avoid infinite loops and other
                // platform-specific surprises.
                continue;
            }
            if (is_dir($path)) {
                $stack[] = $path;
            } elseif (is_file($path) && strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'php') {
                $files[] = $path;
            }
        }
    }

    sort($files);
    return $files;
}

$phpFiles = dcf_collect_php_files($projectPathReal, $excludeDirs);

if (empty($phpFiles)) {
    fwrite(STDERR, "No .php files found in {$projectPath}\n");
    exit(1);
}

// ---------- 2. Tokenize every file & record definitions ----------

/**
 * Get the index of the next significant token (skipping whitespace & comments).
 */
function dcf_next_significant(array $tokens, int $i, int $count): int
{
    $i++;
    while ($i < $count) {
        $t = $tokens[$i];
        if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            $i++;
            continue;
        }
        break;
    }
    return $i;
}

// Usage counts are aggregated into hash maps (keyed by lowercased name) as
// we go, one file at a time, instead of keeping every file's full token
// array in memory and later re-scanning all of them for every single
// definition. That old O(definitions x total_tokens) approach is what blew
// up memory/time on real-world projects with thousands of files; this is
// O(total_tokens) overall.
$definitions = [];        // list of ['kind'=>, 'name'=>, 'class'=>, 'file'=>, 'line'=>]
$identUsageCount = [];    // lowercased name => count of matching T_STRING tokens (any context)
$methodUsageCount = [];   // lowercased name => count of matching T_STRING tokens preceded by -> or ::
$literalUsageCount = [];  // lowercased name => count of matching string-literal contents

foreach ($phpFiles as $file) {
    $code = @file_get_contents($file);
    if ($code === false) {
        continue;
    }
    $tokens = @token_get_all($code);
    $count = count($tokens);

    // Indices (within THIS file's token array) of tokens that are a
    // definition's own name, e.g. the "Foo" in "class Foo" or "bar" in
    // "function bar()". These must not be counted as a usage of themselves.
    $skipIndices = [];
    $braceDepth = 0;
    $classStack = []; // each: ['depth'=>int, 'name'=>?string]

    for ($i = 0; $i < $count; $i++) {
        $t = $tokens[$i];

        if ($t === '{') {
            $braceDepth++;
            continue;
        }
        if ($t === '}') {
            if (!empty($classStack) && end($classStack)['depth'] === $braceDepth) {
                array_pop($classStack);
            }
            $braceDepth--;
            continue;
        }
        if (!is_array($t)) {
            continue;
        }

        [$id, $text, $line] = $t;

        // --- class / interface / trait / enum ---
        if ($id === T_CLASS || $id === T_INTERFACE || $id === T_TRAIT
            || (defined('T_ENUM') && $id === T_ENUM)) {

            // "ClassName::class" magic constant -> not a definition, skip.
            $prevSig = null;
            for ($p = $i - 1; $p >= 0; $p--) {
                $pt = $tokens[$p];
                if (is_array($pt) && in_array($pt[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $prevSig = $pt;
                break;
            }
            if (is_array($prevSig) && $prevSig[0] === T_DOUBLE_COLON) {
                continue; // ::class
            }

            $ni = dcf_next_significant($tokens, $i, $count);
            $name = null;
            if ($ni < $count && is_array($tokens[$ni]) && $tokens[$ni][0] === T_STRING) {
                $name = $tokens[$ni][1];
            }
            // find the '{' that opens the class body (skip past extends/implements/etc.)
            $j = $i;
            $depthBeforeBody = $braceDepth;
            while ($j < $count && $tokens[$j] !== '{' && $tokens[$j] !== ';') {
                $j++;
            }
            if ($j < $count && $tokens[$j] === '{') {
                // class body found; push onto classStack with the depth it
                // will have AFTER that '{' is processed by the main loop
                // (we're just pre-registering it here; the '{' handler
                // above will do the actual braceDepth increment).
                $classStack[] = ['depth' => $depthBeforeBody + 1, 'name' => $name];

                if ($checkClasses && $name !== null) {
                    $skipIndices[$ni] = true;
                    $definitions[] = [
                        'kind' => strtolower(is_array($tokens[$i]) ? token_name($id) : ''),
                        'label' => match (true) {
                            $id === T_CLASS => 'class',
                            $id === T_INTERFACE => 'interface',
                            $id === T_TRAIT => 'trait',
                            default => 'enum',
                        },
                        'name' => $name,
                        'class' => null,
                        'file' => $file,
                        'line' => $line,
                    ];
                }
            }
            // continue the normal loop from $i (not $j), so the '{'/';'
            // token still gets processed by the brace handler above on the
            // next iteration as usual.
            continue;
        }

        // --- function / method ---
        if ($id === T_FUNCTION) {
            $ni = dcf_next_significant($tokens, $i, $count);
            // skip '&' (return by reference)
            if ($ni < $count && $tokens[$ni] === '&') {
                $ni = dcf_next_significant($tokens, $ni, $count);
            }
            if ($ni >= $count || !is_array($tokens[$ni]) || $tokens[$ni][0] !== T_STRING) {
                continue; // closure/anonymous function, not a named definition
            }
            $name = $tokens[$ni][1];

            $isMethod = !empty($classStack) && end($classStack)['depth'] === $braceDepth;
            $className = $isMethod ? end($classStack)['name'] : null;

            if ($isMethod && !$checkMethods) {
                continue;
            }
            if ($isMethod && $className === null) {
                continue; // inside an anonymous class, skip reporting
            }
            if ($isMethod && in_array(strtolower($name), $MAGIC_METHODS, true)) {
                continue;
            }

            $skipIndices[$ni] = true;
            $definitions[] = [
                'kind' => $isMethod ? 'method' : 'function',
                'label' => $isMethod ? 'method' : 'function',
                'name' => $name,
                'class' => $className,
                'file' => $file,
                'line' => $line,
            ];
        }
    }

    // Second pass over this file's tokens: aggregate usage counts into the
    // global maps, skipping the tokens identified above as definition
    // names. $tokens is discarded once this file is done, so peak memory
    // stays proportional to one file at a time, not the whole project.
    for ($i = 0; $i < $count; $i++) {
        $t = $tokens[$i];
        if (!is_array($t)) {
            continue;
        }
        [$id, $text] = $t;

        if ($id === T_STRING) {
            if (isset($skipIndices[$i])) {
                continue;
            }
            $key = strtolower($text);
            $identUsageCount[$key] = ($identUsageCount[$key] ?? 0) + 1;

            $p = $i - 1;
            while ($p >= 0 && is_array($tokens[$p]) && in_array($tokens[$p][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $p--;
            }
            $prev = $p >= 0 ? $tokens[$p] : null;
            if (is_array($prev) && in_array($prev[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
                $methodUsageCount[$key] = ($methodUsageCount[$key] ?? 0) + 1;
            }
        } elseif ($id === T_CONSTANT_ENCAPSED_STRING) {
            // reference via a string literal, e.g. 'functionName' inside
            // call_user_func('functionName') or an array callback ['Class','method']
            $inner = substr($text, 1, -1); // strip the surrounding quotes
            $key = strtolower($inner);
            $literalUsageCount[$key] = ($literalUsageCount[$key] ?? 0) + 1;
        }
    }
}

// ---------- 3. Decide which definitions have zero usages ----------
//
// For functions & classes: "used" means the name matched a T_STRING
// anywhere else in the project (any context), or matched a string literal
// exactly (to catch dynamic calls like call_user_func('name')).
//
// For methods: only T_STRING occurrences preceded by '->' or '::' count
// (anywhere in the project, regardless of which class - so polymorphic
// calls are still caught), plus string-literal references.

$deadFunctions = [];
$deadClasses = [];
$deadMethods = [];

foreach ($definitions as $def) {
    $key = strtolower($def['name']);
    if (strlen($def['name']) < $minLen) {
        continue; // treated as "used" so it isn't reported
    }

    if ($def['kind'] === 'method') {
        $count = ($methodUsageCount[$key] ?? 0) + ($literalUsageCount[$key] ?? 0);
    } else {
        $count = ($identUsageCount[$key] ?? 0) + ($literalUsageCount[$key] ?? 0);
    }

    if ($count === 0) {
        $relFile = ltrim(str_replace($projectPathReal, '', $def['file']), '/\\');
        $entry = [
            'label' => $def['label'],
            'name' => $def['name'],
            'class' => $def['class'],
            'file' => $relFile,
            'line' => $def['line'],
        ];
        if ($def['kind'] === 'method') {
            $deadMethods[] = $entry;
        } elseif ($def['kind'] === 'function') {
            $deadFunctions[] = $entry;
        } else {
            $deadClasses[] = $entry;
        }
    }
}

// ---------- 4. Output ----------

$summary = [
    'files_scanned' => count($phpFiles),
    'functions_defined' => count(array_filter($definitions, fn($d) => $d['kind'] === 'function')),
    'methods_defined' => count(array_filter($definitions, fn($d) => $d['kind'] === 'method')),
    'classes_defined' => count(array_filter($definitions, fn($d) => $d['kind'] !== 'function' && $d['kind'] !== 'method')),
    'dead_functions' => count($deadFunctions),
    'dead_classes' => count($deadClasses),
    'dead_methods' => count($deadMethods),
];

if ($jsonOutput) {
    echo json_encode([
        'summary' => $summary,
        'dead_functions' => $deadFunctions,
        'dead_classes' => $deadClasses,
        'dead_methods' => $deadMethods,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

function dcf_print_section(string $title, array $items): void
{
    echo "\n== {$title} ==\n";
    if (empty($items)) {
        echo "  (none)\n";
        return;
    }
    usort($items, fn($a, $b) => strcmp($a['file'], $b['file']) ?: $a['line'] <=> $b['line']);
    foreach ($items as $it) {
        $loc = "{$it['file']}:{$it['line']}";
        if ($it['class'] !== null) {
            printf("  %-55s %s::%s()\n", $loc, $it['class'], $it['name']);
        } elseif ($it['label'] === 'function') {
            printf("  %-55s function %s()\n", $loc, $it['name']);
        } else {
            printf("  %-55s %s %s\n", $loc, $it['label'], $it['name']);
        }
    }
}

echo "=== Dead Code Report: {$projectPath} ===\n";
echo "Files scanned       : {$summary['files_scanned']}\n";
echo "Global functions    : {$summary['functions_defined']}\n";
if ($checkMethods) {
    echo "Methods in classes  : {$summary['methods_defined']}\n";
}
if ($checkClasses) {
    echo "Classes/interfaces/traits/enums : {$summary['classes_defined']}\n";
}

dcf_print_section('Possibly unused global functions', $deadFunctions);
if ($checkClasses) {
    dcf_print_section('Possibly unused classes/interfaces/traits', $deadClasses);
}
if ($checkMethods) {
    dcf_print_section('Possibly unused methods (more prone to false positives)', $deadMethods);
}

echo "\nNote: this is a token-based heuristic, not a perfect analysis.\n";
echo "Magic methods (__construct, etc.) are always skipped. Methods that\n";
echo "override an interface/parent class, or are called purely via\n";
echo "reflection/DI container, may still show up here even though they're\n";
echo "actually used. Always review manually before deleting anything.\n";