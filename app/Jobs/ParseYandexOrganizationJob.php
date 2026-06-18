<?php

namespace App\Jobs;

use App\Models\Organization;
use App\Models\OrganizationReview;
use App\Models\ParsingJob;
use App\Services\Yandex\YandexMapsParser;
use App\Services\Yandex\YandexMapsParserException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ParseYandexOrganizationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 300;
    public $backoff = [10, 30, 60];

    public function __construct(
        public string $url,
        public ?int $userId = null,
    ) {}

    public function handle(YandexMapsParser $parser): void
    {
        $job = ParsingJob::create([
            'url' => $this->url,
            'user_id' => $this->userId,
            'status' => 'processing',
            'started_at' => now(),
        ]);

        try {
            $result = $parser->scrape($this->url);

            $yandexId = $result['organization']['yandex_id'];

            $existingOrganization = Organization::where('user_id', $this->userId)
                ->where('yandex_id', $yandexId)
                ->first();

            $existingReviewsCount = $existingOrganization ?
                OrganizationReview::where('organization_id', $existingOrganization->id)->count() : 0;
            $newReviewsCount = count($result['reviews']);

            $organization = Organization::updateOrCreate(
                [
                    'user_id' => $this->userId,
                    'yandex_id' => $yandexId
                ],
                array_merge($result['organization'], [
                    'user_id' => $this->userId,
                    'last_scraped_at' => now(),
                    'scrape_status' => 'completed',
                    'scraped_reviews_count' => $newReviewsCount,
                ])
            );

            $savedReviewsCount = 0;
            foreach ($result['reviews'] as $reviewData) {
                try {
                    OrganizationReview::updateOrCreate(
                        [
                            'organization_id' => $organization->id,
                            'yandex_review_id' => $reviewData['yandex_review_id'],
                        ],
                        $reviewData
                    );
                    $savedReviewsCount++;
                } catch (\Exception $e) {
                    Log::warning('Failed to save review', [
                        'organization_id' => $organization->id,
                        'yandex_review_id' => $reviewData['yandex_review_id'] ?? 'unknown',
                        'error' => $e->getMessage()
                    ]);
                }
            }

            $hasNewReviews = $savedReviewsCount > $existingReviewsCount;

            $job->update([
                'status' => 'completed',
                'completed_at' => now(),
                'organization_id' => $organization->id,
                'scraped_reviews_count' => $savedReviewsCount,
                'has_new_reviews' => $hasNewReviews,
                'previous_reviews_count' => $existingReviewsCount,
                'new_reviews_count' => max(0, $savedReviewsCount - $existingReviewsCount),
            ]);

        } catch (YandexMapsParserException $e) {
            $job->update([
                'status' => 'failed',
                'completed_at' => now(),
                'error' => $e->getMessage(),
            ]);
            Log::error('Yandex parsing failed', ['url' => $this->url, 'error' => $e->getMessage()]);

            throw $e;
        } catch (\Exception $e) {
            $job->update([
                'status' => 'failed',
                'completed_at' => now(),
                'error' => 'Internal server error',
            ]);
            Log::error('Parsing job failed', ['url' => $this->url, 'error' => $e->getMessage()]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        ParsingJob::where('url', $this->url)
            ->where('status', 'processing')
            ->latest()
            ->first()
            ?->update([
                'status' => 'failed',
                'completed_at' => now(),
                'error' => $exception->getMessage(),
            ]);
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
