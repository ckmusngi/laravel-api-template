# Laravel API Template

A starter template for building JSON APIs with Laravel 13, [Laravel Sanctum](https://laravel.com/docs/sanctum) token authentication, and [spatie/laravel-permission](https://spatie.be/docs/laravel-permission) roles.

It includes:

- Register, login, and logout (current device or all devices)
- Forgot and reset password by email
- Optional email verification
- Role-based access control (admin and user roles)
- Token expiration and revocation
- A users API with pagination, search, filtering, sorting, and soft deletes
- Versioned routes under `/api/v1` with a consistent JSON response shape
- Rate limiting, CORS, and centralized error handling
- Unit and feature tests, Pint formatting, Larastan static analysis, and GitHub Actions CI

## Requirements

- PHP 8.4.1+
- Composer
- MySQL 8+
- Node.js and npm (only used by the `setup` script)

## Getting started

1. Clone the repository and install dependencies:

   ```bash
   git clone <your-repo-url> my-api
   cd my-api
   composer install
   ```

2. Create your environment file and app key:

   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

3. Create the database, then update the `DB_*` values in `.env` if your credentials differ:

   ```bash
   mysql -uroot -e "CREATE DATABASE laravel_api_template CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
   ```

4. Run the migrations with seed data and start the server:

   ```bash
   php artisan migrate --seed
   php artisan serve
   ```

The API is now available at `http://localhost:8000/api/v1`.

The seeders create the roles and permissions plus these accounts (password `password`):

| Email | Role |
| --- | --- |
| `admin@example.com` | admin |
| `test@example.com` | user |

Ten more random users with the `user` role are also created. Change or remove the seeded accounts before deploying.

> Roles and permissions must exist in every environment. In production, run `php artisan db:seed --class=RolesAndPermissionsSeeder` instead of the full seeder.

## Configuration

All secrets and environment-specific values live in `.env`, which is never committed. `.env.example` lists every variable.

| Variable | Purpose | Default |
| --- | --- | --- |
| `APP_URL` | URL of this API | `http://localhost:8000` |
| `FRONTEND_URL` | URL of your client app, used in password reset links | `http://localhost:3000` |
| `AUTH_REQUIRE_EMAIL_VERIFICATION` | Require users to verify their email | `false` |
| `AUTH_VERIFICATION_EXPIRE` | Minutes a verification link stays valid | `60` |
| `SANCTUM_TOKEN_EXPIRATION` | Token lifetime in minutes; empty means tokens never expire | `10080` (7 days) |
| `SANCTUM_TOKEN_PREFIX` | Prefix added to tokens so secret scanners can detect leaks | empty |
| `CORS_ALLOWED_ORIGINS` | Comma-separated origins allowed to call the API | `FRONTEND_URL` |
| `SESSION_SECURE_COOKIE` | Send cookies over HTTPS only; set `true` in production | `false` |
| `DB_*` | MySQL connection | `127.0.0.1:3306`, database `laravel_api_template`, user `root` |
| `MAIL_*` | Mail delivery | [Mailtrap](https://mailtrap.io) sandbox SMTP |

After changing `.env`, run `php artisan config:clear`.

### Mail (Mailtrap)

Password reset and verification emails are sent through [Mailtrap](https://mailtrap.io)'s sandbox, which catches every email in a test inbox instead of delivering it. To set it up:

1. Sign in to Mailtrap and open **Email Testing → Inboxes**, then choose your inbox.
2. Under **SMTP Settings → Integrations**, select **Laravel** and copy the username and password.
3. Add them to `.env`:

   ```dotenv
   MAIL_MAILER=smtp
   MAIL_HOST=sandbox.smtp.mailtrap.io
   MAIL_PORT=2525
   MAIL_USERNAME=your-mailtrap-username
   MAIL_PASSWORD=your-mailtrap-password
   ```

4. Run `php artisan config:clear`, then call `POST /api/v1/auth/forgot-password`. The email appears in your Mailtrap inbox.

For production, switch the `MAIL_*` values to a sending provider (for example Mailtrap Email Sending, Mailgun, or Resend).

## Authentication

Login and register return a Sanctum token. Send it on every protected request:

```http
Authorization: Bearer <token>
Accept: application/json
```

- Each login creates a separate token, so a user can be signed in on several devices at once. The optional `device_name` field labels the token.
- Tokens expire after `SANCTUM_TOKEN_EXPIRATION` minutes. The login response includes `expires_at`.
- Tokens are revoked on logout, logout of all devices, password reset, and account deletion.
- An expired or revoked token receives `401 Unauthorized`.
- Expired tokens are removed from the database daily by the scheduler (`sanctum:prune-expired`). Make sure `php artisan schedule:run` runs every minute in production.
- Passwords are hashed with bcrypt through the `hashed` cast on the `User` model.

### Email verification

Email verification is off by default. Set `AUTH_REQUIRE_EMAIL_VERIFICATION=true` to turn it on. When it is on:

- A verification email is sent when a user registers or changes their email address.
- Unverified users get `403` on the users endpoints. They can still use `/auth/me`, `/auth/logout`, and the resend endpoint.
- The emailed link points to `GET /api/v1/auth/email/verify/{id}/{hash}`. It is signed and expires after `AUTH_VERIFICATION_EXPIRE` minutes, so it works without a token.

When verification is off, no verification emails are sent and the resend endpoint returns `404`.

## Roles and permissions

Roles and permissions are defined as enums in `app/Enums/Role.php` and `app/Enums/Permission.php`, and stored with spatie/laravel-permission.

| Role | Permissions |
| --- | --- |
| `admin` | `users.view`, `users.create`, `users.update`, `users.delete`, `roles.assign` |
| `user` | none (can only view, update, and delete their own account) |

New users get the `user` role. The rules are enforced in `app/Policies/UserPolicy.php`.

To add a role or permission:

1. Add a case to the enum (and list the permissions in `Role::permissions()`).
2. Run `php artisan db:seed --class=RolesAndPermissionsSeeder`. The seeder is safe to run repeatedly.
3. Check the permission in a policy with `$user->checkPermissionTo(Permission::YourPermission)`.

## Endpoints

All paths are prefixed with `/api/v1`.

### Auth

| Method | Path | Auth | Description |
| --- | --- | --- | --- |
| POST | `/auth/register` | — | Create an account and receive a token |
| POST | `/auth/login` | — | Receive a token |
| POST | `/auth/forgot-password` | — | Email a password reset link |
| POST | `/auth/reset-password` | — | Set a new password using the emailed token |
| GET | `/auth/email/verify/{id}/{hash}` | Signed link | Verify an email address |
| GET | `/auth/me` | Token | Get the authenticated user |
| POST | `/auth/logout` | Token | Revoke the current token |
| POST | `/auth/logout-all` | Token | Revoke every token (logs out all devices) |
| POST | `/auth/email/verification-notification` | Token | Resend the verification email |

### Users

| Method | Path | Who can call it | Description |
| --- | --- | --- | --- |
| GET | `/users` | Admin | Paginated list of users |
| POST | `/users` | Admin | Create a user |
| GET | `/users/{id}` | Admin, or the user themselves | Get a user |
| PUT/PATCH | `/users/{id}` | Admin, or the user themselves | Update a user |
| DELETE | `/users/{id}` | Admin, or the user themselves | Soft delete a user and revoke their tokens |
| PUT | `/users/{id}/roles` | Admin (not on their own account) | Replace a user's roles |

### Listing users

`GET /users` accepts these query parameters:

| Parameter | Example | Description |
| --- | --- | --- |
| `search` | `smith` | Matches name or email |
| `role` | `admin` | Only users with this role |
| `sort` | `-created_at` | `name`, `email`, or `created_at`; prefix with `-` for descending. Default `-created_at` |
| `per_page` | `25` | 1 to 100. Default 15 |
| `page` | `2` | Page number |

### Soft deletes

Deleting a user sets `deleted_at` instead of removing the row. Deleted users cannot log in and are hidden from every endpoint. Their email stays reserved, so it cannot be registered again.

## Response format

Successful responses wrap their payload in `data`:

```json
{
  "data": {
    "id": 1,
    "name": "Jane Doe",
    "email": "jane@example.com",
    "email_verified_at": null,
    "roles": ["user"],
    "created_at": "2026-10-11T09:00:00.000000Z",
    "updated_at": "2026-10-11T09:00:00.000000Z"
  }
}
```

Lists add `links` and `meta` for pagination. Actions without a resource return `{"message": "..."}`. `DELETE` and logout return `204 No Content`.

Errors always return a `message`. Validation errors also return an `errors` object keyed by field:

```json
{
  "message": "The email field is required.",
  "errors": {
    "email": ["The email field is required."]
  }
}
```

| Status | Meaning |
| --- | --- |
| `200` / `201` / `204` | Success / created / no content |
| `401` | Missing, invalid, expired, or revoked token |
| `403` | Not allowed (missing permission, someone else's account, or unverified email) |
| `404` | Resource not found |
| `422` | Validation failed |
| `429` | Too many requests |
| `500` | Server error (details are hidden when `APP_DEBUG=false`) |

Exception handling is configured in `bootstrap/app.php`. API routes always return JSON, and 404 responses never reveal internal model names.

## Examples

### Register

```bash
curl -X POST http://localhost:8000/api/v1/auth/register \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"name":"Jane Doe","email":"jane@example.com","password":"secret-password","password_confirmation":"secret-password"}'
```

```json
{
  "data": {
    "token": "1|abc123...",
    "token_type": "Bearer",
    "expires_at": "2026-10-18T09:00:00+00:00",
    "user": {
      "id": 1,
      "name": "Jane Doe",
      "email": "jane@example.com",
      "email_verified_at": null,
      "roles": ["user"],
      "created_at": "2026-10-11T09:00:00.000000Z",
      "updated_at": "2026-10-11T09:00:00.000000Z"
    }
  }
}
```

### Login

```bash
curl -X POST http://localhost:8000/api/v1/auth/login \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@example.com","password":"password","device_name":"iphone"}'
```

### List users (admin)

```bash
curl "http://localhost:8000/api/v1/users?search=smith&role=user&sort=name&per_page=25" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer 1|abc123..."
```

### Update your profile

Changing `password` also requires `current_password`. Changing `email` marks the email as unverified.

```bash
curl -X PATCH http://localhost:8000/api/v1/users/1 \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer 1|abc123..." \
  -d '{"name":"Jane Smith"}'
```

### Make a user an admin

```bash
curl -X PUT http://localhost:8000/api/v1/users/2/roles \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer 1|abc123..." \
  -d '{"roles":["admin"]}'
```

## Password reset flow

1. The client sends the user's email to `POST /auth/forgot-password`. The response is the same whether or not the account exists, so the endpoint cannot be used to discover registered emails.
2. The user receives an email linking to `FRONTEND_URL/reset-password?token=...&email=...`. The link expires after 60 minutes.
3. Your frontend reads `token` and `email` from the URL and sends them with the new password:

   ```bash
   curl -X POST http://localhost:8000/api/v1/auth/reset-password \
     -H "Accept: application/json" \
     -H "Content-Type: application/json" \
     -d '{"token":"<token>","email":"jane@example.com","password":"new-password","password_confirmation":"new-password"}'
   ```

4. The password is changed and all of the user's tokens are revoked, so they must log in again.

## Security

- **Secrets** are read from `.env` through config files. Never commit `.env`.
- **Rate limiting**: every API route allows 60 requests per minute per user (or per IP for guests). `register`, `login`, `forgot-password`, and `reset-password` also allow only 5 per minute per email and IP. Verification endpoints allow 6 per minute.
- **CORS** only allows origins listed in `CORS_ALLOWED_ORIGINS` (default `FRONTEND_URL`). See `config/cors.php`.
- **Input validation** happens in form requests (`app/Http/Requests/Api/V1`). Controllers only use validated data, and models restrict mass-assignable fields.
- **Output** goes through API resources (`app/Http/Resources`), so passwords and internal fields are never returned.
- **Authorization** is enforced by policies, so users cannot read or change other users' data without permission.
- **Tokens** are stored hashed, expire, and can be revoked. Set `SANCTUM_TOKEN_PREFIX` so leaked tokens are flagged by secret scanners.
- **Cookies**: set `SESSION_SECURE_COOKIE=true` in production. Cookies are HTTP-only and `SameSite=Lax` by default.
- **Multi-step writes** (registration, user creation, deletion) run in database transactions.

## Project structure

```text
app/
├── Enums/                    Role and Permission enums
├── Http/
│   ├── Controllers/Api/V1/   Auth, PasswordReset, EmailVerification, User, UserRole controllers
│   ├── Middleware/           EnsureEmailIsVerifiedWhenRequired
│   ├── Requests/Api/V1/      Form request validation
│   └── Resources/            UserResource (controls the JSON shape)
├── Models/                   User (tokens, roles, soft deletes)
├── Policies/                 UserPolicy (authorization rules)
└── Providers/                AppServiceProvider (rate limiters, email link URLs)
bootstrap/app.php             Middleware and exception handling
config/cors.php               CORS settings
database/seeders/             RolesAndPermissionsSeeder, DatabaseSeeder
routes/api.php                API routes
routes/console.php            Scheduled token pruning
tests/Feature/Api/V1/         API, auth, and authorization tests
tests/Unit/                   Unit tests
```

## Testing and code quality

| Command | What it does |
| --- | --- |
| `composer test` | Run the test suite |
| `composer lint` | Check formatting with [Pint](https://laravel.com/docs/pint) |
| `composer format` | Fix formatting |
| `composer analyse` | Run static analysis with [Larastan](https://github.com/larastan/larastan) (level 6) |
| `composer check` | Run lint, static analysis, and tests |

Tests run against an in-memory SQLite database (see `phpunit.xml`), so they don't touch your MySQL data. Roles and permissions are seeded automatically for every test that uses `RefreshDatabase`.

GitHub Actions (`.github/workflows/ci.yml`) runs lint, static analysis, and tests on PHP 8.4 and 8.5 for every push to `main` and every pull request.

## License

Open-sourced under the [MIT license](https://opensource.org/licenses/MIT).
