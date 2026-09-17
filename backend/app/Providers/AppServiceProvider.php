<?php

namespace App\Providers;

use App\Domain\Post\Models\Post;
use App\Domain\Post\Models\PostSource;
use App\Domain\Post\Observers\PostObserver;
use App\Domain\Post\Observers\PostSourceObserver;
use App\Domain\Post\Policies\PostPolicy;
use App\Models\Profile;
use App\Models\User;
use App\Observers\ProfileObserver;
use App\Observers\UserObserver;
use Illuminate\Support\Facades\Gate;
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
        PostSource::observe(PostSourceObserver::class);
        Profile::observe(ProfileObserver::class);
        User::observe(UserObserver::class);

        Gate::policy(Post::class, PostPolicy::class);
    }
}
