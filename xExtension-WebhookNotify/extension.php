<?php
declare(strict_types=1);

/**
 * FreshRSS Webhook Notify Extension
 *
 * Sends new articles to a configured webhook URL as JSON POST requests.
 * Designed for integration with Tracecat SOAR (or any webhook consumer).
 *
 * Configuration (per-user):
 *   - webhook_url: Full URL including secret path (HTTPS enforced)
 *   - feed_filter: Comma-separated feed name substrings to match (empty = all feeds)
 *   - timeout: HTTP request timeout in seconds (default: 10)
 */
class WebhookNotifyExtension extends Minz_Extension {

	/** @var int Maximum content length sent to webhook (bytes) */
	private const MAX_CONTENT_LENGTH = 50000;

	/** @var int Default HTTP timeout */
	private const DEFAULT_TIMEOUT = 10;

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

		if (empty($config['webhook_url'])) {
			return $entry;
		}

		$webhookUrl = trim((string)$config['webhook_url']);

		// Security: only allow HTTPS endpoints
		if (!str_starts_with($webhookUrl, 'https://')) {
			Minz_Log::warning('[WebhookNotify] Skipping non-HTTPS webhook URL');
			return $entry;
		}

		// Apply feed filter (if configured)
		if (!$this->matchesFeedFilter($entry, $config)) {
			return $entry;
		}

		$payload = $this->buildPayload($entry);
		$timeout = (int)($config['timeout'] ?? self::DEFAULT_TIMEOUT);

		$this->sendWebhook($webhookUrl, $payload, $timeout);

		return $entry;
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
			'author' => $entry->author(),
		];
	}

	/**
	 * Send the webhook POST request.
	 *
	 * Fire-and-forget: failures are logged but never block article insertion.
	 *
	 * @param string $url
	 * @param array<string,mixed> $payload
	 * @param int $timeout
	 */
	private function sendWebhook(string $url, array $payload, int $timeout): void {
		$json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if ($json === false) {
			Minz_Log::warning('[WebhookNotify] Failed to encode payload as JSON');
			return;
		}

		$context = stream_context_create([
			'http' => [
				'method' => 'POST',
				'header' => "Content-Type: application/json\r\n" .
							"User-Agent: FreshRSS-WebhookNotify/1.0\r\n",
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

		// Log failures but don't block article insertion
		if ($result === false) {
			$error = error_get_last();
			Minz_Log::warning(
				'[WebhookNotify] Webhook request failed: ' .
				($error['message'] ?? 'unknown error')
			);
		} else {
			Minz_Log::debug('[WebhookNotify] Webhook sent for: ' . $payload['title']);
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
			$config = [
				'webhook_url' => trim(Minz_Request::paramString('webhook_url')),
				'feed_filter' => trim(Minz_Request::paramString('feed_filter')),
				'timeout' => max(1, min(30, Minz_Request::paramInt('timeout') ?: self::DEFAULT_TIMEOUT)),
			];

			// Validate URL
			if (!empty($config['webhook_url']) && !str_starts_with($config['webhook_url'], 'https://')) {
				$config['webhook_url'] = '';
				Minz_Log::warning('[WebhookNotify] Rejected non-HTTPS webhook URL in configuration');
			}

			$this->setUserConfiguration($config);
		}
	}
}
