# ExamplePress MU

A self-updating WordPress MU plugin that auto-installs from GitHub releases.

## Structure

```
mu-plugins/
├── examplepress-mu.php          # Thin loader (sits at mu-plugins root)
└── examplepress-mu/             # Application directory
    └── bootstrap.php            # Platform kernel entry point
```

## How It Works

1. WordPress automatically loads `examplepress-mu.php` from the `mu-plugins/` root.
2. The loader checks for `examplepress-mu/bootstrap.php`.
3. If missing, it fetches the latest release from GitHub, extracts it, and places the files.
4. The kernel is then executed via `require_once`.

## Installation

### Option A: Auto-Install (Loader Only)

Drop just `examplepress-mu.php` into `wp-content/mu-plugins/`. On first load, it will download and extract the full application from the latest GitHub release.

### Option B: Manual Install

Download the latest release `.zip`, extract it into `wp-content/mu-plugins/`. The structure maps directly — no extra steps required.

## Development

Clone the repo and work inside the `examplepress-mu/` directory. The thin loader at the root and the application directory are both versioned together.

```bash
git clone https://github.com/webmultipliers/examplepress-mu.git
```
