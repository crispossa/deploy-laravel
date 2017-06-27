<?php

namespace CrisPossa\GitDeploy;

use Illuminate\Routing\Router;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;

class GitDeployServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot(Router $router)
    {
        $this->publishes([
            __dir__ . '/../config/gitdeploy.php' => config_path('gitdeploy.php')
        ], 'config');
        $this->loadRoutesFrom(__dir__ . '/http/routes.php');
        $this->loadViewsFrom(__dir__ . '/views', 'gitdeploy');

    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(__dir__ . '/../config/gitdeploy.php', 'gitdeploy');
        $this->app->bind('git_deploy', function ($app) {
            return new GitDeploy;
        });
    }
}
