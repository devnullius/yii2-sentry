# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project follows
[Semantic Versioning](https://semver.org/).

## [4.1.0] - 2026-11-10

### Security

- Log lines attached to events (`logs`) no longer include the client IP, user ID and session ID unless
  `send_default_pii` is enabled. The session ID could be used to take over the session.
- The `Proxy-Authorization`, `X-Forwarded-For` and `X-Real-IP` headers are now filtered by default.
- A client could prevent an error from being reported by sending a body that did not match the nested
  `piiBodyFields` rules (for example a string where an array was expected). Such bodies are now handled.

### Fixed

- With `send_default_pii` enabled, every event was dropped, and with tracing also enabled every request failed with
  HTTP 500 on SDK versions before 4.32 (the code still used the SDK 2 API). The same code path also took the user's
  IP address from a request header named `REMOTE_ADDR`, which a client can set, and would have sent cookies as an
  object without their values. It now uses `yii\web\Request::getUserIP()` and sends cookies as name/value pairs.
- On POST, PUT and PATCH requests, the additional data set elsewhere (such as `LogTarget`'s `logs`, `traces`,
  `real_body` and `real_time`) was replaced by `decodedParams`. They are now merged.
- Events were dropped when a request with a body failed before an action was resolved, for example in bootstrap or
  `beforeRequest` in an application without an error action.
- Events were dropped when the request body could not be parsed (for example invalid JSON with `JsonParser`). The
  event is now sent without the body and the failure is logged as a warning.
- The `route` tag was overwritten by the error action (`site/error` on every exception in production) and by nested
  actions. It now holds the route that was requested.
- Breadcrumbs described the first action of the request instead of the action being run.
- Logging in failed with an exception when the identity's ID was not an integer or string, like MongoDB's ObjectId.
  Stringable IDs are now sent as strings, and setting the Sentry user can no longer make a login fail.
- The integrations configuration documented in the README (Yii configuration arrays) failed with
  "Class name must be a valid object or a string". Integrations can now be class names, configuration arrays or
  instances.
- `SENTRY_DSN` and `SENTRY_ENVIRONMENT` were ignored. When `dsn` is not set, the SDK now reads `SENTRY_DSN`;
  `SENTRY_ENVIRONMENT`, when set, takes precedence over `YII_ENV`.
- Subclasses of `Integration` were never applied, and when several clients were created in one process, the first
  client's `Integration` settings were used for all of them.
- `request.url` was relative and repeated the query string; it is now the absolute URL without the query string.
- The tracing hooks no longer fail the request when an action or HTTP request finishes without its start hook having
  run, for example when another event handler marks the start event as handled.

### Added

- `Component::$useExistingHub`, to reuse a client set up with `\Sentry\init()` before Yii starts ([#1]).
- A test suite (PHPUnit, including end-to-end tests through PHP's built-in web server), PHPStan, and a GitHub Actions
  workflow testing PHP 7.4 to 8.5 with the lowest and highest dependency versions.

### Changed

- Requires PHP 7.4 or later (was 7.3 in practice), Yii 2.0.55 or later and Sentry PHP SDK 4.33 or later. Older Yii
  versions have known vulnerabilities, Composer 2.9 and later refuses to install them by default, and every Yii version
  that supports PHP 7.3 is affected. SDK versions before 4.15 fail to boot on PHP 8.5 when deprecations are reported,
  and before 4.33 the SDK's `RequestIntegration` replaced this package's request data.
- When `dsn` is not set, the `SENTRY_DSN` environment variable is used. Set `dsn` to `false` to disable sending events
  explicitly.
- `yiisoft/yii2-httpclient` is now suggested instead of HTTPlug packages that SDK 4 does not use.
- The README was rewritten.

### Removed

- The `sentry/sdk`, `symfony/psr-http-message-bridge` and `nyholm/psr7` dependencies, which were not used. Together
  they also installed `symfony/http-foundation`, which kept applications on Symfony 7 or later from installing this
  package.

## [4.0.1] - 2025-06-06

### Changed

- The `yiisoft/yii2` requirement was relaxed from exactly 2.0.50 to `^2.0.50` ([#2]).

## [4.0.0] - 2025-01-24

### Changed

- Requires Sentry PHP SDK 4.

## [1.9.0] - 2022-01-22 to [1.9.3] - 2023-03-10

- Sentry PHP SDK 3 (3.3, then 3.12 in 1.9.1).
- Basic performance tracing of controller actions and yii2-httpclient requests (1.9.2, 1.9.3).

## [1.0.0] - 2020-08-20 to [1.0.5] - 2020-09-17

- First releases of this fork, on Sentry PHP SDK 2.

[#1]: https://github.com/devnullius/yii2-sentry/issues/1
[#2]: https://github.com/devnullius/yii2-sentry/pull/2
[4.1.0]: https://github.com/devnullius/yii2-sentry/compare/4.0.1...4.1.0
[4.0.1]: https://github.com/devnullius/yii2-sentry/compare/4.0.0...4.0.1
[4.0.0]: https://github.com/devnullius/yii2-sentry/compare/1.9.3...4.0.0
[1.9.0]: https://github.com/devnullius/yii2-sentry/compare/1.0.5...1.9.0
[1.9.3]: https://github.com/devnullius/yii2-sentry/compare/1.9.2...1.9.3
[1.0.0]: https://github.com/devnullius/yii2-sentry/releases/tag/1.0.0
[1.0.5]: https://github.com/devnullius/yii2-sentry/compare/1.0.4...1.0.5
