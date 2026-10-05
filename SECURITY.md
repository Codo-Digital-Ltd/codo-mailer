# Security policy

## Reporting a vulnerability

Please report security issues privately to **security@cododigital.co.uk**, or through GitHub's "Report a vulnerability" button on this repository. Do not open a public issue.

We aim to acknowledge reports within two UK business days and to ship a fix for confirmed high or critical issues within seven days. We credit reporters in the changelog unless asked not to.

## Supported versions

Only the latest release receives security fixes. WordPress.org offers updates to every site automatically.

## Design notes for researchers

- The email log is rendered only on `Settings > Codo Mailer` for users with `manage_options`. There are no REST or AJAX endpoints.
- All admin form handlers check `manage_options` and a nonce; the resend nonce is bound to the log entry ID.
- Password-reset and one-time-token links are redacted before logging.
- Provider secrets are encrypted at rest with libsodium `crypto_secretbox`, keyed from the site salts or `CODO_MAILER_ENCRYPTION_KEY`.
