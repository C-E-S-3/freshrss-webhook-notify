<?php
declare(strict_types=1);

/**
 * FreshRSS Webhook Notify Extension
 *
 * Sends new articles to one or more configured webhook endpoints as JSON POST requests.
 * Supports generic webhooks (e.g. Tracecat) and GitHub repository dispatch events.
 *
 * Configuration (per-user):
 *   - webhooks: Array of webhook endpoint configs (type, url/repo, token, etc.)
 *   - feed_filter: Comma-separated feed name substrings to match (empty = all feeds)
 *   - timeout: HTTP request timeout in seconds (default: 10)
 */
class WebhookNotifyExtension extends Minz_Extension {

	/** @var int Maximum content length sent to webhook (bytes) */
	private const MAX_CONTENT_LENGTH = 50000;

	/** @var int Default HTTP timeout */
	private const DEFAULT_TIMEOUT = 10;

	/** @var int Maximum number of webhook endpoints */
	private const MAX_WEBHOOKS = 10;

	public function init(): void {
		parent::init();
		$this->registerHook('entry_before_insert', [$this, 'onEntryBeforeInsert']);
	}

	/**
	 * Hook: called for each new article before it is saved to the database.
	 *
	 * @param FreshRSS_Entry $entry The article being inserted.
	 * @return FreshRSS_Entry The (unmodified) entry — we never alter articles.
	 */
	public function onEntryBeforeInsert(FreshRSS_Entry $entry): FreshRSS_Entry {
		$config = $this->getUserConfiguration();
		$config = $this->migrateConfig($config);

		$webhooks = $config['webhooks'] ?? [];
		if (empty($webhooks)) {
			return $entry;
		}

		// Apply feed filter (if configured)
		if (!$this->matchesFeedFilter($entry, $config)) {
			return $entry;
		}

		$payload = $this->buildPayload($entry);
		$timeout = (int)($config['timeout'] ?? self::DEFAULT_TIMEOUT);

		foreach ($webhooks as $webhook) {
			if (empty($webhook['enabled'])) {
				continue;
			}

			$type = $webhook['type'] ?? 'generic';

			if ($type === 'github') {
				$this->sendGitHubDispatch($webhook, $payload, $timeout);
			} else {
				$url = trim((string)($webhook['url'] ?? ''));
				if ($url !== '' && str_starts_with($url, 'https://')) {
					$this->sendWebhook($url, $payload, $timeout, []);
				}
			}
		}

		return $entry;
	}

	/**
	 * Migrate legacy single-webhook config to multi-webhook format.
	 *
	 * @param array<string,mixed> $config
	 * @return array<string,mixed>
	 */
	private function migrateConfig(array $config): array {
		if (isset($config['webhooks'])) {
			return $config;
		}

		// Migrate from legacy single webhook_url
		if (!empty($config['webhook_url'])) {
			$config['webhooks'] = [
				[
					'type' => 'generic',
					'url' => trim((string)$config['webhook_url']),
					'enabled' => true,
				],
			];
		} else {
			$config['webhooks'] = [];
		}

		unset($config['webhook_url']);
		return $config;
	}

	/**
	 * Check if the entry's feed matches the configured filter.
	 *
	 * @param FreshRSS_Entry $entry
	 * @param array<string,mixed> $config
	 * @return bool True if the entry should be sent to the webhook.
	 */
	private function matchesFeedFilter(FreshRSS_Entry $entry, array $config): bool {
		$filterStr = trim((string)($config['feed_filter'] ?? ''));

		// Empty filter means all feeds match
		if ($filterStr === '') {
			return true;
		}

		$feed = $entry->feed();
		if ($feed === null) {
			return false;
		}

		$feedName = strtolower($feed->name());
		$filters = array_map('trim', explode(',', strtolower($filterStr)));

		foreach ($filters as $filter) {
			if ($filter !== '' && str_contains($feedName, $filter)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Build the JSON payload from an entry.
	 *
	 * @param FreshRSS_Entry $entry
	 * @return array<string,mixed>
	 */
	private function buildPayload(FreshRSS_Entry $entry): array {
		$content = $entry->content(false);

		// Strip HTML tags for cleaner downstream processing
		$content = strip_tags($content);

		// Truncate excessively long content
		if (strlen($content) > self::MAX_CONTENT_LENGTH) {
			$content = substr($content, 0, self::MAX_CONTENT_LENGTH) . "\n\n[truncated]";
		}

		$feed = $entry->feed();

		return [
			'title' => $entry->title(),
			'url' => $entry->link(),
			'content' => $content,
			'feed_title' => $feed !== null ? $feed->name() : 'unknown',
			'published' => date('c', (int)$entry->date(true)),
			'authors' => $entry->authors(),
		];
	}

	/**
	 * Send a GitHub repository_dispatch event.
	 *
	 * @param array<string,mixed> $webhook Webhook config with repo, token, event_type
	 * @param array<string,mixed> $payload Article payload
	 * @param int $timeout
	 */
	private function sendGitHubDispatch(array $webhook, array $payload, int $timeout): void {
		$repo = trim((string)($webhook['repo'] ?? ''));
		$token = trim((string)($webhook['token'] ?? ''));
		$eventType = trim((string)($webhook['event_type'] ?? 'threat-intel-scrape'));

		if ($repo === '' || $token === '') {
			Minz_Log::warning('[WebhookNotify] GitHub dispatch missing repo or token');
			return;
		}

		// Validate repo format: OWNER/REPO
		if (!preg_match('#^[a-zA-Z0-9._-]+/[a-zA-Z0-9._-]+$#', $repo)) {
			Minz_Log::warning('[WebhookNotify] Invalid GitHub repo format: ' . $repo);
			return;
		}

		$url = 'https://api.github.com/repos/' . $repo . '/dispatches';

		$dispatchPayload = [
			'event_type' => $eventType,
			'client_payload' => [
				'target_url' => $payload['url'],
			],
		];

		$headers = [
			'Authorization: Bearer ' . $token,
			'Accept: application/vnd.github+v3+json',
		];

		$this->sendWebhook($url, $dispatchPayload, $timeout, $headers);
	}

	/**
	 * Send a webhook POST request.
	 *
	 * Fire-and-forget: failures are logged but never block article insertion.
	 *
	 * @param string $url
	 * @param array<string,mixed> $payload
	 * @param int $timeout
	 * @param string[] $extraHeaders Additional HTTP headers
	 */
	private function sendWebhook(string $url, array $payload, int $timeout, array $extraHeaders): void {
		$json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if ($json === false) {
			Minz_Log::warning('[WebhookNotify] Failed to encode payload as JSON');
			return;
		}

		$headerStr = "Content-Type: application/json\r\n" .
					 "User-Agent: FreshRSS-WebhookNotify/2.0\r\n";

		foreach ($extraHeaders as $header) {
			$headerStr .= $header . "\r\n";
		}

		$context = stream_context_create([
			'http' => [
				'method' => 'POST',
				'header' => $headerStr,
				'content' => $json,
				'timeout' => $timeout,
				'ignore_errors' => true,
			],
			'ssl' => [
				'verify_peer' => true,
				'verify_peer_name' => true,
			],
		]);

		$result = @file_get_contents($url, false, $context);

		$title = $payload['title'] ?? $payload['client_payload']['title'] ?? 'unknown';

		// Log failures but don't block article insertion
		if ($result === false) {
			$error = error_get_last();
			Minz_Log::warning(
				'[WebhookNotify] Webhook request failed (' . $url . '): ' .
				($error['message'] ?? 'unknown error')
			);
		} else {
			Minz_Log::debug('[WebhookNotify] Webhook sent to ' . $url . ' for: ' . $title);
		}
	}

	/**
	 * Handle the extension configuration form.
	 */
	public function handleConfigureAction(): void {
		parent::handleConfigureAction();

		if (FreshRSS_Auth::requestReauth()) {
			return;
		}

		if (Minz_Request::isPost()) {
			$webhooks = [];

			// Parse webhook entries from form
			for ($i = 0; $i < self::MAX_WEBHOOKS; $i++) {
				$type = trim(Minz_Request::paramString('webhook_type_' . $i));
				if ($type === '') {
					continue;
				}

				$enabled = Minz_Request::paramString('webhook_enabled_' . $i) === '1';

				if ($type === 'github') {
					$repo = trim(Minz_Request::paramString('webhook_repo_' . $i));
					$token = trim(Minz_Request::paramString('webhook_token_' . $i));
					$eventType = trim(Minz_Request::paramString('webhook_event_type_' . $i));

					if ($repo === '' && $token === '') {
						continue; // Skip empty entries
					}

					if ($eventType === '') {
						$eventType = 'threat-intel-scrape';
					}

					$webhooks[] = [
						'type' => 'github',
						'repo' => $repo,
						'token' => $token,
						'event_type' => $eventType,
						'enabled' => $enabled,
					];
				} else {
					$url = trim(Minz_Request::paramString('webhook_url_' . $i));

					if ($url === '') {
						continue; // Skip empty entries
					}

					// Validate HTTPS
					if (!str_starts_with($url, 'https://')) {
						Minz_Log::warning('[WebhookNotify] Rejected non-HTTPS webhook URL in configuration');
						continue;
					}

					$webhooks[] = [
						'type' => 'generic',
						'url' => $url,
						'enabled' => $enabled,
					];
				}
			}

			$config = [
				'webhooks' => $webhooks,
				'feed_filter' => trim(Minz_Request::paramString('feed_filter')),
				'timeout' => max(1, min(30, Minz_Request::paramInt('timeout') ?: self::DEFAULT_TIMEOUT)),
			];

			$this->setUserConfiguration($config);
		}
	}
}
