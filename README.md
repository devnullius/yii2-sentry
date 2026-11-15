# yii2-sentry

[![CI](https://github.com/devnullius/yii2-sentry/actions/workflows/ci.yml/badge.svg)](https://github.com/devnullius/yii2-sentry/actions/workflows/ci.yml)
[![Latest Stable Version](https://img.shields.io/packagist/v/devnullius/yii2-sentry)](https://packagist.org/packages/devnullius/yii2-sentry)
[![Total Downloads](https://img.shields.io/packagist/dt/devnullius/yii2-sentry)](https://packagist.org/packages/devnullius/yii2-sentry)
[![License](https://img.shields.io/packagist/l/devnullius/yii2-sentry)](LICENSE.md)

[Sentry](https://sentry.io/) error reporting and performance monitoring for [Yii 2](https://www.yiiframework.com/)
applications, built on the official [Sentry PHP SDK](https://github.com/getsentry/sentry-php) 4.

Inspired by the official [sentry-symfony](https://github.com/getsentry/sentry-symfony) and
[sentry-laravel](https://github.com/getsentry/sentry-laravel) packages.

- Reports uncaught exceptions, PHP errors and `Yii::error()` / `Yii::warning()` messages through Yii's own error
  handler and logger, one event for each, so your log targets' `levels`, `categories` and `except` rules apply.
- Adds what Yii knows to every event: the request, the route, the logged-in user, and the request's log messages, SQL
  queries and HTTP calls as breadcrumbs.
- Keeps secrets out of Sentry, and personal data too unless you allow it.
- Traces requests, console commands, yii2-httpclient calls and SQL queries, and continues traces across your services
  and the browser.
- Reports failed [yii2-queue](https://github.com/yiisoft/yii2-queue) jobs with their details, and traces each job.
- Optionally monitors cron jobs with Sentry Crons, and forwards log messages to Sentry Logs.
- Works in web and console applications.

## Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Getting started](#getting-started)
- [What gets reported](#what-gets-reported)
- [Configuration](#configuration)
- [Personal data and secrets](#personal-data-and-secrets)
- [Performance monitoring](#performance-monitoring)
- [Profiling](#profiling)
- [Queue jobs](#queue-jobs)
- [Cron monitoring](#cron-monitoring)
- [Sentry Logs](#sentry-logs)
- [Long-running processes](#long-running-processes)
- [Setting up Sentry before Yii starts](#setting-up-sentry-before-yii-starts)
- [Speed and quota](#speed-and-quota)
- [Upgrading](#upgrading)
- [Development](#development)
- [Credits](#credits)

## Requirements

- PHP 7.4 or 8.x, with the `curl`, `json` and `mbstring` extensions
- Yii 2.0.55 or later
- Sentry PHP SDK (`sentry/sentry`) 4.33.2 or later, installed automatically
- Optional: [yiisoft/yii2-queue](https://github.com/yiisoft/yii2-queue) 2.3.8 or later, for [queue jobs](#queue-jobs);
  [yiisoft/yii2-httpclient](https://github.com/yiisoft/yii2-httpclient) 2.0.15 or later, for outgoing HTTP calls; the
  [excimer](https://pecl.php.net/package/excimer) extension, for [profiling](#profiling)

## Installation

```bash
composer require devnullius/yii2-sentry:^5.0
```

The SDK sends events with its own cURL-based HTTP client; no other HTTP packages are needed.

## Getting started

Register the component, bootstrap it, and add the log target, in your web and console application configs:

```php
<?php

return [
    'id' => 'my-app',

    'bootstrap' => [
        'sentry',
        'log',
    ],

    'components' => [
        'sentry' => [
            'class' => devnullius\yii2\sentry\Component::class,
            // Optional when the SENTRY_DSN environment variable is set.
            'dsn' => 'https://<public-key>@<organization>.ingest.sentry.io/<project-id>',
        ],

        'log' => [
            'targets' => [
                [
                    'class' => devnullius\yii2\sentry\LogTarget::class,
                    'except' => [
                        'yii\web\HttpException:40*',
                    ],
                ],
            ],
        ],
    ],
];
```

Excluding the 40x HTTP exceptions is highly recommended: Yii uses them to answer requests (not found, forbidden, …),
and they don't point to problems in your application.

That is all you need. Errors and warnings are reported, without secrets or personal data. The rest of this README
explains what is sent, and how to change it.

## What gets reported

**Events**: one for each of these.

- Every exception that reaches Yii's error handler, marked as unhandled: it ended the request or command. This includes
  the PHP warnings and notices Yii's error handler turns into exceptions, and fatal errors.
- Every message logged at the log target's `levels` (`error` and `warning` by default): `Yii::error()`,
  `Yii::warning()`, and the exceptions your code catches and logs.
- With yii2-queue installed, every failed job: see [Queue jobs](#queue-jobs).

Every event carries:

- The time it was logged, its level, and its log category, as the `category` tag and the logger name.
- A stack trace: the exception's, or for a message, where it was logged (when Yii records it: `traceLevel` above 0 in
  the `log` component). Only your application's code is marked as "in app", and paths are relative to the project root.
- The request: method, URL, query string, headers and body, without secrets and, by default, without personal data. See
  [Personal data and secrets](#personal-data-and-secrets).
- The requested route, as the `route` tag and as the event's transaction (for a queue job, the job's class), even when
  the error is reported while the error action runs. A request that fails before its route is resolved, e.g. in
  bootstrap or for an unknown URL, shows the error action's route.
- The logged-in user's ID, when personal data may be sent, or with `sendUserId`.
- A "yii" section: the Yii version, and the application's ID and name.
- Breadcrumbs: every action run, the request's other log messages, its SQL queries (with values masked), its
  yii2-httpclient calls, and the queue jobs it queued. The SDK keeps the last 100 (its `max_breadcrumbs` option), so a
  request with many queries shows only its latest ones.
- The environment (the `SENTRY_ENVIRONMENT` environment variable, or `YII_ENV`) and the release (`SENTRY_RELEASE`, or
  the SDK's `release` option).

Events are sent at the end of the request, after the response has been sent, so that visitors don't wait for Sentry
(under PHP-FPM and LiteSpeed; see `sendAfterResponse`).

### What is on by default

Cheap and safe features are on; whatever sends more data, uses more Sentry quota or slows your application down is off
until you turn it on.

| On by default | Off by default |
|---|---|
| An event for every error and warning, at the time it was logged | Personal data (`send_default_pii`) |
| Crashes marked as unhandled | Tracing (`traces_sample_rate`) and profiling |
| The request's log messages, SQL queries (masked) and HTTP calls as breadcrumbs | SQL values in breadcrumbs (`maskSqlValues`) |
| Secrets always scrubbed, personal data hidden | Yii's profiling markers as breadcrumbs |
| Reporting failed queue jobs, when yii2-queue is installed | Cron monitoring (`cronMonitors`) |
| Sending after the response | Sentry Logs (`sentryLogs`) |
| Trace headers on yii2-httpclient calls, to every host, and continuing incoming traces: see [Performance monitoring](#performance-monitoring) | |

## Configuration

### Component (`devnullius\yii2\sentry\Component`)

| Setting | Default | Description |
|---|---|---|
| `dsn` | `null` | The project's DSN. When it is not set, the SDK reads the `SENTRY_DSN` environment variable. Set it to `false` to send nothing. |
| `sentrySettings` | `[]` | [SDK options](https://docs.sentry.io/platforms/php/configuration/options/), passed as they are, e.g. `release`, `sample_rate`, `traces_sample_rate`, `before_send`. They take precedence over the component's defaults. Use the `integrations` setting below rather than an `integrations` key here. |
| `integrations` | see below | SDK integrations, as class names, Yii configuration arrays or instances. Setting it replaces the default list. |
| `privacy` | `[]` | Settings for what this package sends about requests, function arguments and database errors: see [Personal data and secrets](#personal-data-and-secrets). |
| `tracing` | all on | What is traced, once tracing is on: see [Performance monitoring](#performance-monitoring). |
| `queue` | all on | What is reported about yii2-queue jobs: see [Queue jobs](#queue-jobs). |
| `cronMonitors` | `[]` | Console commands to monitor with Sentry Crons: see [Cron monitoring](#cron-monitoring). |
| `useExistingHub` | `true` | Reuse a Sentry client already set up, e.g. with `\Sentry\init()` before Yii starts: see [Setting up Sentry before Yii starts](#setting-up-sentry-before-yii-starts). |
| `sendUserId` | `null` | Whether to attach the logged-in user's ID to events. By default, only when the SDK may send personal data (`send_default_pii`, or the `data_collection` option's `user_info`). `true` attaches the ID without the other personal data; `false` never attaches it. |
| `yiiContext` | `true` | Add the "yii" section to events: the Yii version and the application's ID and name. |
| `routeAsTransaction` | `true` | Use the requested route, e.g. `site/view`, as the transaction of error events. |
| `sendAfterResponse` | `true` | Under PHP-FPM or LiteSpeed, finish the HTTP response once Yii has sent it, so that visitors don't wait while events are sent. Code that runs afterwards can no longer send output. |
| `appBasePath` | `null` | The directory that paths in stack traces are shown relative to. By default, the project root (the directory that holds `vendor/`), or `@app` when the vendor directory is elsewhere. Not applied to a client set up before Yii starts. |

The default `integrations`:

```php
[
    devnullius\yii2\sentry\Integration::class,  // the request, and what is scrubbed: see the privacy setting
    Sentry\Integration\FrameContextifierIntegration::class,
    Sentry\Integration\EnvironmentIntegration::class,
    Sentry\Integration\ModulesIntegration::class,
    Sentry\Integration\RequestIntegration::class,
]
```

The SDK's exception and error listeners are deliberately left out: Yii's error handler and `LogTarget` already report
uncaught exceptions and PHP errors, and both together would report each of them twice.

To see the SDK's own diagnostics, e.g. why an event was not sent, give it a PSR-3 logger:
`'sentrySettings' => ['logger' => $myPsrLogger]`.

The client is created with `Sentry\ClientBuilder`, resolved from Yii's DI container with the options as its only
argument: set your own in the container to change how the client is built.

### Log target (`devnullius\yii2\sentry\LogTarget`)

A regular Yii [log target](https://www.yiiframework.com/doc/guide/2.0/en/runtime-logging): `levels`, `categories`,
`except`, `exportInterval`, `prefix`, … all apply. Without `levels`, it takes `error` and `warning`. In addition:

| Setting | Default | Description |
|---|---|---|
| `component` | `'sentry'` | The ID of the Sentry application component, or the component itself. |
| `breadcrumbs` | `true` | Add the request's log messages as breadcrumbs to the events that follow them. |
| `breadcrumbLevels` | `['error', 'warning', 'info', 'trace']` | The levels of the messages that become breadcrumbs. Add `'profile'` for Yii's profiling markers. |
| `maskSqlValues` | `true` | Replace the values in SQL queries (strings and numbers) with `?`, e.g. `WHERE email = ?`, wherever Yii's SQL log lines are sent: as breadcrumbs, to Sentry Logs, or as events when `levels` has `info`. Turn it off only if your queries hold no personal data or secrets. |
| `stackTraces` | `true` | Give the event of a logged message a stack trace, from the trace Yii records for it (`traceLevel`). |
| `categoryAsLogger` | `true` | Use the log category as the event's logger name. |
| `aggregate` | `false` | Send one event per export, for the most severe message, with the other messages as text under the `logs` additional data, like version 4 did. The messages are then not breadcrumbs (the actions, HTTP calls and queued jobs still are), crashes are not marked as unhandled, and the event has no logger name and the time it was sent. Messages are scrubbed as everywhere else; an exception in `logs` is its class and message, without its stack trace. |
| `sentryLogs` | `false` | Also forward log messages to [Sentry Logs](#sentry-logs). |
| `sentryLogLevels` | `['error', 'warning', 'info']` | The levels of the messages forwarded to Sentry Logs. |

Messages that this package logs about its own failures (category `devnullius\yii2\sentry\*`) are never sent to Sentry;
they reach your other log targets.

In `aggregate` mode, the lines under `logs` start with Yii's prefix while personal data may be sent, and the `privacy`
setting `scrubPersonalData` doesn't hide it: the client IP and user ID, with the session ID replaced, as it is a secret.
A custom `prefix` is used as it is.

## Personal data and secrets

> **Be careful with what you send.** Sentry keeps events for weeks or months, and everyone who can open your Sentry
> project can read them, on [sentry.io](https://sentry.io/) and on a Sentry you host yourself alike. A password, token
> or session ID in an event lets its readers act as your users, and personal data falls under laws like the GDPR. The
> defaults keep both out of Sentry. Before you turn any of it back on, look at what your events then contain, and
> decide who may read it.

**Secrets are always scrubbed**, even with the SDK's `send_default_pii` option on: the values of fields, query
parameters, headers, cookies and function arguments named like passwords, tokens, API keys, one-time codes or card
numbers (`Integration::SECRET_FIELDS`), the `Authorization` headers, and Yii's session, CSRF and identity cookies,
under the names your application gives them.

**Personal data is hidden** while `send_default_pii` is off: the values of fields named like an email address, phone
number, first, middle, last or full name, address, birth date, IBAN, bank account, passport, national ID or IP address
(`Integration::PERSONAL_FIELDS`), the headers with the visitor's IP address, and the visitor's IP address and cookies.
A field called just `name`, `username` or `nickname` is not hidden, as it so often names something else: add the names
your application uses for people to `personalFields`.

Both apply to the request body at any depth, the query string, headers, cookies, the query string of the `Referer`
header, the arguments of the functions in stack traces, and the data of queue jobs. A scrubbed value is replaced with
`[Filtered PII]`. Names match whatever their case and separators: a name of five characters or
more matches anywhere ("token" matches `accessToken`), a shorter one only as a whole word ("pin" matches `pin_code`, not
`spinner`).

Also by default:

- A request body that can't be read as fields (XML, plain text, invalid JSON) is left out, as secrets in it can't be
  found.
- Values in database errors are masked: "Duplicate entry ? for key 'email'".
- SQL is sent with its values masked, in query spans, Yii's SQL log lines and database errors: numbers, and strings
  in single quotes, in PostgreSQL's dollar quotes and, when the application's `db` connection is to MySQL or
  MariaDB, in double quotes, which other databases use for names. A statement whose quotes don't pair up isn't sent.
- Credentials in a URL, as in `https://user:password@api.example.com`, are scrubbed like other secrets: in the spans
  and breadcrumbs of yii2-httpclient calls, in Yii's log lines for them and in the `Referer` header.
- Yii's own log lines are scrubbed wherever they are sent, as events, breadcrumbs or Sentry Logs: a yii2-httpclient
  call keeps its method and URL, as Yii logs every header and the body; logins, logouts and emails lose the user ID, IP
  address and recipients while personal data is hidden; the warnings about an invalid login cookie or session lose the
  auth key, and the user ID and IP address while personal data is hidden; a password in a database DSN is removed.
  Your own log messages are sent as you write them: don't log secrets.

With `'send_default_pii' => true` in `sentrySettings`, personal data is sent: the visitor's IP address, from
`yii\web\Request::getUserIP()` (which honors `trustedHosts` behind a proxy), cookies (with secrets scrubbed), personal
data fields, and the logged-in user's ID. Secrets stay scrubbed.

Sentry's [server-side data scrubbing](https://docs.sentry.io/security-legal-pii/scrubbing/) can remove more, but don't
rely on it alone: what this package sends, Sentry receives.

### The `privacy` setting

The component's `privacy` setting configures all of the above:

```php
'sentry' => [
    'class' => devnullius\yii2\sentry\Component::class,
    'privacy' => [
        // Your own fields that hold personal data under other names.
        'personalFields' => array_merge(devnullius\yii2\sentry\Integration::PERSONAL_FIELDS, ['nickname']),
        // And for specific routes only: the "nickname" an ActiveForm posts as Profile[nickname].
        'piiBodyFields' => [
            'account/profile' => ['Profile' => ['nickname']],
        ],
    ],
],
```

| Setting | Default | Description |
|---|---|---|
| `scrubSecrets` | `true` | Scrub secrets, even with `send_default_pii`. **Don't turn it off** unless nobody but you can read your events. |
| `secretFields` | `Integration::SECRET_FIELDS` | The names of fields that hold secrets. Merge yours with the defaults, as in the example. |
| `scrubPersonalData` | `null` | Hide personal data. By default, while `send_default_pii` is off; `true` always hides it, `false` never does: then the headers with the visitor's IP address are sent too. The IP address the SDK sends for the visitor, and the cookies, follow `send_default_pii` only. |
| `personalFields` | `Integration::PERSONAL_FIELDS` | The names of fields that hold personal data. |
| `piiBodyFields` | `[]` | More personal data fields, for the request body of specific routes. A name with a list under it limits the match to the fields in the list. When a request fails before its route is known, e.g. in bootstrap, the body is left out. |
| `piiHeaders` | `['forwarded', 'x-forwarded-for', 'x-real-ip', 'remote_addr']` | Headers that hold personal data: the visitor's IP address, as forwarded by proxies. Headers named after the personal data fields are hidden too. Lower case. |
| `stripHeaders` | `['cookie', 'set-cookie']` | Headers that are never sent. The cookies are sent apart, with `send_default_pii`. Lower case. |
| `sendUnparsedBodies` | `false` | Send bodies that can't be read as fields (XML, plain text, invalid JSON) as they are. Secrets and personal data in them can't be found, so **they are sent unscrubbed**. |
| `httpMethodsWithRequestBody` | `['POST', 'PUT', 'PATCH']` | The HTTP methods whose request body is sent. |
| `maskDatabaseErrors` | `true` | Replace the values in database errors with `?`. Names of tables, columns and keys are kept. |
| `scrubYiiLogs` | `true` | Scrub Yii's own log lines, as described above. |
| `piiReplaceText` | `'[Filtered PII]'` | The text that replaces scrubbed values. |

The request body is sent for POST, PUT and PATCH requests, as its fields, in `request.data`, up to the size the SDK's
`max_request_body_size` option allows: 10,000 bytes by default, any size with `'always'`. A body that is left out is
replaced by a note saying why, e.g. `[not sent: DELETE is not in httpMethodsWithRequestBody]`. An upload that is left
out isn't loaded into memory to report an error during it, as long as the client sends its size in the
`Content-Length` header, as browsers do.

### The SDK's `data_collection` option

Sentry PHP SDK 4.33 added a `data_collection` option, which replaces `send_default_pii` with finer controls. When it
is set, in `sentrySettings` or on a client reused with `useExistingHub`, the SDK collects the request and the function
arguments under that policy, with its `RequestIntegration`: keep that integration in `integrations`. This package then
only masks database errors, and elsewhere, e.g. in Yii's log lines, hides personal data while the policy doesn't
collect user info (`user_info`), which also decides whether the user ID is attached. Job data follows its `queues`.

> **Setting `data_collection` turns personal data on.** Its `user_info` defaults to `true` once the option is set: the
> visitor's IP address and the user ID are then sent, and personal data in Yii's log lines and in job data too. Set
> `'user_info' => false` unless you want that.

The SDK leaves out the cookies named like its own list of words ("session", "sid", "csrf", "identity", …), which
misses a session cookie named, say, `advanced-frontend`, as in Yii's advanced application template. The component adds
the names your application gives Yii's session, CSRF and identity cookies to that list (the `cookies` setting's
`terms`), unless the setting only lets listed cookies through. It reads the names from the components' configuration
when Sentry starts, or from the components that exist by then, so add a name by hand when it is set elsewhere, e.g.
by a closure that creates the session component. Add them all by hand on a client you set up yourself, or with the
option given as a `DataCollectionOptions` object:

```php
'data_collection' => [
    'user_info' => false,
    'cookies' => ['terms' => ['advanced-frontend', '_csrf-frontend', '_identity-frontend']],
],
```

## Performance monitoring

Tracing is off until you set a sample rate, the share of requests and commands to trace:

```php
'sentrySettings' => [
    'traces_sample_rate' => 0.1,
],
```

Then every traced web request is one transaction, named by its route (e.g. `site/view`, or its path when no route
matched), timed from when PHP received the request and finished after the response has been sent, with the HTTP status.
Every console command is a transaction too. Their spans show the bootstrap, each action and view, every yii2-httpclient
call and every SQL query (from Yii's DB profiling, with values masked).

The component's `tracing` setting turns each part off, e.g. `'tracing' => ['views' => false]`:

| Part | What it traces |
|---|---|
| `requests` | A transaction for every web request. |
| `commands` | A transaction for every console command, except yii2-queue workers: they run for as long as they wait for jobs, so their jobs are traced one by one instead. It is marked as failed when the command's exit code isn't 0, or can't be known, as when the command ends with `exit()` or `Yii::$app->end()` instead of returning its exit code. |
| `actions` | A span for every controller action. |
| `views` | A span for every view rendered. |
| `httpClient` | A span and a breadcrumb for every yii2-httpclient call, and the trace headers the called service needs to continue the trace. The breadcrumbs and headers are added without tracing too. |
| `database` | A span for every SQL query, from Yii's DB profiling (`enableProfiling`, on by default). |
| `queue` | A transaction for every yii2-queue job a worker runs, named by the job's class, and a span for every job queued. A job run inside a traced request or command, as with the sync queue, is a span of it. |
| `continueTraces` | Continue the trace of the caller, from the `sentry-trace` and `baggage` headers, so that the request's errors and spans show in it; also without tracing. The same for queue jobs that carry the trace of the code that queued them. |

**Traces across services.** Outgoing yii2-httpclient calls carry the `sentry-trace` and `baggage` headers, so that a
service you call, if it uses Sentry too, continues the trace. They are sent to every host unless you list your own in
the SDK's `trace_propagation_targets` option: a third-party API would see your trace ID and, in `baggage`, details
such as your release, environment and route.

```php
'sentrySettings' => [
    'trace_propagation_targets' => ['api.example.com', 'billing.internal'],
],
```

**Traces from the browser.** To show what the browser does and what the server does as one trace, print the meta tags
of Sentry's browser SDK in the `<head>` of your layout:

```php
<?= Yii::$app->sentry->traceMetaTags() ?>
```

Don't print them on pages that are cached as a whole: every visitor would join the same trace.

**Incoming traces** are continued with the caller's decision to trace them. With tracing on, anyone can therefore
send those headers to a public site and have their requests traced, which counts toward your quota: turn
`continueTraces` off, or decide yourself with the SDK's `traces_sampler` option.

## Profiling

Profiles show where the time of a traced request, command or job goes, down to the function. The SDK records them
with the [excimer](https://pecl.php.net/package/excimer) extension: install it, then set a sample rate, the share of
traced transactions to profile:

```php
'sentrySettings' => [
    'traces_sample_rate' => 0.1,
    'profiles_sample_rate' => 0.5,
],
```

From SDK 4.36, continuous profiling records the traced transactions too, with `'profile_session_sample_rate' => 0.1`
and `'profile_lifecycle' => 'trace'` instead of `profiles_sample_rate`. Its sample rate is decided once per process:
a queue worker then profiles all of its jobs, or none.

Profiles cost a little speed while they record, and count toward your Sentry quota. The SDK drops a transaction's
profile when it runs longer than 30 seconds; for long commands, use continuous profiling. A queue worker is profiled job
by job, like it is traced.

## Queue jobs

When [yii2-queue](https://github.com/yiisoft/yii2-queue) is installed, its jobs are reported with nothing to configure:

- **A failed job is an error event** with its exception, named after the job's class, with the job's ID, attempt and
  queue, whether yii2-queue will retry it (the `job.retry` tag), and its public properties, scrubbed like request
  bodies. A worker also reports a job whose process crashed, or ran past its time to reserve.
- **Each job a worker runs has a scope of its own**: its events don't carry what the job before it did, and the log
  messages a job writes are sent when it ends.
- **Queuing a job** is a breadcrumb.
- **With tracing**, each job a worker runs is a transaction, and queuing a job is a span.

`LogBehavior`'s line about a failed job doesn't become a second event, and what it logs is sent without the job's data:
what the job's process printed, or a dump of a job that couldn't be read. The job, as yii2-queue stores it, is kept out
of the arguments in stack traces.

The component's `queue` setting turns each part off:

| Part | Default | What it does |
|---|---|---|
| `errors` | `true` | Report failed jobs. |
| `retries` | `true` | Also report a failed attempt that yii2-queue will retry. Turn it off to report a job only once it has failed for good. |
| `jobData` | `true` | Add the job's public properties to its events, scrubbed like request bodies: secrets always hidden, personal data while `send_default_pii` is off. Never when the `data_collection` option is set with `queues` off. |
| `breadcrumbs` | `true` | A breadcrumb for every job queued, in place of the lines `LogBehavior` logs. |

**One trace for the request and its jobs.** A job can carry the trace of the code that queued it, so that Sentry shows
both as one trace, with the time the job waited in the queue and the job's errors in that trace. yii2-queue stores
nothing but the job itself, so the trace travels in it: implement `TraceableJobInterface` with `TraceableJobTrait`.

```php
use devnullius\yii2\sentry\TraceableJobInterface;
use devnullius\yii2\sentry\TraceableJobTrait;

class SendInvoiceJob extends yii\base\BaseObject implements yii\queue\JobInterface, TraceableJobInterface
{
    use TraceableJobTrait;

    // ...
}
```

> **Deploy your workers first.** The trait adds a property to the job as it is stored. A worker still running the
> class without the property can't read the job: with yii2-queue's `JsonSerializer` when the job extends
> `yii\base\BaseObject`, and otherwise on PHP 8.2 and later when deprecations are reported. yii2-queue drops a job it
> can't read. Deploy the workers before, or together with, the code that queues the job.

## Cron monitoring

[Sentry Crons](https://docs.sentry.io/product/crons/) tells you when a scheduled command fails, takes too long or
doesn't run at all. List the console commands your crontab runs, by route:

```php
'sentry' => [
    'class' => devnullius\yii2\sentry\Component::class,
    'cronMonitors' => [
        // Reports to the monitor "report-daily", set up in Sentry.
        'report/daily',
        // Reports to the monitor "nightly-cleanup", set up in Sentry.
        'cleanup/run' => 'nightly-cleanup',
        // Creates or updates the monitor in Sentry, at every run.
        'billing/charge' => [
            'slug' => 'billing',
            'schedule' => '0 3 * * *',
            'timezone' => 'Europe/Berlin',
            'checkinMargin' => 5,
            'maxRuntime' => 30,
        ],
    ],
],
```

Each run of a listed command tells Sentry when it starts and how it ends: succeeded, with exit code 0, or failed, with
another exit code or a crash. The errors the run reports show with it in Sentry.

| Setting | What it is |
|---|---|
| `slug` | The monitor's name in Sentry, at most 50 characters. By default, the route with `-` for `/`: `report/daily` reports to `report-daily`. |
| `schedule` | When the command runs: a cron expression of five fields, like `0 3 * * *`, or `@hourly`, `@daily`, `@weekly`, `@monthly`, `@yearly`; or a `Sentry\MonitorSchedule`, e.g. an interval. Keep it the same as your crontab line. With a schedule, Sentry creates or updates the monitor from these settings at every run; without one, the monitor must be set up in Sentry. |
| `timezone` | The time zone of the schedule, e.g. `Europe/Berlin`. Sentry assumes UTC. |
| `checkinMargin` | The minutes a run may start late before it counts as missed. |
| `maxRuntime` | The minutes a run may take before it counts as timed out. |
| `failureIssueThreshold` | The failed runs in a row before Sentry opens an issue. |
| `recoveryThreshold` | The successful runs in a row before Sentry resolves it. |

Sentry's defaults apply to the settings you leave out; all but `slug` need a `schedule`. A misconfigured monitor stops
the application from starting, with a message that says what is wrong.

Good to know:

- Each run sends two check-ins, when it starts and when it ends, while the command waits.
- Return the exit code from the command's action. A run that ends otherwise, with `exit()`, `Yii::$app->end()` or an
  `ExitException`, is reported as failed, as its exit code can't be known.
- A run that the command itself skips, e.g. while the previous run still goes, succeeds when it is skipped by a
  `beforeAction` event handler, or in `beforeAction()` after `parent::beforeAction()`. Skipped before that, or by a
  module, it sends nothing, and shows as missed.
- `yii report/daily --help` is not a run. A command that fails on an invalid option, before it starts, shows as missed.
- Only list commands that end. A command that runs for as long as it is up, like `queue/listen`, would show as timed
  out.

## Sentry Logs

[Sentry Logs](https://docs.sentry.io/product/explore/logs/) keeps your log messages searchable next to your errors and
traces. To forward Yii's log messages to it, turn it on in the log target:

```php
'log' => [
    'targets' => [
        [
            'class' => devnullius\yii2\sentry\LogTarget::class,
            'except' => ['yii\web\HttpException:40*'],
            'sentryLogs' => true,
            // The levels forwarded, by default error, warning and info.
            'sentryLogLevels' => ['error', 'warning', 'info'],
        ],
    ],
],
```

Forwarded messages count toward your Sentry quota, and are scrubbed like breadcrumbs: SQL values masked, Yii's own lines
scrubbed. Your own messages are sent as you write them: don't log secrets. The SDK's `before_send_log` option can change
or drop each one before it is sent.

## Long-running processes

Yii keeps log messages in memory and exports them when the process ends, or after 1000 messages. yii2-queue workers
are handled for you: their log messages are sent after each job. For other long-running commands, export every message
right away, so that errors are reported when they happen:

```php
'log' => [
    'flushInterval' => 1,
    'targets' => [
        [
            'class' => devnullius\yii2\sentry\LogTarget::class,
            'exportInterval' => 1,
        ],
    ],
],
```

## Setting up Sentry before Yii starts

To also catch errors that happen before Yii's error handler is registered, e.g. in your configuration files, set up the
SDK right after Composer's autoloader, e.g. in `web/index.php`:

```php
require __DIR__ . '/../vendor/autoload.php';

\Sentry\init([
    'dsn' => getenv('SENTRY_DSN') ?: null,
    // What the component sets on a client of its own.
    'environment' => YII_ENV,
    'prefixes' => [dirname(__DIR__)],
    'in_app_exclude' => [dirname(__DIR__) . '/vendor'],
]);
```

The component then reuses that client (`useExistingHub`): the SDK options and integrations come from your
`\Sentry\init()` call, and the component's `dsn`, `sentrySettings` and SDK integrations are ignored. So are the
settings it gives a client of its own: the environment from `YII_ENV`, file paths relative to the project root, and
vendor code not marked as "in app"; set them in the call, as above. This package's `Integration`, the route, the
breadcrumbs, the user ID and tracing are still added. If that call sets the SDK's `data_collection` option, the request
is left to the SDK.

Once Yii has started, its error handler takes over exceptions and PHP errors, except while it renders an error page,
when the SDK's error listener is back and reports a PHP warning in the error page itself. The SDK's fatal-error listener
stays active, and reports a fatal error before Yii's error handler does. The component then drops the SDK's report, if
the log target reports the error too: Yii's report comes with the request's log messages as breadcrumbs. The SDK reports
the fatal errors that happen before Yii starts.

Without a client set up beforehand, the component creates one as usual. Set `'useExistingHub' => false` to have it
create its own anyway.

## Speed and quota

The SDK sends to Sentry while your code waits: each event, for at most 2 seconds to connect and 5 seconds in all, so
a request that sends several waits for each. What that means here:

- **Web requests**: events and transactions are sent after the response, so visitors don't wait (`sendAfterResponse`,
  under PHP-FPM and LiteSpeed). The PHP worker is busy until they are sent.
- **Errors**: one event for each error and warning. A request that logs many warnings sends many events, and each counts
  toward your quota: keep warnings for what needs attention, or raise the log target's `levels` to `error`.
- **Tracing and profiling**: a little work for every traced request, and quota for every transaction and profile. Start
  with a low sample rate.
- **Cron monitoring**: two check-ins for every run of a listed command.
- **Sentry Logs**: every forwarded message counts toward your quota.

## Upgrading

### From 4.x

5.0 changes how errors and log messages become events, and what is sent about requests: see
[UPGRADE-5.0.md](UPGRADE-5.0.md). [CHANGELOG.md](CHANGELOG.md) has the full list of changes.

### From 1.x

Version 4 moved to Sentry PHP SDK 4: see Sentry's
[upgrade guide](https://github.com/getsentry/sentry-php/blob/master/UPGRADE-4.0.md), then the upgrade from 4.x. You can
remove `php-http/curl-client` and `http-interop/http-factory-guzzle` if you only installed them for this package.

## Development

```bash
composer install
composer test      # PHPUnit
composer analyse   # PHPStan
```

The tests check what would be sent to Sentry; nothing is sent over the network. Besides the `Unit` tests, which build
Yii applications inside PHPUnit, three suites run real processes: `Web` serves a Yii application with PHP's built-in
web server, `Worker` runs yii2-queue workers, and `Console` runs console commands as cron runs them. The tests that
need the excimer extension are skipped without it; CI runs them in a job of their own.

## Credits

- [Oleg Tsvetkov](https://olegtsvetkov.com/), original author
- [Sergei Iamskoi](https://iamskoi.com/)
- [Aram Harutyunyan](https://github.com/aramds/), maintainer, e-mail: [aram.ds@gmail.com](mailto:aram.ds@gmail.com)

Released under the BSD 3-Clause license: see [LICENSE.md](LICENSE.md).
