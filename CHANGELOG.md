# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project follows
[Semantic Versioning](https://semver.org/).

## [5.0.0] - Unreleased

5.0 changes how errors and log messages become Sentry events, keeps secrets and personal data out of events by
default, and adds tracing, yii2-queue jobs, cron monitoring and Sentry Logs. [UPGRADE-5.0.md](UPGRADE-5.0.md) lists what
to check when upgrading. The requirements are those of 4.1, with Sentry PHP SDK 4.33.2 or later.

### Security

- Secrets are scrubbed even when `send_default_pii` is on: the values of fields, query parameters, headers, cookies
  and function arguments named like passwords, tokens, API keys or card numbers (`Integration::SECRET_FIELDS`), and
  Yii's session, CSRF and identity cookies, under the names the application gives them. Before, `send_default_pii`
  sent all of them, and without it request bodies were only filtered for the routes listed in `piiBodyFields`.
- Personal data (email, phone, first, last and full names, address, birth date, IBAN, passport, national ID, IP
  address) is hidden while `send_default_pii` is off: in the request body at any depth, the query string, headers, the
  query string of the `Referer` header, function arguments, and job data.
- The arguments of the functions in stack traces are scrubbed with the same rules.
- Values in database error messages are masked, e.g. "Duplicate entry ? for key 'email'", in events, breadcrumbs and
  Sentry Logs.
- In SQL, strings are masked whatever their quotes: single quotes, PostgreSQL's dollar quotes, and double quotes when
  the application's `db` connection is to MySQL or MariaDB, as other databases quote names with them. A statement whose
  quotes don't pair up is not sent.
- Multipart form bodies are no longer left to the SDK, which sent their fields unfiltered (4.1.1 applies
  `piiBodyFields` to them).
- The body of a request whose method is not in `httpMethodsWithRequestBody` is no longer left to the SDK, which sent it
  unfiltered: e.g. a DELETE with a body, or any request that a client marks as another method with Yii's
  `X-Http-Method-Override` header or `_method` field (also fixed in 4.1.1).
- Yii's own log lines are scrubbed wherever they are sent, as events, breadcrumbs, Sentry Logs and in `aggregate` mode:
  a yii2-httpclient call keeps its method and URL (Yii logs every header and the body); logins, logouts and emails lose
  the user ID, IP address and recipients while personal data is hidden; the warnings about an invalid login cookie or
  session lose the auth key, and the user ID and IP address while personal data is hidden; passwords in database DSNs
  are removed (4.1.1 scrubs those warnings and DSN passwords too). SQL log lines are masked wherever they are sent too.
- Credentials in a URL, e.g. `https://user:password@api.example.com`, are scrubbed like other secrets: in the spans
  and breadcrumbs of yii2-httpclient calls, in Yii's log lines for them and in the `Referer` header (4.1.1 leaves them
  out of the URLs it traces for yii2-httpclient calls).
- yii2-queue's log line about a failed job is sent without the job's data it may hold: what the job's process printed
  and the dump of a job that couldn't be read (also fixed in 4.1.1).
- In `aggregate` mode, the session ID in Yii's log prefix is replaced, as the session cookie is, and the prefix is left
  out while the `privacy` setting `scrubPersonalData` hides personal data. An exception under `logs` is its class and
  message, without the arguments in its stack trace.
- When the SDK's `data_collection` option is set, its `user_info` decides, like it does for the SDK, whether the user ID
  and personal data are sent. The SDK then collects the cookies itself: the names the application gives Yii's session,
  CSRF and identity cookies are added to the cookies it leaves out, which missed a session cookie named, say,
  `advanced-frontend` (also fixed in 4.1.1).
- Requires Sentry PHP SDK 4.33.2 or later: 4.33.0 and 4.33.1 add the raw request body, passwords included, to the
  transactions of traced requests.

### Added

- Tracing, once the SDK's `traces_sample_rate` or `traces_sampler` is set: a transaction for every web request, named
  by its route, timed from when PHP received the request and finished after the response is sent, with its HTTP
  status, and for every console command; spans for bootstrap, actions, views, yii2-httpclient calls and SQL queries
  (with values masked). The new `tracing` setting turns each part off; its `maskSqlValues` keeps the SQL of query spans
  as written.
- Traces continue across services: incoming `sentry-trace` and `baggage` headers are continued, and yii2-httpclient
  calls carry them (to the hosts in the SDK's `trace_propagation_targets`, or all), also without tracing.
  `traceMetaTags()` lets Sentry's browser SDK continue the request's trace.
- A breadcrumb for every yii2-httpclient call, with its status code, also for calls that got no response.
- yii2-queue jobs, when yii2-queue is installed, configured with the new `queue` setting. A failed job is an error event
  with its exception, named after the job's class, with its ID, attempt and queue, whether it will be retried (the
  `job.retry` tag) and its public properties, scrubbed. Each job a worker runs has a scope of its own, and queuing a
  job is a breadcrumb. With tracing, each job a worker runs is a `queue.process` transaction, and queuing a job a
  `queue.publish` span. A job that implements `TraceableJobInterface` (with `TraceableJobTrait`) shows in the trace of
  the code that queued it.
- Cron monitoring with Sentry Crons, for the console commands listed in the new `cronMonitors` setting.
- Sentry Logs: `LogTarget::$sentryLogs` forwards log messages to Sentry's Logs product.
- `LogTarget` settings: `aggregate`, `breadcrumbs`, `breadcrumbLevels`, `maskSqlValues`, `categoryAsLogger`,
  `stackTraces`, `sentryLogs` and `sentryLogLevels`.
- Component settings: `privacy` (this package's `Integration`, configured without replacing the integration list),
  `tracing`, `queue`, `cronMonitors`, `sendUserId`, `yiiContext`, `routeAsTransaction` and `sendAfterResponse`.
- `privacy` settings: `scrubSecrets`, `secretFields`, `scrubPersonalData`, `personalFields`, `sendUnparsedBodies`,
  `maskDatabaseErrors` and `scrubYiiLogs`, besides the `Integration` settings of 4.x (`piiBodyFields`, `piiHeaders`,
  `stripHeaders`, `httpMethodsWithRequestBody`, `piiReplaceText`).
- A "yii" section on events: the Yii version and the application's ID and name.
- The `yiisoft/yii2-queue` and `ext-excimer` suggestions. With excimer, the SDK's profiling (`profiles_sample_rate`, or
  continuous profiling from SDK 4.36) profiles the transactions above.
- End-to-end tests that run queue workers and console commands in real processes, and a CI job that runs the tests with
  the `excimer` extension.

### Changed

- `LogTarget` sends one event for every error and warning, with the time it was logged, the log category as its logger
  and a stack trace from where it was logged (with Yii's `traceLevel`). The request's other log messages become
  breadcrumbs, SQL queries as `db.sql.query` breadcrumbs with their values masked. `levels` defaults to `error` and
  `warning`. `'aggregate' => true` keeps the version 4 behavior: one event per export.
- An exception that reaches Yii's error handler is marked as unhandled.
- PHP errors are reported through Yii's error handler only: `ErrorListenerIntegration`, `FatalErrorListenerIntegration`
  and `TransactionIntegration` are no longer in the default `integrations`, so PHP errors are no longer reported twice.
  A client set up before Yii, e.g. with `\Sentry\init()`, keeps the SDK's fatal-error listener: its report of a fatal
  error is dropped when a log target reports the error through Yii's error handler.
- A Sentry client set up before Yii, e.g. with `\Sentry\init()`, is reused by default: `useExistingHub` is `true`.
- The user ID is attached to the events of a logged-in user on every request, not only when the user logs in, but by
  default only while personal data may be sent: with `send_default_pii`, or the `data_collection` option's
  `user_info`. Before, it was attached at login whatever `send_default_pii` said. The new `sendUserId` setting attaches
  it without the other personal data.
- The requested route is the transaction of error events.
- Only the application's own code is marked as "in app" in stack traces, and file paths are shown relative to the
  project root: `appBasePath` defaults to it, where it was `@app`. Sentry may group some issues anew once.
- The request body is sent once, as its fields, in `request.data`; the `decodedParams` additional data is gone. JSON
  bodies are read as fields also without a Yii parser configured for them, and bodies that can't be read as fields
  (XML, text, invalid JSON) are left out unless `sendUnparsedBodies` is set.
- Bodies larger than the SDK's `max_request_body_size` allows, 10,000 bytes by default, are left out: 4.x sent them
  whole. An upload that is left out isn't loaded into memory to report an error during it, when the client sends its
  size in the `Content-Length` header.
- `piiBodyFields` names match at any depth of the body, like `personalFields`; a name with a list limits the match to
  the fields under it.
- `piiHeaders` holds the headers with the visitor's IP address (`Forwarded`, `X-Forwarded-For`, `X-Real-IP`):
  `Authorization` and `Proxy-Authorization` are secrets now, scrubbed even with `send_default_pii`.
- The HTTP response is finished before events are sent, under PHP-FPM and LiteSpeed (`sendAfterResponse`).
- The `yiisoft/yii2-httpclient` suggestion now describes what the package does with it.
- The README was rewritten.

### Removed

- Version 4's tracing: a transaction per action, and yii2-httpclient requests as transactions of their own.
- The `decodedParams` additional data, and, outside `aggregate` mode, the `logs`, `real_body`, `real_time` and `traces`
  additional data: the other messages are breadcrumbs, and the body is in `request.data`.

### Fixed

- A failed yii2-queue job was lost, or, with `LogBehavior`, reported as the text of its log line, with the log lines of
  the other jobs under `logs`.
- Building several applications in one process, as some test suites do, registered the component's event handlers
  again for each application, so that actions were recorded several times.

## [4.1.1] - 2026-11-15

### Security

- `piiBodyFields` was not applied to forms sent as `multipart/form-data`, such as forms with a file field: PHP reads
  such a body itself, and the SDK's `RequestIntegration` then sent the fields unfiltered. They are now filtered like
  the fields of other forms, and left out when the route is not resolved.
- The line yii2-queue's `LogBehavior` logs about a failed job was sent to Sentry as it was, with any of the job's data
  it held: everything the job's process printed, when a worker runs the job in a process of its own (yii2-queue's
  default) and that process fails, e.g. because the job exited; and a dump of the job with all its data, when a stored
  job can't be read as a job, e.g. because its class was renamed after it was queued. That data is now left out.
- Yii's own warnings about a login cookie or session whose auth key doesn't match were sent with the user ID, the
  visitor's IP address and the key, and its warning about a database connection of a pool that couldn't be opened with
  the connection's DSN, which may hold the password. The key and the password are now left out, and the user ID and IP
  address too unless `send_default_pii` is enabled.
- With the SDK's `data_collection` option, the SDK collects the cookies itself, leaving out the ones named like its own
  list of words, so a session cookie under another name, like `advanced-frontend` in Yii's advanced application
  template, was sent. The names the application gives Yii's session, CSRF and identity cookies are now added to that
  list.
- The body of a request whose method is not in `httpMethodsWithRequestBody` was sent by the SDK's `RequestIntegration`
  as it was, unfiltered by `piiBodyFields`: e.g. a DELETE with a body, a PUT once the list is narrowed to POST, or any
  request that a client marks as another method with Yii's `X-Http-Method-Override` header or `_method` field. Such
  bodies are now left out, as the setting says.
- With tracing on, a yii2-httpclient request was recorded with its full URL, as the operation of its transaction and
  span, and with the client's base URL as the transaction's name, so credentials in a URL, e.g.
  `https://user:password@...`, a query string, e.g. `?api_key=...`, and a fragment were sent. They are now left out.

## [4.1.0] - 2026-11-10

### Security

- Log lines attached to events (`logs`) no longer include the client IP, user ID and session ID unless
  `send_default_pii` is enabled. The session ID could be used to take over the session.
- The `Proxy-Authorization`, `X-Forwarded-For` and `X-Real-IP` headers are now filtered by default.
- A client could prevent an error from being reported by sending a body that did not match the nested
  `piiBodyFields` rules (for example a string where an array was expected). Such bodies are now handled.
- The SDK's `data_collection` option (SDK 4.33 and later) was ignored for request data, so headers and bodies that it
  leaves out were still sent. When it is configured, the request is now left to the SDK's `RequestIntegration`, which
  applies it.

### Fixed

- With `send_default_pii` enabled, every event was dropped, and with tracing also enabled every request failed with
  HTTP 500 on SDK versions before 4.32 (the code still used the SDK 2 API). The same code path also took the user's
  IP address from a request header named `REMOTE_ADDR`, which a client can set, and would have sent cookies as an
  object without their values. It now uses `yii\web\Request::getUserIP()` and sends cookies as name/value pairs.
- On POST, PUT and PATCH requests, the additional data set elsewhere (such as `LogTarget`'s `logs`, `traces`,
  `real_body` and `real_time`) was replaced by `decodedParams`. They are now merged.
- Events were dropped when a request with a body failed before an action was resolved, for example in bootstrap or
  `beforeRequest` in an application without an error action. While `piiBodyFields` is configured, the body of such a
  request is left out, since no route's rules can be applied.
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
[5.0.0]: https://github.com/devnullius/yii2-sentry/compare/4.1.1...5.0.0
[4.1.1]: https://github.com/devnullius/yii2-sentry/compare/4.1.0...4.1.1
[4.1.0]: https://github.com/devnullius/yii2-sentry/compare/4.0.1...4.1.0
[4.0.1]: https://github.com/devnullius/yii2-sentry/compare/4.0.0...4.0.1
[4.0.0]: https://github.com/devnullius/yii2-sentry/compare/1.9.3...4.0.0
[1.9.0]: https://github.com/devnullius/yii2-sentry/compare/1.0.5...1.9.0
[1.9.3]: https://github.com/devnullius/yii2-sentry/compare/1.9.2...1.9.3
[1.0.0]: https://github.com/devnullius/yii2-sentry/releases/tag/1.0.0
[1.0.5]: https://github.com/devnullius/yii2-sentry/compare/1.0.4...1.0.5
