<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\Yandex\YandexMapsParser;
use App\Services\Yandex\YandexMapsParserException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrganizationController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $url = $request->query('url');

        if ($url) {
            $yandexId = $this->extractYandexIdFromUrl($url);
            if ($yandexId) {
                $organization = $request->user()
                    ->organizations()
                    ->where('yandex_id', $yandexId)
                    ->latest('updated_at')
                    ->first();

                if ($organization) {
                    return response()->json([
                        'organization' => $this->organizationPayload($organization),
                    ]);
                }
            }
        }

        $organization = $request->user()
            ->organizations()
            ->latest('updated_at')
            ->first();

        return response()->json([
            'organization' => $organization ? $this->organizationPayload($organization) : null,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'url' => [
                'required',
                'url',
                'max:2048',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $host = strtolower((string) parse_url((string) $value, PHP_URL_HOST));

                    if (! preg_match('/(^|\.)yandex\.[a-z.]+$/', $host) && ! preg_match('/(^|\.)ya\.ru$/', $host)) {
                        $fail('Укажите ссылку на организацию в Яндекс.Картах.');
                    }
                },
            ],
        ]);

        $url = $data['url'];
        $userId = $request->user()->id;

        $yandexId = $this->extractYandexIdFromUrl($url);
        $existingOrganization = null;
        $useCache = false;

        if ($yandexId) {
            $existingOrganization = Organization::where('user_id', $userId)
                ->where('yandex_id', $yandexId)
                ->first();

            if ($existingOrganization) {
                $cacheTtlHours = config('services.yandex_maps.cache_ttl_hours', 24);
                $cacheExpiry = now()->subHours($cacheTtlHours);

                if ($existingOrganization->last_scraped_at && $existingOrganization->last_scraped_at->greaterThan($cacheExpiry)) {
                    $useCache = true;
                }
            }
        }

        \App\Jobs\ParseYandexOrganizationJob::dispatch($url, $userId);

        if ($useCache && $existingOrganization) {
            return response()->json([
                'message' => 'Используем кешированные данные, проверяем новые отзывы',
                'organization' => $this->organizationPayload($existingOrganization),
                'background_parsing' => true,
            ], 200);
        }

        return response()->json([
            'message' => 'Парсинг запущен',
            'organization' => $existingOrganization ? $this->organizationPayload($existingOrganization) : null,
            'background_parsing' => true,
        ], 202);
    }

    public function reviews(Request $request, Organization $organization): JsonResponse
    {
        abort_unless($organization->user_id === $request->user()->id, 403);

        $page = max(1, (int) $request->integer('page', 1));
        $perPage = min(50, max(1, (int) $request->integer('per_page', 50)));
        $paginator = $organization->reviews()
            ->orderByDesc('reviewed_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data' => $paginator->getCollection()->map(fn ($review) => [
                'id' => $review->id,
                'author' => $review->author,
                'date' => optional($review->reviewed_at)->toDateString(),
                'text' => $review->text,
                'rating' => $review->rating,
            ])->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function jobStatus(Request $request): JsonResponse
    {
        $job = \App\Models\ParsingJob::where('user_id', $request->user()->id)
            ->latest()
            ->first();

        if (!$job) {
            return response()->json(['message' => 'Job not found'], 404);
        }

        $response = [
            'status' => $job->status,
            'scraped_reviews_count' => $job->scraped_reviews_count,
            'has_new_reviews' => $job->has_new_reviews ?? false,
            'previous_reviews_count' => $job->previous_reviews_count ?? 0,
            'new_reviews_count' => $job->new_reviews_count ?? 0,
            'started_at' => $job->started_at?->toIso8601String(),
            'completed_at' => $job->completed_at?->toIso8601String(),
            'error' => $job->error,
            'message' => $this->getJobMessage($job),
        ];

        if ($job->status === 'completed' && $job->organization_id) {
            $organization = \App\Models\Organization::find($job->organization_id);
            $response['organization'] = $this->organizationPayload($organization);
        }

        return response()->json($response);
    }

    private function organizationPayload(Organization $organization): array
    {
        return [
            'id' => $organization->id,
            'yandex_id' => $organization->yandex_id,
            'source_url' => $organization->source_url,
            'canonical_url' => $organization->canonical_url,
            'title' => $organization->title,
            'address' => $organization->address,
            'rating_value' => $organization->rating_value !== null ? (float)$organization->rating_value : null,
            'rating_count' => $organization->rating_count,
            'review_count' => $organization->review_count,
            'scraped_reviews_count' => $organization->scraped_reviews_count,
            'scrape_status' => $organization->scrape_status,
            'scrape_error' => $organization->scrape_error,
            'last_scraped_at' => optional($organization->last_scraped_at)->toIso8601String(),
        ];
    }

    private function getJobMessage(\App\Models\ParsingJob $job): string
    {
        switch ($job->status) {
            case 'pending':
                return 'Ожидание начала парсинга...';
            case 'processing':
                return 'Идёт парсинг отзывов...';
            case 'completed':
                if ($job->has_new_reviews && $job->new_reviews_count > 0) {
                    return "Найдено {$job->new_reviews_count} новых отзывов!";
                } elseif ($job->has_new_reviews === false) {
                    return 'Парсинг завершён. Новых отзывов нет.';
                } else {
                    return 'Парсинг завершён';
                }
            case 'failed':
                return $job->error ? "Ошибка: {$job->error}" : 'Ошибка парсинга';
            default:
                return 'Неизвестный статус';
        }
    }

    private function extractYandexIdFromUrl(string $url): ?string
    {

        $path = parse_url($url, PHP_URL_PATH);
        if (!$path) {
            return null;
        }

        $parts = explode('/', trim($path, '/'));

        foreach ($parts as $part) {
            if (is_numeric($part) && strlen($part) >= 5) {
                return $part;
            }
        }

        foreach ($parts as $i => $part) {
            if (is_numeric($part) && isset($parts[$i - 1]) && $parts[$i - 1] === 'org') {
                return $part;
            }
        }

        return null;
    }
}
