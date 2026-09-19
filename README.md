# FTP Deployment Remote Ignore

A small compatibility extension for [`dg/ftp-deployment`](https://github.com/dg/ftp-deployment) that adds **remote-only ignore state**.

It was created for deployments where some server-side files must intentionally stay different from the local tree — for example, when multiple developers use different `vendor/` contents or dependency versions on the same target.

## The problem

`dg/ftp-deployment` stores hashes of previously deployed files in a remote deployment-state file. A normal `ignore` rule removes a path from the local scan, but an older entry may still exist in the remote state. With deletion enabled, the deployer can then consider that path obsolete and remove it from the server.

This extension moves selected entries out of the normal deployment state before the regular deployment runs. The removed entries are preserved in a sidecar state file, so they can later be restored or re-evaluated.

```text
remote deployment state
        |
        |  apply ignoreRemote masks
        v
+-------------------+       +--------------------------+
| active entries    |       | remote-ignored entries   |
| .deployment       |       | .deployment.ignore.remote|
+-------------------+       +--------------------------+
        |
        v
regular dg/ftp-deployment
```

## Example

```ini
[production]
remote = ftp://user:password@example.com/public_html
local = .

; The path must also be excluded from the normal local deployment scan.
ignore = "
    /vendor
    /deployment.*
"

; Keep the existing server-side vendor state outside the normal
; deployment-state file so it is not considered obsolete.
ignoreRemote = "
    /vendor
"

allowDelete = yes
deploymentFile = .deployment
```

Prepare the remote state:

```bash
php bin/deployment.php deployment.ini --ignore-remote
```

Then run the normal deployment through the same wrapper:

```bash
php bin/deployment.php deployment.ini
```

When no remote-ignore command is present, the wrapper delegates directly to the original `Deployment\\CliRunner`.

## Commands

```text
--ignore-remote
--unignore-remote
--backup-remote-deployment-file
--restore-remote-deployment-file
--delete-backup-remote-deployment-file
```

`--ignore-remote` creates a backup of the original remote deployment state (if one does not already exist), merges previously ignored entries back into the working set, applies the current `ignoreRemote` masks, and writes the active and ignored sets separately.

`--unignore-remote` merges all sidecar entries back into the normal deployment state.

The backup/restore commands operate on the deployment-state file only; they do not copy application files.

## Installation

The project requires:

- PHP 7.1.26 or newer
- `ext-zlib`
- `dg/ftp-deployment` 3.x (`^3.3`)

For a standalone checkout:

```bash
composer install
php bin/deployment.php deployment.ini --ignore-remote
```

The loader also supports several common legacy/standalone layouts used by older `dg/ftp-deployment` installations. This keeps the utility usable when the upstream tool is present as source code rather than as a conventional Composer dependency.

## Compatibility notes

The extension intentionally uses a small Reflection-based bridge because the upstream runner/deployer keeps the required configuration and state private. Reflection is isolated in `CliRunnerBridge`; the remote-ignore logic itself does not depend on Reflection directly.

The deployment-state reader accepts both formats used by the tested 3.x releases:

- raw DEFLATE used by older releases
- gzip used by newer releases

State files written by this extension use raw DEFLATE for backward compatibility.

The refactored version was smoke-tested against the supplied `dg/ftp-deployment` 3.3-era and 3.6-era source trees using their real `Deployer`, `RetryServer`, `FileServer`, `Logger` and mask-matching classes. The state transition was verified with a deployment state containing `vendor/` entries: `--ignore-remote` moved those entries to the sidecar file, preserved the backup, and left only active entries in the normal state file.

## Design trade-offs

This is intentionally a small compatibility layer rather than a fork of `dg/ftp-deployment`.

- It reuses the upstream mask matcher and deployer/server implementations.
- It keeps the original deployment-state format.
- It isolates access to upstream private internals in one bridge class.
- It keeps a separate sidecar state instead of modifying application files or replacing the deployer's synchronization algorithm.

Because it depends on private upstream internals, future `dg/ftp-deployment` versions can require compatibility changes.

## Author

Jan Kuželka — https://kuzelka.dev

## License

BSD-3-Clause. See [`LICENSE`](LICENSE).

Portions of the deployment-state handling are adapted from `dg/ftp-deployment`, Copyright (c) 2009 David Grudl, and retain the upstream BSD-3-Clause attribution.
