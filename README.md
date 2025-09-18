## Cull
![PHP](https://img.shields.io/badge/PHP-777BB4?style=for-the-badge&logo=php&logoColor=white)
![GitHub License](https://img.shields.io/github/license/nickwelsh/cull?style=for-the-badge)
![GitHub Release](https://img.shields.io/github/v/release/nickwelsh/cull?style=for-the-badge)
![Packagist Version](https://img.shields.io/packagist/v/nickwelsh/cull?style=for-the-badge)

Quickly find and purge `vendor` and `node_modules` directories from your projects to free up disk space.

### Install (global)

```bash
composer global require nickwelsh/cull
```

Ensure Composer's global bin dir is on your PATH:

```bash
export PATH="$(composer global config bin-dir --absolute 2>/dev/null):$PATH"
```

### Usage

From any directory:

```bash
cull
```

Cull will recursively scan the current working directory, list all `vendor` and `node_modules` folders it finds, and let you select which ones to delete.

You can skip file size calculations by passing the `--no-size` flag.

```bash
cull --no-size
```

### Notes

- Works on macOS, Linux, and Windows (via PHP). Requires PHP 8.1+.
