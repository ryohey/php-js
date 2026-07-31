# Project conventions

- All commit messages, documentation, code comments, and identifiers are written in English.
- Pushing directly to `main` is allowed.
- Read `DESIGN.md` before making architectural changes; the constraints in its §11
  (shared-nothing / opcache) are load-bearing and cannot be retrofitted:
  - Function templates (bytecode) must be `var_export`-able plain arrays.
  - Never store PHP Closures, resources, or foreign PHP objects on the JS heap;
    native functions are referenced by string ID through `BuiltinRegistry`.
  - JS exceptions are in-VM control flow; PHP exceptions cross the native boundary only.
- Run `composer install` then `vendor/bin/phpunit` before pushing.
- The language target is growing beyond ES5.1; read DESIGN.md §2.5 before adding
  syntax. Two rules hold regardless: a feature arrives with its semantics intact
  or is refused with a message naming it (a silently wrong answer is worse than a
  refusal), and `tests/acceptance/run.php` plus test262 both run before a syntax
  change is pushed.
