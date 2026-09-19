# reqsheet.com deployment procedure

This is an administrator procedure. The application agent must not change DNS,
Apache, TLS, or protected files under `/etc`.

## Application configuration

Add these non-secret values to the protected runtime environment file, keeping
the existing database and authentication values unchanged:

```text
REQSHEET_BASE_HOSTS=reqsheet.com,reqsheet.duckdns.org
REQSHEET_CANONICAL_HOST=reqsheet.com
```

`REQSHEET_BASE_HOST` remains supported for single-domain/local deployments.
The same persistent tenant slug then resolves under both
`<slug>.reqsheet.com` and `<slug>.reqsheet.duckdns.org`; no database migration
or organisation-data rewrite is required. Do not set a cookie domain spanning
both registrable domains.

## DNS and TLS

The DNS administrator must provide `A`/`AAAA` records for `reqsheet.com`, a
wildcard `*.reqsheet.com` record, and `www.reqsheet.com` pointing to the Pumba
service (or an equivalent provider-supported arrangement). They must confirm
which authoritative DNS provider hosts the zone and whether it supports
automated DNS-01 challenges.

Issue a new certificate containing both `reqsheet.com` and `*.reqsheet.com`.
Keep the existing DuckDNS certificates in place. The wildcard requires
DNS-01; use the provider's official Certbot DNS plugin or an acme.sh DNS API
integration. Keep API credentials in the provider-approved protected location,
never in this repository or Apache configuration.

Inspect the issued certificate SANs and renewal configuration before use. Do
not reload Apache until the new key and certificate paths are readable by the
configured service and cover both the bare and wildcard names.

## Apache sequence

1. Leave the existing DuckDNS HTTP/HTTPS vhosts and certificate paths
   unchanged.
   The inspected DuckDNS HTTP vhost currently redirects using
   `%{SERVER_NAME}`. Test an HTTP request for a tenant before deployment; if
   Apache collapses it to the bare DuckDNS host, replace only that redirect
   with explicit allowlisted rules preserving the tenant label, for example:

   ```apache
   RewriteCond %{HTTP_HOST} ^([^.]+)\.reqsheet\.duckdns\.org$ [NC]
   RewriteRule ^ https://%1.reqsheet.duckdns.org%{REQUEST_URI} [END,NE,R=permanent]
   RewriteRule ^ https://reqsheet.duckdns.org%{REQUEST_URI} [END,NE,R=permanent]
   ```

   This is an administrator-only correction to preserve existing staging
   tenant URLs; do not replace the DuckDNS certificate or vhost.
2. Add an HTTP vhost for `reqsheet.com`, `www.reqsheet.com`, and
   `*.reqsheet.com`, copying the existing Reqsheet document root, PHP-FPM, and
   `FallbackResource /index.php` directives. Redirect `www.reqsheet.com` to
   the fixed URL `https://reqsheet.com%{REQUEST_URI}`; redirect the bare and
   tenant names to their corresponding HTTPS host without using an arbitrary
   Host header as a redirect target.
3. Add an HTTPS application vhost with `ServerName reqsheet.com` and
   `ServerAlias *.reqsheet.com`, using the new certificate, for example:

   ```apache
   SSLCertificateFile /path/to/reqsheet-com-fullchain.pem
   SSLCertificateKeyFile /path/to/reqsheet-com-privkey.pem
   ```

4. Add an HTTPS redirect vhost for `www.reqsheet.com` with the same certificate
   and fixed redirect to `https://reqsheet.com%{REQUEST_URI}`.
5. Validate Apache configuration, reload it, and verify SNI and redirects
   before changing DNS traffic.

The repository examples are `docs/apache/reqsheet-com-http.conf.example` and
`docs/apache/reqsheet-com-ssl.conf.example`. An administrator can install them
with commands of this form, after replacing the certificate placeholders and
reviewing the PHP-FPM socket against the live host:

```sh
sudo install -m 0644 docs/apache/reqsheet-com-http.conf.example /etc/apache2/sites-available/reqsheet-com.conf
sudo install -m 0644 docs/apache/reqsheet-com-ssl.conf.example /etc/apache2/sites-available/reqsheet-com-ssl.conf
sudo a2enmod rewrite ssl proxy_fcgi
sudo a2ensite reqsheet-com.conf reqsheet-com-ssl.conf
sudo apachectl configtest
sudo systemctl reload apache2
```

Before the reload, update the protected runtime file with the two
non-secret domain settings, then confirm the process receives the file path
and not repository configuration. These commands intentionally do not issue a
certificate or alter DNS. The existing DuckDNS site links must remain enabled.

The exact certificate paths depend on the selected ACME client. Do not assume
the current Certbot renewal file or acme.sh DNS provider can issue the new
certificate until the authoritative DNS provider is confirmed.

## Verification order

```sh
curl -I https://reqsheet.com/health
curl -I https://www.reqsheet.com/health
curl -I https://reqsheet.duckdns.org/health
curl -I https://tenant.reqsheet.com/login
curl -I https://tenant.reqsheet.duckdns.org/login
```

Also test unknown tenants on both domains, an unrelated Host header, and the
signup handoff from `https://reqsheet.com/signup`. Confirm the handoff target
uses `*.reqsheet.com`, then confirm the original DuckDNS tenant still resolves.
