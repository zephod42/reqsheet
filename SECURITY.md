# Security

Security is a design requirement from the beginning. This bootstrap has no authentication, user data, database connection, or product endpoints.

Future implementation must at minimum address:

- password and session handling through reviewed, conventional mechanisms;
- authorization checks on every protected action;
- server-side validation and safe output escaping;
- PDO prepared statements for database access;
- CSRF protection for state-changing forms;
- secure cookie and transport settings in deployment;
- least-privilege database credentials and safe secret management;
- audit logging appropriate to requisition and approval changes.

Never commit credentials, `.env` files, logs, runtime data, or production configuration to this repository. Host-level configuration remains the administrator's responsibility.
