<?php

namespace Database\Seeders;

use App\Http\Controllers\SyncLolController;
use App\Jobs\UpdateRiotKeysJob;
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
        // \App\Models\User::factory(10)->create();
        $this->call([
            TierSeeder::class,
            UserSeeder::class,
            LolApiAccountSeeder::class,
        ]);
        $slc = new SyncLolController();
        echo "Syncing...<br>";
        $slc->index();
        echo "Get api account<br>";
        UpdateRiotKeysJob::dispatchSync();
    }
}
