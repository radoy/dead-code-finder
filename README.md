# dead-code-finder

A standalone PHP script that finds functions, methods, classes, interfaces, traits, and enums defined in a project that don't appear to be called or used anywhere.

Just needs the PHP CLI. No dependencies, no Composer required.

Installation

Just download the single file — nothing to install:
curl -O https://raw.githubusercontent.com/radoy/dead-code-finder/main/dead-code-finder.php

Or clone this repo.
 
## Usage
 
```bash
php dead-code-finder.php <project-path> [options]
```
 
Examples:
 
```bash
# Scan the project in the current folder
php dead-code-finder.php .
 
# Scan another project, exclude extra folders, output JSON
php dead-code-finder.php /path/to/project --exclude=vendor,tests --json
 
# Only check global functions & classes, skip methods (faster, less noise)
php dead-code-finder.php . --no-methods
```
 
### Options
 
| Option | Description |
|---|---|
| `--exclude=dir1,dir2` | Extra folders to skip (relative to the project path). Default: `vendor,node_modules,.git,storage,cache,build,dist` |
| `--no-methods` | Don't check methods inside classes, only global functions & classes. |
| `--no-classes` | Don't check classes/interfaces/traits/enums, only functions. |
| `--json` | Output as JSON instead of plain text. |
| `--min-len=N` | Skip symbol names shorter than N characters. |
| `--help` | Show help. |
 
### Example Output
 
```
=== Dead Code Report: /path/to/project ===
Files scanned       : 42
Global functions    : 6
Methods in classes  : 118
Classes/interfaces/traits/enums : 15
 
== Possibly unused global functions ==
  src/Helpers/legacy.php:12    function formatOldPrice()
 
== Possibly unused classes/interfaces/traits ==
  src/Services/DeprecatedMailer.php:5    class DeprecatedMailer
 
== Possibly unused methods (more prone to false positives) ==
  src/Models/User.php:88    User::unusedScope()
```
 
## How It Works
 
1. Scans every `*.php` file under the project path (skipping excluded folders).
2. Tokenizes each file with PHP's built-in `token_get_all()` (not regex),
   recording every definition: global functions, methods inside classes, and
   classes/interfaces/traits/enums.
3. Re-tokenizes the whole project to count how many times each name is used
   elsewhere — function calls, instantiation, `extends`, `implements`, type
   hints, and so on — including references inside string literals, to catch
   dynamic calls like `call_user_func('name')` or array callbacks
   `[$obj, 'method']`.
4. Any symbol whose usage count is zero outside its own definition is
   reported as "possibly dead code".
## ⚠️ Limitations (Read Before Deleting Anything)
 
This is a **heuristic, token-based analysis**, not a full static analyzer.
Always review manually before removing anything it reports:
 
- **Magic methods** (`__construct`, `__toString`, `__get`, etc.) are always
  skipped since PHP calls them automatically.
- **Methods that override an interface/abstract/parent class** can be false
  positives — flagged as dead even though they're called polymorphically
  through a parent or interface reference.
- **Truly dynamic calls** — variable functions/methods, reflection,
  dependency injection containers, or names assembled from separate string
  fragments — can slip past detection even with the string-literal check.
- The tool has no semantic understanding of autoloading, framework routing,
  or any service container — it purely matches symbol names against other
  occurrences of the same name across the project.
## Requirements
 
- PHP CLI 7.4+ (uses the `T_ENUM` constant for enum detection, which is
  automatically skipped on PHP versions below 8.1).
## License
 
MIT