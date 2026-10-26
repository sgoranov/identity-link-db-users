# Identity Link DB Users

The user registry of the Identity Link system. This service exposes a REST API for managing **users** and the
**groups** that grant users scopes on protected-resource audiences. Other Identity Link services use it to
authenticate users and to resolve the scopes a user is allowed to request. It also serves the pages where users
reset a forgotten password.

## Domain model

| Entity    | Description                                                                                                                                                                       |
|-----------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| **User**  | A user account: unique username and email address, first and last name, password, the OAuth 2.0 grant types the user may authenticate with, and a two-factor authentication flag. |
| **Group** | A named set of users. A user can belong to up to 50 groups.                                                                                                                       |
| **Scope** | A scope granted to a group for one audience. A user's scopes for an audience are the union of the scopes of all its groups for that audience.                                     |

Passwords are stored as bcrypt hashes. Unless `FEATURE_PASSWORD_VALIDATION_ENABLED` is `false`, new passwords must
pass a strength check.

Users and groups flagged as **system** entities cannot be updated or deleted through the API, and neither can the
scopes of a system group.

## Running the service

### With Docker Compose (recommended)

The service is designed to run as part of the full Identity Link stack. See
[identity-link-docker](https://github.com/sgoranov/identity-link-docker) for Compose files that start it
together with PostgreSQL, Redis, and the other Identity Link services, for both production and local development.

## Configuration

The configuration variables and their defaults are described in the [.env](.env) file. Credentials are read from
[Docker secrets](https://docs.docker.com/compose/how-tos/use-secrets/) when the secret files exist; otherwise
the fallback environment variables `APP_SECRET`, `DB_USER`, and `DB_PASSWORD` are used.

Besides PostgreSQL, the service needs Redis (`REDIS_DSN`) for its cache, sessions, and rate limiting, and an SMTP
server (`MAILER_DSN`) to send password reset emails.

Logs are written to stderr; in `prod` they are formatted as JSON. When a request has an `X-Request-Id` header,
every line logged while handling it includes the header's value as `extra.request_id`.

## Authentication and scopes

Every endpoint under `/api` except `/api/v1/ping` and `/api/v1/openapi.yaml` requires a JWT bearer token:

```
Authorization: Bearer <access token>
```

Tokens are verified against the keys published at `JWT_JWKS_URI`, and both the issuer and the audience must
equal `JWT_URL`. The token's space-separated `scope` claim must contain the scope the endpoint requires;
otherwise the request is rejected with `403 Forbidden`.

| Scope                 | Grants                                                                |
|-----------------------|-----------------------------------------------------------------------|
| `users.read`          | Fetch a user and the user's scopes for an audience.                   |
| `users.write`         | Create and update users.                                              |
| `users.delete`        | Delete users.                                                         |
| `users.auth`          | Check a username and password with `POST /api/v1/auth`.               |
| `users.self.update`   | Update the profile of the user identified by the token's `sub` claim. |
| `users.groups.read`   | Fetch groups and list their scopes.                                   |
| `users.groups.write`  | Create and update groups, and add scopes to them.                     |
| `users.groups.delete` | Delete groups and remove their scopes.                                |
| `users.query`         | Search users and groups with `POST /api/v1/query`.                    |

## Password reset

The password reset pages are public and need no token. Setting `FEATURE_RESET_PASSWORD_ENABLED` to `false` turns
them off, and they then return `404 Not Found`. A reset works like this:

1. The user opens `/reset-password/{loginId}` and enters their email address. If a user has that address, the
   service emails them a reset link that is valid for one hour. The page shows the same message whether or not the
   address exists, and each client IP can submit the form five times per hour.
2. The link opens `/reset-password/{token}/{loginId}`, where the user chooses a new password. The success message
   links back to `RESET_PASSWORD_REDIRECT_URL`.

`{loginId}` is passed through unchanged; it only replaces `{id}` in `RESET_PASSWORD_REDIRECT_URL` to build the
link back to the login page.

The pages are translated into English and Bulgarian. To change how they look without modifying the service, put
files in `local_theme/`, whose contents are ignored by Git:

- templates in `local_theme/templates/` take precedence over those in `templates/`
- translations in `local_theme/translations/` override those in `translations/`
- files in `local_theme/public/` are served under `/theme/`

## OpenAPI specification

All endpoints, with their request and response schemas, are described in the OpenAPI specification at
[docs/openapi.yaml](docs/openapi.yaml). You can browse it with [Swagger Editor](https://editor.swagger.io/).
In the `dev` environment the service also serves it at `/api/v1/openapi.yaml`.

The specification is generated from the OpenAPI attributes in `src/`. After changing an endpoint, regenerate it with:

```bash
php bin/console app:generate-openapi
```

## Console commands

Three commands help bootstrap users without going through the API. Run them inside the container, for example
with `docker compose exec identity-link-db-users php bin/console …`.

### `app:configure-group-scopes`

Creates the group if it does not exist and **replaces** its scopes for the given audience with the ones provided.
Scopes for other audiences are left untouched.

```bash
php bin/console app:configure-group-scopes \
    --group=administrators \
    --audience=https://api.example.com \
    --scope=orders.read \
    --scope=orders.write
```

### `app:create-user`

Creates a user and attaches it to existing groups. Two-factor authentication is disabled for the new user.

```bash
php bin/console app:create-user \
    --username=jdoe \
    --email=jdoe@example.com \
    --first-name=John \
    --last-name=Doe \
    --password='<password>' \
    --grant-type=password \
    --group=administrators
```

| Option         | Required | Description                                                                     |
|----------------|----------|---------------------------------------------------------------------------------|
| `--username`   | yes      | Unique username of letters, digits, `_` and `-`.                                |
| `--email`      | yes      | Unique email address.                                                           |
| `--first-name` | yes      | First name.                                                                     |
| `--last-name`  | yes      | Last name.                                                                      |
| `--password`   | yes      | Password. It must pass the strength check unless password validation is off.    |
| `--grant-type` | no       | OAuth 2.0 grant type the user may authenticate with. Repeatable. Default: none. |
| `--group`      | no       | Name of an existing group. Repeatable.                                          |

### `app:reset-user-password`

Sets a random password for a user and prints it. The password is shown once and cannot be retrieved later.

```bash
php bin/console app:reset-user-password --username=jdoe
```

## Running the tests

The test suite runs in Docker against a disposable PostgreSQL instance, so the only requirement is Docker with
the Compose plugin.

```bash
bin/test-run
```

This builds the `test` image, runs PHPUnit, and removes the containers and volumes afterwards. The script exits with
PHPUnit's exit code, and CI runs the same script on every push and pull request to `develop` and `main`.

To debug tests interactively, open a shell in the test container with the project directory mounted at `/app`:

```bash
bin/test-debug
# inside the container:
vendor/bin/phpunit --filter UserControllerTest
```

Xdebug is installed in the test image.

## License

Identity Link is open source software licensed under the [MIT License](LICENSE), which permits reuse,
modification, and distribution with minimal restrictions.
