# yii2-sentry

[![CI](https://github.com/devnullius/yii2-sentry/actions/workflows/ci.yml/badge.svg)](https://github.com/devnullius/yii2-sentry/actions/workflows/ci.yml)
[![Latest Stable Version](https://img.shields.io/packagist/v/devnullius/yii2-sentry)](https://packagist.org/packages/devnullius/yii2-sentry)
[![Total Downloads](https://img.shields.io/packagist/dt/devnullius/yii2-sentry)](https://packagist.org/packages/devnullius/yii2-sentry)
[![License](https://img.shields.io/packagist/l/devnullius/yii2-sentry)](LICENSE.md)

[Sentry](https://sentry.io/) error reporting for [Yii 2](https://www.yiiframework.com/) applications, built on the
official [Sentry PHP SDK](https://github.com/getsentry/sentry-php) 4.

Inspired by the official [sentry-symfony](https://github.com/getsentry/sentry-symfony) and
[sentry-laravel](https://github.com/getsentry/sentry-laravel) packages.

- Reports uncaught exceptions, PHP errors and `Yii::error()` / `Yii::warning()` messages, through Yii's own error
  handler and logger, so your log targets' `levels`, `categories` and `except` rules apply.
- Adds Yii context to every event: the request (with control over personal data), the requested route, a breadcrumb
  for each action, the logged-in user's ID and the other messages logged during the request.
- Works in web and console applications.

## Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Getting started](#getting-started)
- [What gets reported](#what-gets-reported)
- [Configuration](#configuration)
- [Personal data](#personal-data)
- [Console applications and queue workers](#console-applications-and-queue-workers)
- [Setting up Sentry before Yii starts](#setting-up-sentry-before-yii-starts)
- [Performance monitoring](#performance-monitoring)
- [Known limitations in 4.x](#known-limitations-in-4x)
- [Upgrading](#upgrading)
- [Development](#development)
- [Credits](#credits)

## Requirements

- PHP 7.4 or 8.x, with the `curl`, `json` and `mbstring` extensions
- Yii 2.0.55 or later
- Sentry PHP SDK (`sentry/sentry`) 4.33 or later, installed automatically

## Installation

```bash
composer require devnullius/yii2-sentry:^4.1
```

The SDK sends events with its own cURL-based HTTP client; no other HTTP packages are needed.

## Getting started

Register the component, bootstrap it, and add the log target to your application config:

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
                    'levels' => ['error', 'warning'],
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

## What gets reported

- **Uncaught exceptions** and **`Yii::error()` / `Yii::warning()` messages**, through `LogTarget` and the `levels`,
  `categories` and `except` you configure for it.
- **PHP warnings, notices and fatal errors**, through Yii's error handler and through the SDK's error listener
  integrations (see [Known limitations](#known-limitations-in-4x) about duplicates).

Every event carries:

- **The request**: method, URL, query string and headers, and for POST, PUT and PATCH requests the body, both raw
  (`request.data`) and parsed (the `decodedParams` additional data). See [Personal data](#personal-data) for what is
  filtered.
- **The `route` tag**: the route that was requested, even when the error is reported while the error action runs.
- **Breadcrumbs**: one for each action run during the request, with the action's class and method.
- **The user ID**, when the user logs in during the request (see [Known limitations](#known-limitations-in-4x)).
- **The `category` tag** of the log message, and the **other messages** logged during the same request under the
  `logs` additional data.
- **The environment**: `SENTRY_ENVIRONMENT` if that environment variable is set, otherwise `YII_ENV`.

`LogTarget` sends **one event per export**: the most severe message logged since the last export (the latest one, if
several share that level). The other messages are attached to it under `logs`. In web requests, that means one event
per request. See [Console applications and queue workers](#console-applications-and-queue-workers) for long-running
processes.

## Configuration

### Component (`devnullius\yii2\sentry\Component`)

| Property | Default | Description |
|---|---|---|
| `dsn` | `null` | The project's DSN. When it is not set, the SDK reads the `SENTRY_DSN` environment variable. Set it to `false` to disable sending events. |
| `sentrySettings` | `[]` | [SDK options](https://docs.sentry.io/platforms/php/configuration/options/), passed as is, e.g. `release`, `sample_rate`, `traces_sample_rate`, `before_send`. They take precedence over the component's defaults. Use the `integrations` property below rather than an `integrations` key here. |
| `integrations` | see below | SDK integrations, as class names, Yii configuration arrays or instances. Setting it replaces the default list. |
| `useExistingHub` | `false` | Reuse a client already set up with `\Sentry\init()`; see [Setting up Sentry before Yii starts](#setting-up-sentry-before-yii-starts). |
| `appBasePath` | `'@app'` | Used to shorten file paths in stack traces and to tell your code from vendor code. |

The default `integrations` list:

```php
[
    devnullius\yii2\sentry\Integration::class,  // request data, with the personal-data filtering described below
    Sentry\Integration\FatalErrorListenerIntegration::class,
    Sentry\Integration\TransactionIntegration::class,
    Sentry\Integration\FrameContextifierIntegration::class,
    Sentry\Integration\ErrorListenerIntegration::class,
    Sentry\Integration\EnvironmentIntegration::class,
    Sentry\Integration\ModulesIntegration::class,
    Sentry\Integration\RequestIntegration::class,
]
```

The SDK's own exception listener is deliberately not used: Yii already handles uncaught exceptions, and `LogTarget`
reports them, so both would report every exception.

The SDK reads `SENTRY_RELEASE` from the environment; alternatively, set `release` in `sentrySettings`.

The client is created with `Sentry\ClientBuilder`, resolved from Yii's DI container with the options as its only
argument. To customize it, e.g. to set a PSR-3 logger for the SDK's own diagnostics:

```php
Yii::$container->set(Sentry\ClientBuilder::class, static function ($container, array $params): Sentry\ClientBuilder {
    $builder = new Sentry\ClientBuilder($params[0]);
    $builder->setLogger($myPsrLogger);

    return $builder;
});
```

### Log target (`devnullius\yii2\sentry\LogTarget`)

A regular Yii [log target](https://www.yiiframework.com/doc/guide/2.0/en/runtime-logging): `levels`, `categories`,
`except`, `exportInterval`, `prefix`, … all apply. In addition:

| Property | Default | Description |
|---|---|---|
| `component` | `'sentry'` | The ID of the Sentry application component, or the component itself. |

Messages that this package logs about its own failures (category `devnullius\yii2\sentry\*`) are never sent to Sentry;
they reach your other log targets.

## Personal data

By default, the SDK's `send_default_pii` option is `false`. In that mode:

- the `Cookie` and `Set-Cookie` headers are removed;
- the `Authorization`, `Proxy-Authorization`, `X-Forwarded-For` and `X-Real-IP` headers are replaced with
  `[Filtered PII]`;
- no IP address is sent, and the lines in `logs` don't include the client IP, user ID or session ID that Yii adds to
  log messages by default.

**Request bodies are sent** for POST, PUT and PATCH requests, unless you filter their fields per route with
`piiBodyFields` (below). Sentry's [server-side data scrubbing](https://docs.sentry.io/security-legal-pii/scrubbing/)
can mask more, but don't rely on it alone for passwords and payment data.

With `'send_default_pii' => true` in `sentrySettings`, all headers and cookies are sent, the user's IP address is set
from `yii\web\Request::getUserIP()` (which honors `trustedHosts` when the application runs behind a proxy), and log
lines keep Yii's `[ip][userId][sessionId]` prefix. `piiBodyFields` is not applied in this mode.

### Filtering headers and body fields

Configure the package's `Integration`. As setting `integrations` replaces the default list, keep the entries you need:

```php
'sentry' => [
    'class' => devnullius\yii2\sentry\Component::class,
    'integrations' => [
        [
            'class' => devnullius\yii2\sentry\Integration::class,
            // Headers that are not sent at all (lower case)
            'stripHeaders' => ['cookie', 'set-cookie'],
            // Headers whose values are replaced (lower case)
            'piiHeaders' => ['authorization', 'proxy-authorization', 'x-forwarded-for', 'x-real-ip', 'x-api-key'],
            // Body fields whose values are replaced, per route
            'piiBodyFields' => [
                'account/login' => [
                    'email',
                    'password',
                ],
                // Nested fields, e.g. the ones a Yii ActiveForm posts as LoginForm[password]
                'site/login' => [
                    'LoginForm' => [
                        'password',
                    ],
                ],
            ],
            // Text that replaces filtered values
            'piiReplaceText' => '[Filtered PII]',
        ],
        Sentry\Integration\FatalErrorListenerIntegration::class,
        Sentry\Integration\TransactionIntegration::class,
        Sentry\Integration\FrameContextifierIntegration::class,
        Sentry\Integration\ErrorListenerIntegration::class,
        Sentry\Integration\EnvironmentIntegration::class,
        Sentry\Integration\ModulesIntegration::class,
        Sentry\Integration\RequestIntegration::class,
    ],
],
```

Field names match exactly and from the top of the body: list nested fields under their parent, as above. When a route
has `piiBodyFields`, `request.data` holds a placeholder and the filtered fields are in `decodedParams`. The body is
left out entirely when it can't be parsed, and, while `piiBodyFields` is configured, when the request fails before its
route is resolved, since no route's rules can be applied then.

### The SDK's `data_collection` option

Sentry PHP SDK 4.33 added a `data_collection` option, which replaces `send_default_pii` with finer-grained controls.
When it is set, in `sentrySettings` or on a client reused with `useExistingHub`, this package leaves the request to the
SDK's `RequestIntegration`, which applies that policy: `stripHeaders`, `piiHeaders` and `piiBodyFields` don't apply,
and `decodedParams` is not added. Keep `RequestIntegration` in `integrations` in that case.

## Console applications and queue workers

Configure the component and `LogTarget` in the console application the same way; request data is skipped
automatically.

Yii keeps log messages in memory and exports them when the process ends or after 1000 messages. A long-running
process, like a queue worker, would therefore report its errors late, and only one of them per export. Make the logger
export every message right away in your console config:

```php
'log' => [
    'flushInterval' => 1,
    'targets' => [
        [
            'class' => devnullius\yii2\sentry\LogTarget::class,
            'levels' => ['error', 'warning'],
            'exportInterval' => 1,
        ],
    ],
],
```

Each error and warning then becomes its own event.

## Setting up Sentry before Yii starts

To also catch errors that happen before Yii's error handler is registered, set up the SDK right after Composer's
autoloader, e.g. in `web/index.php`:

```php
require __DIR__ . '/../vendor/autoload.php';

\Sentry\init([
    'dsn' => getenv('SENTRY_DSN') ?: null,
    'environment' => 'production',
]);
```

Then let the component reuse that client:

```php
'sentry' => [
    'class' => devnullius\yii2\sentry\Component::class,
    'useExistingHub' => true,
],
```

The SDK options and integrations then come from your `\Sentry\init()` call: the component's `dsn`, `sentrySettings`
and SDK integrations are ignored, while this package's `Integration` (request data and personal-data filtering), the
route tag, breadcrumbs and user ID are still added. If that call sets the SDK's `data_collection` option, the request is
left to the SDK (see [The SDK's `data_collection` option](#the-sdks-data_collection-option)). Once Yii starts, its error handler takes over uncaught exceptions,
so they are still reported once. Without a client set up beforehand, the component creates one as usual.

## Performance monitoring

Tracing is off unless you set a sample rate:

```php
'sentrySettings' => [
    'traces_sample_rate' => 0.2,
],
```

In 4.x, tracing is basic: each controller action is recorded as a transaction, and each
[yii2-httpclient](https://github.com/yiisoft/yii2-httpclient) request as a separate one. It will be reworked in 5.0:
see [Known limitations](#known-limitations-in-4x).

## Known limitations in 4.x

These are planned to change in 5.0:

- **PHP warnings and fatal errors can be reported twice**: once by the SDK's error listeners and once through Yii's
  error handler and `LogTarget`. To avoid it, remove `ErrorListenerIntegration` and `FatalErrorListenerIntegration`
  from `integrations`; Yii's error handler and `LogTarget` still report them.
- **The user ID is only attached on the request where the user logs in**: Yii doesn't trigger the login event when it
  restores the identity from the session on later requests.
- **`LogTarget` sends one event per export**, as described in [What gets reported](#what-gets-reported).
- **Exceptions are marked as handled**, including uncaught ones, which affects Sentry's "unhandled" filters and
  crash-free statistics.
- **Building several applications in one process** (as some test suites do) registers the component's event handlers
  once more for each application, so actions get recorded several times.
- **Vendor code is marked as application code** in stack traces, which affects how Sentry groups issues. To fix it,
  point `in_app_exclude` at your vendor directory, e.g. in `config/web.php` of the basic application template:

  ```php
  'sentrySettings' => [
      'in_app_include' => [],
      'in_app_exclude' => [dirname(__DIR__) . '/vendor'],
  ],
  ```

- **Tracing**: transactions are named after the controller (the route is in the operation), they aren't sent when
  the action fails, nested actions replace the outer transaction, and yii2-httpclient requests are recorded as
  separate transactions rather than as spans of the request.

## Upgrading

### From 4.0

- PHP 7.4, Yii 2.0.55 and Sentry PHP SDK 4.33 are now required.
- `sentry/sdk`, `symfony/psr-http-message-bridge` and `nyholm/psr7` are no longer installed by this package. Require
  them in your application if you use them directly.
- When `dsn` is not set, the `SENTRY_DSN` environment variable is used; set `dsn` to `false` to disable sending events
  explicitly. `SENTRY_ENVIRONMENT`, when set, takes precedence over `YII_ENV`.
- `request.url` is now the absolute URL, without the query string (which stays in `query_string`).
- The `route` tag now always holds the route that was requested.
- With `send_default_pii` off, log lines in `logs` no longer include the client IP, user ID and session ID, and the
  `Proxy-Authorization`, `X-Forwarded-For` and `X-Real-IP` headers are filtered.

See [CHANGELOG.md](CHANGELOG.md) for the full list of changes.

### From 1.x

Version 4 uses Sentry PHP SDK 4: see Sentry's
[upgrade guide](https://github.com/getsentry/sentry-php/blob/master/UPGRADE-4.0.md). You can remove
`php-http/curl-client` and `http-interop/http-factory-guzzle` if you only installed them for this package.

## Development

```bash
composer install
composer test      # PHPUnit
composer analyse   # PHPStan
```

The `Web` test suite serves a Yii application with PHP's built-in web server and checks what would be sent to Sentry;
nothing is sent over the network.

## Credits

- [Oleg Tsvetkov](https://olegtsvetkov.com/), original author
- [Sergei Iamskoi](https://iamskoi.com/)
- [Aram Harutyunyan](https://github.com/aramds/), maintainer, e-mail: [aram.ds@gmail.com](mailto:aram.ds@gmail.com)

Released under the BSD 3-Clause license: see [LICENSE.md](LICENSE.md).
