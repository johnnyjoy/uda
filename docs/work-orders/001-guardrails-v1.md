# Work Order 001 — Purpose Checker Hardening

## Goal
Make the purpose check **reliable** and **aligned** with UDA’s header rules by:

- Replacing the brittle regex with a **header-window scan**
- Scanning only the **first 40-60 lines** of each file
- Requiring `Purpose:` to appear **near the top** (before namespace)
- Producing **clear, human-readable errors** with filename + line number

## Acceptance Criteria

- ✅ **Passes on current repo** after headers are normalized
- ✅ **Fails on fixture files** that violate the rules
- ✅ **Error messages** are clean and actionable
- ✅ **CI enforces** the new checker via `composer check`

## Tests
The enhanced checker must handle:

```php
// ✅ Valid: PHPDoc-contained Purpose
/**
 * @package UDA
 * Purpose: Example description
 */

// ✅ Valid: Standalone multiline comment Purpose
/**
 * @package UDA
 */

/*
 * Purpose: Example description
 */

// ❌ Invalid: Missing Purpose

// ❌ Invalid: Purpose too far down (>60 lines)

// ❌ Invalid: Purpose appears after namespace (if rule enforced)
```

## Files

```
tools/check-purpose.php          # Enhanced implementation
composer.json                    # Update if needed

tests/Tools/CheckPurposeTest.php # Test fixtures + assertions
```

## Evidence

```bash
hadolint:
  all files contain Purpose within line 60
  ✅ if valid
  ❌ provides line numbers on failure
```