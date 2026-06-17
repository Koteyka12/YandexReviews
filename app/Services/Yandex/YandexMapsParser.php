<?php

namespace App\Services\Yandex;

use Carbon\Carbon;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class YandexMapsParser
{
    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124 Safari/537.36';

    public function scrape(string $url): array
    {
        $this->assertYandexUrl($url);

        $jar = new CookieJar();
        $pageResponse = $this->client($jar)->get($url);

        if (! $pageResponse->ok()) {
            throw new YandexMapsParserException('Карточка Яндекс.Карт недоступна.');
        }

        $html = $pageResponse->body();
        $state = $this->extractInitialState($html);
        $config = $state['config'] ?? [];
        $businessId = (string) data_get($config, 'query.orgpage.id', '');

        if ($businessId === '') {
            throw new YandexMapsParserException('Не удалось определить id организации в ссылке.');
        }

        $business = $this->findBusinessNode($state, $businessId) ?? [];
        $loaded = $this->fetchAllReviews($jar, $config, $businessId, $url);
        $meta = $this->extractOrganizationMeta($html, $business, $businessId, $url);

        if (($meta['review_count'] ?? 0) === 0 && isset($loaded['params']['count'])) {
            $meta['review_count'] = (int) $loaded['params']['count'];
        }

        $meta['scraped_reviews_count'] = count($loaded['reviews']);

        return [
            'organization' => $meta,
            'reviews' => $loaded['reviews'],
            'raw' => [
                'business' => $business,
                'review_params' => $loaded['params'],
            ],
        ];
    }

    private function client(CookieJar $jar): PendingRequest
    {
        return Http::timeout((int) config('services.yandex_maps.timeout', 20))
            ->retry(1, 500)
            ->withOptions([
                'allow_redirects' => true,
                'cookies' => $jar,
            ])
            ->withHeaders([
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,application/json;q=0.8,*/*;q=0.7',
                'Accept-Language' => 'ru-RU,ru;q=0.9,en;q=0.8',
                'User-Agent' => self::USER_AGENT,
            ]);
    }

    private function assertYandexUrl(string $url): void
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if (! preg_match('/(^|\.)yandex\.[a-z.]+$/', $host) && ! preg_match('/(^|\.)ya\.ru$/', $host)) {
            throw new YandexMapsParserException('Нужна ссылка на карточку организации в Яндекс.Картах.');
        }
    }

    private function extractInitialState(string $html): array
    {
        preg_match_all('/<script\b[^>]*>(.*?)<\/script>/is', $html, $matches);

        foreach ($matches[1] ?? [] as $script) {
            $candidate = trim($script);

            if (! str_starts_with($candidate, '{"config"')) {
                continue;
            }

            $decoded = json_decode($candidate, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        throw new YandexMapsParserException('Яндекс изменил стартовый JSON карточки или вернул пустую страницу.');
    }

    private function findBusinessNode(mixed $node, string $businessId): ?array
    {
        if (! is_array($node)) {
            return null;
        }

        if ((string) ($node['id'] ?? '') === $businessId && isset($node['ratingData'])) {
            return $node;
        }

        foreach ($node as $child) {
            $found = $this->findBusinessNode($child, $businessId);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    private function extractOrganizationMeta(string $html, array $business, string $businessId, string $sourceUrl): array
    {
        $ratingData = $business['ratingData'] ?? [];

        return [
            'yandex_id' => $businessId,
            'source_url' => $sourceUrl,
            'canonical_url' => $this->canonicalUrl($html) ?? $sourceUrl,
            'title' => $business['title'] ?? $business['shortTitle'] ?? $this->ogTitle($html),
            'address' => $business['fullAddress'] ?? $business['address'] ?? null,
            'rating_value' => $this->floatOrNull(data_get($ratingData, 'ratingValue') ?? $this->metaItemProp($html, 'ratingValue')),
            'rating_count' => (int) (data_get($ratingData, 'ratingCount') ?? $this->metaItemProp($html, 'ratingCount') ?? 0),
            'review_count' => (int) (data_get($ratingData, 'reviewCount') ?? $this->metaItemProp($html, 'reviewCount') ?? 0),
        ];
    }

    private function fetchAllReviews(CookieJar $jar, array $config, string $businessId, string $sourceUrl): array
    {
        $page = 1;
        $pageSize = (int) config('services.yandex_maps.page_size', 50);
        $maxReviews = (int) config('services.yandex_maps.max_reviews', 600);
        $csrfToken = (string) data_get($config, 'csrfToken');
        $reviews = [];
        $seen = [];
        $lastParams = [];

        do {
            $data = $this->fetchReviewsPage($jar, $config, $businessId, $sourceUrl, $page, $pageSize, $csrfToken);
            $items = $data['reviews'] ?? [];
            $lastParams = $data['params'] ?? $lastParams;

            if (! is_array($items) || $items === []) {
                break;
            }

            foreach ($items as $item) {
                $review = $this->normalizeReview($item);
                $key = $review['yandex_review_id'];

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $reviews[] = $review;

                if ($maxReviews > 0 && count($reviews) >= $maxReviews) {
                    break 2;
                }
            }

            $totalPages = max(1, (int) ($lastParams['totalPages'] ?? $page));
            $remained = (int) ($lastParams['reviewsRemained'] ?? 0);
            $page++;
        } while ($page <= $totalPages && $remained > 0);

        return [
            'reviews' => $reviews,
            'params' => $lastParams,
        ];
    }

    private function fetchReviewsPage(
        CookieJar $jar,
        array $config,
        string $businessId,
        string $sourceUrl,
        int $page,
        int $pageSize,
        string &$csrfToken,
        bool $allowCsrfRetry = true,
    ): array {
        $query = [
            'ajax' => '1',
            'businessId' => $businessId,
            'csrfToken' => $csrfToken,
            'host_config' => $this->queryValue(data_get($config, 'hostConfig', [])),
            'host_exp' => $this->queryValue(data_get($config, 'hostExp')),
            'locale' => data_get($config, 'locale', 'ru_RU'),
            'page' => $page,
            'pageSize' => $pageSize,
            'patch' => data_get($config, 'experiments.ui.ugcReviewsPatch'),
            'ranking' => 'by_time',
            'reqId' => data_get($config, 'requestId'),
            'sessionId' => data_get($config, 'counters.analytics.sessionId', data_get($config, 'requestId')),
            'ugc_params' => data_get($config, 'query.ugc_params'),
        ];

        $query = array_filter($query, static fn ($value) => $value !== null);
        $query['s'] = $this->signature($query);
        $url = 'https://yandex.ru/maps/api/business/fetchReviews?'.$this->queryString($query);

        $response = $this->client($jar)
            ->withHeaders([
                'Accept' => 'application/json, text/plain, */*',
                'Referer' => $sourceUrl,
                'X-Retpath-Y' => $sourceUrl,
            ])
            ->get($url);

        if (! $response->ok()) {
            throw new YandexMapsParserException('Яндекс вернул ошибку при загрузке отзывов.');
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw new YandexMapsParserException('Яндекс вернул невалидный ответ вместо JSON.');
        }

        if (($json['type'] ?? null) === 'captcha') {
            throw new YandexMapsParserException('Яндекс показал captcha. Нужен повтор позже или другой способ доступа.');
        }

        if (isset($json['csrfToken']) && $allowCsrfRetry) {
            $csrfToken = (string) $json['csrfToken'];

            return $this->fetchReviewsPage($jar, $config, $businessId, $sourceUrl, $page, $pageSize, $csrfToken, false);
        }

        if (isset($json['error'])) {
            $message = data_get($json, 'error.message', 'Яндекс вернул ошибку API отзывов.');

            throw new YandexMapsParserException($message);
        }

        if (! isset($json['data']) || ! is_array($json['data'])) {
            throw new YandexMapsParserException('В ответе Яндекса нет блока data с отзывами.');
        }

        return $json['data'];
    }

    private function normalizeReview(array $review): array
    {
        $reviewId = (string) ($review['reviewId'] ?? $review['id'] ?? '');

        if ($reviewId === '') {
            $reviewId = md5(json_encode($review, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: Str::uuid()->toString());
        }

        return [
            'yandex_review_id' => $reviewId,
            'author' => data_get($review, 'author.name'),
            'reviewed_at' => $this->dateOrNull($review['updatedTime'] ?? $review['createdTime'] ?? null),
            'text' => $review['text'] ?? null,
            'rating' => isset($review['rating']) ? (int) $review['rating'] : null,
            'raw_payload' => $review,
        ];
    }

    private function queryValue(mixed $value): mixed
    {
        if ($value === [] || $value === (object) []) {
            return '';
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $value;
    }

    private function signature(array $query): string
    {
        $string = $this->queryString($query);
        $hash = 5381;

        foreach (str_split($string) as $char) {
            $hash = (($hash * 33) ^ ord($char)) & 0xffffffff;
        }

        return (string) $hash;
    }

    private function queryString(array $query): string
    {
        uksort($query, static fn (string $left, string $right) => strcasecmp($left, $right));

        $pairs = [];

        foreach ($query as $key => $value) {
            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            $pairs[] = rawurlencode((string) $key).'='.rawurlencode((string) $value);
        }

        return implode('&', $pairs);
    }

    private function metaItemProp(string $html, string $property): ?string
    {
        if (preg_match('/<meta\s+itemProp="'.preg_quote($property, '/').'"\s+content="([^"]*)"/i', $html, $matches)) {
            return html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5);
        }

        return null;
    }

    private function canonicalUrl(string $html): ?string
    {
        if (preg_match('/<link\s+rel="canonical"\s+href="([^"]*)"/i', $html, $matches)) {
            return html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5);
        }

        return null;
    }

    private function ogTitle(string $html): ?string
    {
        if (preg_match('/<meta\s+property="og:title"\s+content="([^"]*)"/i', $html, $matches)) {
            return html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5);
        }

        return null;
    }

    private function floatOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? round((float) $value, 2) : null;
    }

    private function dateOrNull(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
