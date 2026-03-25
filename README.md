# FreshRSS Webhook Notify Extension

A [FreshRSS](https://freshrss.org/) extension that sends new articles to a webhook URL as JSON POST requests. Useful for integrating RSS feeds with SOAR platforms, automation tools, or any webhook-compatible service.

## Features

- **HTTPS enforced** — webhook URLs must use HTTPS
- **Feed filtering** — optionally limit which feeds trigger webhooks (comma-separated substring matching, case-insensitive)
- **Configurable timeout** — 1–30 seconds, keeps feed refresh fast
- **Fire-and-forget** — webhook failures are logged but never block article insertion
- **HTML stripping** — article content is sent as plain text
- **Content truncation** — articles over 50KB are truncated to avoid oversized payloads
- **Per-user configuration** — each FreshRSS user can configure their own webhook independently

## Installation

1. Copy the `xExtension-WebhookNotify` directory into your FreshRSS `extensions/` directory:

   ```bash
   cp -r xExtension-WebhookNotify /path/to/FreshRSS/extensions/
   ```

   For Docker installations, copy into the extensions volume:

   ```bash
   docker cp xExtension-WebhookNotify freshrss:/var/www/FreshRSS/extensions/
   ```

2. In FreshRSS, go to **Settings → Extensions** and enable **Webhook Notify**.

3. Click **Configure** to set your webhook URL and optional feed filters.

## Configuration

| Field | Description |
|-------|-------------|
| **Enable webhook** | Toggle the extension on/off |
| **Webhook URL** | Full HTTPS URL including any secret path segments |
| **Feed filter** | Comma-separated substrings to match against feed names (empty = all feeds) |
| **Timeout** | HTTP request timeout in seconds (1–30, default: 10) |

## Webhook Payload

Each new article triggers a POST request with this JSON body:

```json
{
  "title": "Article Title",
  "url": "https://example.com/article",
  "content": "Plain text content of the article...",
  "feed_title": "Feed Name",
  "published": "2025-01-15T10:30:00+00:00",
  "author": "Author Name"
}
```

## TLS / Custom CA

If your webhook endpoint uses a certificate signed by a private CA, you'll need to add your CA certificate to the FreshRSS container's trust store. Mount the CA cert and run `update-ca-certificates` at startup:

```yaml
# docker-compose.yml
services:
  freshrss:
    volumes:
      - /path/to/ca.pem:/usr/local/share/ca-certificates/custom-ca.crt:ro
    command: >-
      /bin/bash -o pipefail -c
      "update-ca-certificates &&
       ([ -z \"$$CRON_MIN\" ] || cron) &&
       . /etc/apache2/envvars &&
       exec apache2 -D FOREGROUND"
```

## Requirements

- FreshRSS 1.20+ (PHP 8.1+)
- `allow_url_fopen` enabled in PHP (default)

## License

MIT
