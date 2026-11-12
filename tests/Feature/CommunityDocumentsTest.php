<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\DataPlatform\Contracts\DocumentStore;
use App\Modules\Rental\Models\Rental;
use App\Modules\Trust\Models\Review;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommunityDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private object $documents;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        // Only the external document boundary is replaced; application policies/services are real.
        $this->documents = new class implements DocumentStore
        {
            public array $data = [];

            public bool $offline = false;

            public function put(string $collection, string $id, array $document): void
            {
                if ($this->offline) {
                    throw new \RuntimeException('External service unavailable');
                }
                $this->data[$collection][$id] = $document + ['_id' => $id];
            }

            public function find(string $collection, array $filter, int $limit = 100): array
            {
                if ($this->offline) {
                    throw new \RuntimeException('External service unavailable');
                }

                return array_slice(array_values(array_filter($this->data[$collection] ?? [], function ($row) use ($filter) {
                    foreach ($filter as $key => $value) {
                        if (($row[$key] ?? null) !== $value) {
                            return false;
                        }
                    }

                    return true;
                })), 0, $limit);
            }

            public function delete(string $collection, string $id): void
            {
                if ($this->offline) {
                    throw new \RuntimeException('External service unavailable');
                }
                unset($this->data[$collection][$id]);
            }
        };
        $this->app->instance(DocumentStore::class, $this->documents);
    }

    private function profileData(): array
    {
        return ['context' => 'personal', 'minimum_budget' => 2000000, 'maximum_budget' => 3000000, 'quiet_from' => '22:00', 'quiet_until' => '06:00', 'smoking' => '0', 'pets' => '1', 'interests' => "Đọc sách\nNấu ăn", 'minimum_months' => 6];
    }

    private function historicalReview(): Review
    {
        // Declared historical initial state, before the currently active rental; no close workflow is mocked.
        $source = Rental::where('status', 'active')->firstOrFail();
        $rental = $source->replicate()->fill(['status' => 'closed', 'deposit' => 0, 'starts_at' => today()->subYears(2)->toDateString(), 'ends_at' => today()->subYears(2)->addMonth()->toDateString(), 'closed_at' => today()->subYears(2)->addMonth(), 'confirmed_at' => today()->subYears(2), 'signed_at' => today()->subYears(2), 'residency_started_at' => today()->subYears(2), 'handover_complete' => true]);
        $rental->save();

        return Review::create(['rental_id' => $rental->id, 'user_id' => $rental->tenant_id, 'rating' => 4, 'comment' => 'Nhận xét cơ bản được giữ nguyên']);
    }

    public function test_private_profile_nested_edit_removes_unselected_interests_and_deletes_only_own_document(): void
    {
        $user = User::where('email', 'user@trohub.test')->firstOrFail();
        $foreign = User::where('email', 'tenant@trohub.test')->firstOrFail();
        $this->documents->put('roommate_profiles', 'old-profile', ['user_id' => $user->id, 'preferences' => ['interests' => ['Bơi', 'Cầu lông'], 'existing_key' => 'preserved']]);
        $this->documents->put('roommate_profiles', 'foreign-profile', ['user_id' => $foreign->id, 'preferences' => ['interests' => ['Thông tin riêng']]]);
        $this->actingAs($user)->post('/actions/roommate.profile.save', $this->profileData())->assertSessionHasNoErrors();
        $saved = $this->documents->data['roommate_profiles']['old-profile'];
        $this->assertSame(['Đọc sách', 'Nấu ăn'], $saved['preferences']['interests']);
        $this->assertFalse($saved['preferences']['smoking']);
        $this->assertTrue($saved['preferences']['pets']);
        $this->assertSame('preserved', $saved['preferences']['existing_key']);
        $this->get('/ui/roommate-discovery?context=personal')->assertOk()->assertSee('Cập nhật hồ sơ sở thích')->assertSee('Nấu ăn')->assertDontSee('Thông tin riêng');
        $this->post('/actions/roommate.profile.save', $this->profileData() + ['user_id' => $foreign->id])->assertForbidden();
        $this->post('/actions/roommate.profile.delete', ['context' => 'personal', 'confirm' => 1])->assertSessionHasNoErrors();
        $this->assertArrayNotHasKey('old-profile', $this->documents->data['roommate_profiles']);
        $this->assertArrayHasKey('foreign-profile', $this->documents->data['roommate_profiles']);
    }

    public function test_profile_validation_does_not_write_invalid_ranges_and_accepts_optional_minimum(): void
    {
        $user = User::where('email', 'user@trohub.test')->firstOrFail();
        $this->actingAs($user)->post('/actions/roommate.profile.save', array_replace($this->profileData(), ['minimum_budget' => 4000000]))->assertSessionHasErrors('maximum_budget');
        $this->assertEmpty($this->documents->data);
        $this->post('/actions/roommate.profile.save', array_replace($this->profileData(), ['minimum_budget' => null]))->assertSessionHasNoErrors();
        $this->assertCount(1, $this->documents->data['roommate_profiles']);
    }

    public function test_extended_review_is_author_only_and_delete_retains_relational_rating_and_comment(): void
    {
        $review = $this->historicalReview();
        $author = User::findOrFail($review->user_id);
        $foreign = User::where('email', 'user@trohub.test')->firstOrFail();
        $data = ['context' => 'personal', 'id' => $review->id, 'handover_comment' => 'Bàn giao rõ ràng', 'shared_area_suggestions' => "Thêm đèn\nLịch vệ sinh rõ ràng", 'would_recommend' => '1'];
        $this->actingAs($foreign)->post('/actions/review.details.save', $data)->assertForbidden();
        $this->actingAs($author)->post('/actions/review.details.save', $data)->assertSessionHasNoErrors();
        $this->assertSame(['Thêm đèn', 'Lịch vệ sinh rõ ràng'], $this->documents->data['review_details']['review-details:'.$review->id]['aspects']['shared_areas']['suggestions']);
        $this->get('/ui/rental-detail?context=personal&id='.$review->rental_id)->assertOk()->assertSee('Bàn giao rõ ràng')->assertSee('Bổ sung đánh giá công khai');
        $this->post('/actions/review.details.delete', ['context' => 'personal', 'id' => $review->id, 'confirm' => 1])->assertSessionHasNoErrors();
        $this->assertSame(4, $review->fresh()->rating);
        $this->assertSame('Nhận xét cơ bản được giữ nguyên', $review->fresh()->comment);
        $this->assertEmpty($this->documents->data['review_details']);
    }

    public function test_document_outage_keeps_primary_roommate_and_review_routes_available(): void
    {
        $review = $this->historicalReview();
        $this->documents->offline = true;
        $this->actingAs(User::findOrFail($review->user_id))->get('/ui/rental-detail?context=personal&id='.$review->rental_id)->assertOk()->assertSee('Đánh giá bổ sung tạm chưa sẵn sàng');
        $this->get('/ui/roommate-discovery?context=personal')->assertOk()->assertSee('Đăng nhu cầu ở ghép')->assertDontSee('Cập nhật hồ sơ sở thích');
        $this->post('/actions/roommate.profile.save', $this->profileData())->assertStatus(503);
        $this->assertDatabaseHas('reviews', ['id' => $review->id, 'rating' => 4]);
        $this->assertEmpty($this->documents->data);
    }
}
