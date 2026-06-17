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
        $organization = $request->user()
            ->organizations()
            ->latest('updated_at')
            ->first();

        return response()->json([
            'organization' => $organization ? $this->organizationPayload($organization) : null,
        ]);
    }

    public function store(Request $request, YandexMapsParser $parser): JsonResponse
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

        try {
            $parsed = $parser->scrape($data['url']);
        } catch (YandexMapsParserException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        $organization = DB::transaction(function () use ($request, $parsed): Organization {
            $meta = $parsed['organization'];

            $organization = Organization::updateOrCreate(
                [
                    'user_id' => $request->user()->id,
                    'yandex_id' => $meta['yandex_id'],
                ],
                [
                    'source_url' => $meta['source_url'],
                    'canonical_url' => $meta['canonical_url'],
                    'title' => $meta['title'],
                    'address' => $meta['address'],
                    'rating_value' => $meta['rating_value'],
                    'rating_count' => $meta['rating_count'],
                    'review_count' => $meta['review_count'],
                    'scraped_reviews_count' => $meta['scraped_reviews_count'],
                    'scrape_status' => 'success',
                    'scrape_error' => null,
                    'last_scraped_at' => now(),
                    'raw_meta' => $parsed['raw'],
                ],
            );

            $organization->reviews()->delete();

            foreach (array_chunk($parsed['reviews'], 100) as $chunk) {
                $organization->reviews()->createMany($chunk);
            }

            return $organization->fresh();
        });

        return response()->json([
            'organization' => $this->organizationPayload($organization),
        ]);
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

    private function organizationPayload(Organization $organization): array
    {
        return [
            'id' => $organization->id,
            'yandex_id' => $organization->yandex_id,
            'source_url' => $organization->source_url,
            'canonical_url' => $organization->canonical_url,
            'title' => $organization->title,
            'address' => $organization->address,
            'rating_value' => $organization->rating_value !== null ? (float) $organization->rating_value : null,
            'rating_count' => $organization->rating_count,
            'review_count' => $organization->review_count,
            'scraped_reviews_count' => $organization->scraped_reviews_count,
            'scrape_status' => $organization->scrape_status,
            'scrape_error' => $organization->scrape_error,
            'last_scraped_at' => optional($organization->last_scraped_at)->toIso8601String(),
        ];
    }
}
