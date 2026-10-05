# MASIX IT Support

MASIX IT Support is a lightweight service desk for companies, schools, nonprofits, public institutions, and other teams. It runs on PHP and a private, file-based JSON store; it does not require a database or third-party account.

## Get started

1. Serve this directory with PHP 8.1 or newer. For a local preview, run `php -S 127.0.0.1:8000 -t . router.php` from the project directory and visit `http://127.0.0.1:8000/`. The included router also blocks direct requests for JSON data files during local preview.
2. On a new installation, MASIX opens the setup page. Enter your organization name and create its first super-administrator account with a strong password.
3. Sign in and open **People** to create administrator and regular-user accounts. There is no public self-registration.

The PHP process must be able to write to `data/`. The first installation creates `data/users.json`, `data/settings.json`, `data/tickets.json`, and `data/audit.json` as needed. Those runtime records are excluded from Git. For deployments that can store data outside the web root, set `MASIX_DATA_DIR` to a private, absolute, PHP-writable directory. Existing tickets in the original root-level `tickets.json` are imported into the private data directory during first-run setup; the original file is retained as a protected migration source.

## Roles

| Role | Requests | People and audit |
| --- | --- | --- |
| Regular user | Create and track their own requests, reply to the service team, and confirm or reopen a resolution | Manage their own profile and password |
| Administrator | Triage and assign organization requests, set status and priority, and add requester-visible replies or private team notes | Create regular users; review the organization audit trail |
| Super administrator | All administrator capabilities and service oversight | Create administrators and regular users; suspend or restore eligible accounts |

Access is checked on the server on every request. Accounts and tickets are scoped to the organization created by the installer. Deploy a separate installation for each organization.

## Service-desk features

- Dedicated dashboards for requesters, service administrators, and super administrators
- Request categories, departments, location and asset references, search, status/priority filters, and paginated queues
- Three priorities: **Low**, **Normal**, and **High**. New requests receive response targets of 72, 24, and 4 hours respectively.
- Assignment to an active teammate, requester conversations, and administrator-only internal notes
- CSV request export for administrators, with spreadsheet-formula injection protection
- A timestamped audit trail for sign-ins, request changes, replies, exports, and account administration
- Password hashing, session ID rotation, CSRF tokens, sign-in throttling, output escaping, role checks, and file locking
- First-sign-in password changes, administrator-issued one-time temporary password resets, and profile security settings
- Account suspension preserves related request and audit history
- Protected runtime JSON files using Apache `.htaccess` and IIS request filtering

## Deployment and backups

Use a production web server configured for HTTPS; the PHP built-in server is for local preview only. Keep the data directory writable by PHP and inaccessible over HTTP, restrict operating-system access to it, and back it up regularly over a secure channel. Back up all four JSON stores as a unit so organizations, accounts, requests, and audit history remain consistent. Do not commit data-store files or share administrator passwords through an unprotected channel.

The app uses organization-provided account credentials and does not send email or reset links. Accounts must change their initial password at first sign-in; administrators can issue one-time temporary resets, which must be shared securely. Production installations should also use a supported PHP release, security updates, HTTPS, and server-level backup/monitoring.
