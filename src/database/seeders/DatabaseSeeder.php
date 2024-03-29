<?php

namespace Database\Seeders;

use App\Models\Post;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {
        User::factory(100)
        ->has(Post::factory()->count(3), 'posts')
        ->create();

        $this->call([
            CategorySeeder::class,
            ReactionSeeder::class,
        ]);
    }
}
