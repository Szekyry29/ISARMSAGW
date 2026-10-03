# Findly PHP + MySQL backend

1. Copy this folder to `C:\xampp\htdocs\findly-api`.
2. Start Apache and MySQL in XAMPP.
3. Open phpMyAdmin and import `schema.sql`.
4. For a local demo only, run `FINDLY_ALLOW_DEMO_SEED=1 php seed.php` from this folder. The public endpoint is blocked and the script refuses to run without explicit opt-in.
5. Set `FINDLY_DB_HOST`, `FINDLY_DB_PORT`, `FINDLY_DB_NAME`, `FINDLY_DB_USER`, and `FINDLY_DB_PASS` in the server environment. A local-only `config.local.php` can be used for XAMPP; never commit it.

The Vue app uses `http://localhost/findly-api/api.php`. Passwords use PHP `password_hash`, sessions are stored as SHA-256 token hashes in MySQL, and all protected requests require a bearer token.

Seeded user account: `juan@findly.local` / `juan12345`. The password is hashed by PHP before it is inserted into MySQL; the plaintext value is never stored in the database.
