<?php

namespace App\Providers;

use App\Models\Post;
use App\Models\Profile;
use App\Models\User;
use App\Observers\PostObserver;
use App\Observers\ProfileObserver;
use App\Observers\UserObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Post::observe(PostObserver::class);
        Profile::observe(ProfileObserver::class);
        User::observe(UserObserver::class);
    }
}
