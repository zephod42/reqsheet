# Reqsheet Monitor

Reqsheet Monitor is a read-only command-line report for operators. It creates one self-contained HTML file with embedded CSS. It has no web route, account, scheduler, JavaScript, external asset or network dependency. The report contains aggregate operational counts only; it never includes individual requisition text, password hashes, recovery data, sessions or database credentials.

## Create the reporting database identity

A MySQL administrator must create a separate identity on each host. Replace the example database, host restriction and password with deployment-specific values. Do not run this as the application runtime or migration user.

```sql
CREATE USER 'reqsheet_monitor'@'localhost' IDENTIFIED BY 'replace-with-a-strong-unique-password';
GRANT SELECT ON `reqsheet_dev`.* TO 'reqsheet_monitor'@'localhost';
```

For IONOS, grant access to the production Reqsheet database name instead of `reqsheet_dev`. Do not grant `INSERT`, `UPDATE`, `DELETE`, DDL, global privileges or `GRANT OPTION`. The monitor runs `SHOW GRANTS FOR CURRENT_USER` and refuses to generate a report unless every grant is `SELECT` or `USAGE`. Account creation and privilege changes are administrator work; the application and monitor do not perform them.

## Configure the protected environment file

Create a monitor-specific file outside the repository and outside Apache's document root. Start from `.env.report.example`, use the actual local database details, then restrict the file to its owner:

```sh
chmod 600 /home/OPERATOR/.config/reqsheet-monitor.env
```

The required keys are `REPORT_DB_HOST`, `REPORT_DB_PORT`, `REPORT_DB_NAME`, `REPORT_DB_USER`, `REPORT_DB_PASSWORD` and `REPORT_ENVIRONMENT`. `REPORT_TIMEZONE` is optional and defaults to `UTC`; use an IANA name such as `Europe/London`. `REQSHEET_DEPLOYED_COMMIT` may optionally contain a 7–64 character hexadecimal deployed commit when the deployment has no `.git` metadata.

The CLI must be told the single absolute configuration path through `REQSHEET_REPORT_ENV_FILE`. It does not search for environment files, does not execute their contents and does not use `DB_*` or `MIGRATION_DB_*`. The file must not be readable or writable by group or other users.

## Generate on Pumba or IONOS

Create an operator-owned destination outside `/var/www/reqsheet/public`, then generate the report over SSH:

```sh
mkdir -p /home/OPERATOR/reqsheet-reports
cd /var/www/reqsheet
REQSHEET_REPORT_ENV_FILE=/home/OPERATOR/.config/reqsheet-monitor.env \
  php bin/operator-report.php --output=/home/OPERATOR/reqsheet-reports/reqsheet-monitor.html
```

The destination directory must already exist and the output path must be absolute. Existing reports are preserved by default; pass `--force` only when deliberate replacement is wanted. Output is written through a private temporary file, moved into place only after a complete write, and left with mode `0600`. The tool refuses paths below this repository's `public/` directory.

The report timestamp includes the configured timezone and prominently warns that the file is a static snapshot. Registration and requisition creation/modification figures are shown only when their schema timestamps exist. Login activity is explicitly unavailable because Reqsheet does not store login timestamps.

## Retrieve from StudyPC

Run SCP on StudyPC, not on the server. For example:

```sh
scp OPERATOR@IONOS_HOST:/home/OPERATOR/reqsheet-reports/reqsheet-monitor.html ~/Downloads/
```

Open the downloaded file directly in a local Ubuntu or macOS browser. It does not need internet access. Treat the file as operational data: keep it out of shared web directories, send it only to authorised operators and remove stale copies according to the organisation's operational policy.

No production database changes, background jobs or privileged host changes are required for normal report generation. The only administrator actions are creating the restricted MySQL identity and securely provisioning its external configuration file.
