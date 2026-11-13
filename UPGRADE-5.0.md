# Upgrading from 4.x to 5.0

5.0 reports more, and sends less personal data. For most applications the upgrade is the new version and a look at
their configuration. This guide lists what changes, so that nothing in Sentry surprises you afterwards. The
[CHANGELOG](CHANGELOG.md) has the complete list.

The requirements are those of 4.1, PHP 7.4 or 8.x and Yii 2.0.55 or later, except that the Sentry PHP SDK must be
4.33.2 or later, which Composer installs for you: 4.33.0 and 4.33.1 add the raw request body, passwords included, to the
transactions of traced requests.

## Steps

1. Update the package:

   ```bash
   composer require devnullius/yii2-sentry:^5.0
   ```

2. If your configuration sets `integrations`, as the 4.x README showed for filtering request data, follow
   [Integrations](#integrations): otherwise PHP errors are reported twice.
3. If your application calls `\Sentry\init()` before Yii starts, read
   [A client set up before Yii](#a-client-set-up-before-yii).
4. Read the other sections for what changes in Sentry: more events, some issues grouped anew once, and new
   transaction names.

## More events: one for every error and warning

In 4.x, `LogTarget` sent one event per export: in a web request, the most severe message, with the other messages as
text under the `logs` additional data. In 5.0, every error and warning is an event of its own, and the request's other
log messages are its breadcrumbs.

- Expect more events, and set your alerts and quota with that in mind. To keep the 4.x behavior, set
  `'aggregate' => true` on the log target: one event per export, with the other messages under `logs`, now scrubbed
  like everything else. `levels` still defaults to `error` and `warning` then, and crashes are not marked as
  unhandled.
- `levels` now defaults to `error` and `warning`. In 4.x, a log target without `levels` collected every message, down to
  `trace`, and sent the most severe one, even when that was only `info`.
- An event's time is when the message was logged, its logger is the log category, and its stack trace shows where the
  message was logged, when Yii records it (`traceLevel` above 0 in the `log` component).
- An exception that reaches Yii's error handler is marked as unhandled: it ended the request or command. This changes
  what Sentry's "unhandled" filters and alerts find.
- Outside `aggregate` mode, the `logs`, `real_body`, `real_time` and `traces` additional data are gone.

## Some issues are grouped anew, once

Only your application's code is marked as "in app" in stack traces now, and file paths are shown relative to the
project root (the directory that holds `vendor/`, see `appBasePath`). Sentry groups issues by the frames of your own
code, so some issues start anew after the upgrade.

If you set `in_app_include` and `in_app_exclude` in `sentrySettings`, as the 4.x README suggested, you can remove them.

## PHP errors

PHP warnings, notices and fatal errors are reported through Yii's error handler only. The SDK's
`ErrorListenerIntegration` and `FatalErrorListenerIntegration`, which reported them a second time, are no longer in the
default integrations; nor is `TransactionIntegration`, as the package names events itself.

Errors that happen before Yii's error handler is registered, e.g. in the configuration files, are not reported, as
before. To catch them too, set up the SDK before Yii starts: see the README.

## Integrations

If your configuration sets `integrations`, it replaces the default list, as before. A list copied from the 4.x README
still holds `ErrorListenerIntegration` and `FatalErrorListenerIntegration`, which report PHP errors a second time, as
they did in 4.x.

The package's own `Integration` is now configured with the component's `privacy` setting, without listing the
integrations at all:

```php
// 4.x
'sentry' => [
    'class' => devnullius\yii2\sentry\Component::class,
    'integrations' => [
        [
            'class' => devnullius\yii2\sentry\Integration::class,
            'piiBodyFields' => ['site/login' => ['LoginForm' => ['password']]],
        ],
        Sentry\Integration\FatalErrorListenerIntegration::class,
        // ...
    ],
],

// 5.0
'sentry' => [
    'class' => devnullius\yii2\sentry\Component::class,
    'privacy' => [
        // Passwords are scrubbed anyway: list only your own personal data fields.
        'piiBodyFields' => ['account/profile' => ['nickname']],
    ],
],
```

Remove `integrations` if it only configured `Integration`. If you add integrations of your own, keep it, starting from
the new default list in the README, which no longer has the two error listeners.

## A client set up before Yii

`useExistingHub` is now `true`: when a Sentry client is already set up, e.g. with `\Sentry\init()` in `web/index.php`,
the component uses it, and its own `dsn`, `sentrySettings` and SDK integrations are not applied. The package's
`Integration`, the route, breadcrumbs and user ID are still added. Set `'useExistingHub' => false` to have the component
create its own client, as 4.x did by default.

Nor does that client get what the component gives a client of its own: the environment from `YII_ENV`, file paths
relative to the project root, and vendor code not marked as "in app". Its events then have absolute paths and vendor
frames marked as "in app", so Sentry may group them differently: set `environment`, `prefixes` and `in_app_exclude` in
your `\Sentry\init()` call, as the README shows.

## Personal data and secrets

5.0 keeps secrets out of Sentry even with `send_default_pii` on, and hides personal data while it is off, everywhere
the package sends data: the request body at any depth, the query string, headers, cookies, the `Referer` header, the
arguments of the functions in stack traces, job data, database error messages, and Yii's own log lines, as events,
breadcrumbs or Sentry Logs. The README explains it all. What changes for 4.x users:

- **Secrets are scrubbed even with `send_default_pii`**: passwords, tokens, API keys, card numbers, the `Authorization`
  headers, and Yii's session, CSRF and identity cookies. Your login forms no longer need `piiBodyFields` entries for
  their passwords.
- **Personal data is found by name**, at any depth of the body: email, phone, first, last and full names, address,
  birth date, IBAN, passport, national ID, IP address. A field called just `name`, `username` or `nickname` is not
  matched: add the fields of your own that hold personal data under other names to `personalFields`, or for one route
  to `piiBodyFields`.
- **`piiBodyFields` matches at any depth**, like those names. In 4.x a name matched only from the top of the body; a
  name with a list under it still limits the match to the fields in the list.
- **`piiHeaders`** holds the headers with the visitor's IP address. `Authorization` and `Proxy-Authorization` are
  secrets now, and are scrubbed even with `send_default_pii`.
- **The body is sent once**, as its fields, in `request.data`: the `decodedParams` additional data is gone. Bodies that
  can't be read as fields (XML, plain text, invalid JSON) are left out, as secrets in them can't be found; set
  `sendUnparsedBodies` to send them as they are.
- **Values in database errors are masked**: "Duplicate entry ? for key 'email'".
- **In `aggregate` mode**, Yii's prefix of the lines in `logs` no longer holds the session ID, also with
  `send_default_pii`.
- **With the SDK's `data_collection` option**, its `user_info` decides whether the user ID and personal data are sent,
  as it does for the SDK. It is `true` unless you set it to `false`. The component adds the names of Yii's session,
  CSRF and identity cookies to the cookies the SDK leaves out.

To send secrets again, set the `privacy` setting `scrubSecrets` to `false`. Be careful: anyone who can read your
Sentry events can then use them, e.g. to log in as your users.

## The user ID

In 4.x, the logged-in user's ID was attached on the request where the user logged in, whatever `send_default_pii` said.
In 5.0, it is attached to the events of every request, but by default only while the SDK may send personal data
(`send_default_pii`, or `data_collection` with `user_info`): with the default settings, events no longer carry it. Set
`'sendUserId' => true` to attach the ID without the other personal data, or `false` to never attach it.

## Tracing

Tracing was rewritten. With `traces_sample_rate` set, every web request is one `http.server` transaction, named by its
route (e.g. `site/view`), and every console command a `console.command` transaction, with spans for bootstrap,
actions, views, outgoing yii2-httpclient calls and SQL queries. The transactions of 4.x are gone: one per action, named
after the controller, and one per yii2-httpclient request. Update the dashboards, alerts and `traces_sampler` rules that
use their names. The `transaction` of error events is now the route too, where it was the URL path.

Outgoing yii2-httpclient calls carry the trace headers `sentry-trace` and `baggage`, also without tracing, so that the
services you call can continue the trace. They go to every host unless you list your own services in the SDK's
`trace_propagation_targets` option; a third-party API then sees your trace ID and, in `baggage`, details such as your
release and environment, and with tracing the route.

Incoming trace headers are continued, also without tracing, so that your errors show in the caller's trace. With tracing
on, the caller's decision to trace the request is followed: a client that sends the headers can have its requests
traced, which counts toward your quota. Turn the `tracing` setting's `continueTraces` off, or decide in
`traces_sampler`, if your application is public.

## Queue jobs

When yii2-queue is installed, its jobs are now reported, with nothing to configure:

- **A failed job is an error event** with its exception, named after the job's class, with its ID, attempt and queue
  and its public properties, scrubbed like request bodies. Before, it was lost, or, with `LogBehavior`, arrived as the
  text of its log line. `LogBehavior`'s line about the failure no longer becomes an event of its own.
- **Every failed attempt** is reported; set the `queue` setting's `retries` to `false` to report a job only once it has
  failed for good.
- **A worker is no longer one transaction** for as long as it runs: each job is a `queue.process` transaction.

The `queue` setting turns each part off. To show a job in the trace of the request that queued it, see
`TraceableJobInterface` in the README: deploy your workers before the code that queues such jobs.

## Sending after the response

Under PHP-FPM and LiteSpeed, the response is now finished before events are sent at the end of the request, so that
visitors don't wait for Sentry. Code that runs afterwards can't send output any more; if yours does, set
`'sendAfterResponse' => false`.

## New, and off until you turn it on

Cron monitoring (`cronMonitors`), Sentry Logs (`sentryLogs` on the log target), SQL values in breadcrumbs
(`'maskSqlValues' => false`), Yii's profiling markers as breadcrumbs (`profile` in `breadcrumbLevels`), and profiling
with the SDK's `profiles_sample_rate`. The README describes each.
