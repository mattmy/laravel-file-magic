# Security Policy

## Supported versions

Until FileMagic reaches a stable `1.0.0` release, security fixes are provided
for the latest published version only. After `1.0.0`, this table will list the
actively supported release lines.

## Reporting a vulnerability

Please report suspected vulnerabilities through
[GitHub private vulnerability reporting](https://github.com/mattmy/file-magic/security/advisories/new).
Do not include exploit details, credentials, private files, or sensitive paths
in a public issue.

Include:

- the affected FileMagic version and Laravel/PHP versions;
- reproduction steps or a minimal proof of concept;
- the security impact;
- any known workaround.

You should receive an initial response within seven days. A fix and disclosure
timeline will be coordinated privately after the report is confirmed.

## Scope

Reports involving path traversal, unsafe file handling, MIME confusion,
unauthorized disclosure, destructive deletion, or dependency vulnerabilities
are in scope. Application authorization mistakes and insecure Filesystem
credentials outside FileMagic are normally the host application's
responsibility.
