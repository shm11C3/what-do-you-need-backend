<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use App\Models\Reaction;
use App\Models\Post;
use Illuminate\Support\Str;

class ReactionTest extends TestCase
{
    use RefreshDatabase;

    private $valid_reaction = [
        'reactable_ulid' => null,
        'reaction_type' => 'good',
    ];

    private $invalid_type_reaction = [
        'reactable_ulid' => null,
        'reaction_type' => '****',
    ];

    private $invalid_ulid_reaction = [
        'reactable_ulid' => null,
        'reaction_type' => 'good',
    ];

    /**
     * ユーザーの作成したリアクションULID
     *
     * @var ?string
     */
    private $reaction_valid_ulid;

    /**
     * 存在しないリアクションULID
     *
     * @var ?string
     */
    private $reaction_invalid_ulid;

    public function setup(): void
    {
        parent::setUp();
        $this->seed('DatabaseSeeder');
        $this->storeIdToken();
        $this->createTestUser();

        $this->valid_reaction['reactable_ulid']
            = $invalid_type_reaction['reactable_ulid']
            = (string) Post::limit(1)->get('ulid')[0]->ulid;

        $this->invalid_ulid_reaction['reactable_ulid']
            = $this->reaction_invalid_ulid
            = (string) Str::ulid();

        // ダミーのリアクションを作成
        $this->reaction_valid_ulid = (string) Str::ulid();;
        Reaction::create([
            'ulid' => $this->reaction_valid_ulid,
            'auth_id' => $this->testing_auth_id,
            'reactable_ulid' => $this->valid_reaction['reactable_ulid'],
            'reaction_type' => 'hot',
            'reactable_type' => 'App\\Models\\Post',
        ]);
    }

    /**
     * @test
     *
     * @return void
     */
    public function test_getReactionTypes(): void
    {
        $response = $this->getJson('reaction-types');
        $response->assertExactJson(Reaction::TYPES);
    }

    /**
     * @test
     *
     * @return void
     */
    public function test_addReaction(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->id_token
        ])->postJson('post/reaction', $this->valid_reaction);
        $response->assertOk()->assertJsonFragment(["status" => true])->assertJsonFragment(['reaction_type' => $this->valid_reaction['reaction_type']]);

        // DBに登録されているか確認
        $this->assertDatabaseHas('reactions', [
            'auth_id' => $this->testing_auth_id,
            'reactable_ulid' => $this->valid_reaction['reactable_ulid'],
            'reaction_type' => $this->valid_reaction['reaction_type'],
        ]);
    }

    /**
     * @test
     *
     * @return void
     */
    public function test_addReaction_exception(): void
    {
        // 認証トークンなし
        $response = $this->postJson('post/reaction', $this->valid_reaction);
        $response->assertUnauthorized();

        // 重複したリアクション
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->id_token
        ])->postJson('post/reaction', $this->valid_reaction);
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->id_token
        ])->postJson('post/reaction', $this->valid_reaction);
        $response->assertStatus(400);

        // バリデーション
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->id_token
        ])->postJson('post/reaction', $this->invalid_type_reaction);
        $response->assertStatus(422);

        // 存在しないULID
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->id_token
        ])->postJson('post/reaction', $this->invalid_ulid_reaction);
        $response->assertStatus(400);
    }

    /**
     * @test
     *
     * @return void
     */
    public function test_removeReaction(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->id_token
        ])->deleteJson('reaction', ['ulid' => $this->reaction_valid_ulid]);

        $response->assertOk()
        ->assertJsonFragment(["status" => true, "ulid" => $this->reaction_valid_ulid]);
    }

    /**
     * @test
     *
     * @return void
     */
    public function test_removeReaction_exception(): void
    {
        $missing_reaction = ['ulid' => $this->reaction_invalid_ulid];
        $valid_reaction = ['ulid' => $this->reaction_valid_ulid];
        $others_reaction = ['ulid' => Reaction::whereNot('auth_id', $this->testing_auth_id)->first()->ulid];

        // 認証トークンなし
        $response = $this->deleteJson('reaction', $valid_reaction);
        $response->assertUnauthorized();

        // 削除権限なし（他人のリアクション）
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->id_token
        ])->deleteJson('reaction', $others_reaction);
        $response->assertStatus(403);

        // 存在しないリアクション
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->id_token
        ])->deleteJson('reaction', $missing_reaction);
        $response->assertStatus(403)
        ->assertJsonFragment(["status" => false, "ulid" => $this->reaction_invalid_ulid]);

        // バリデーション
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->id_token
        ])->deleteJson('reaction', ['ulid' => (string)Str::uuid()]);
        $response->assertStatus(422);
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->id_token
        ])->deleteJson('reaction', ['ulid' => null]);
            $response->assertStatus(422);
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->id_token
        ])->deleteJson('reaction', ['ulid' => (object)Str::uuid()]);
        $response->assertStatus(422);
    }
}
