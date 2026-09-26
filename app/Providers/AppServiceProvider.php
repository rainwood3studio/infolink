<?php

namespace App\Providers;

use App\Models\Concerns\HasSource;
use Illuminate\Database\Schema\Blueprint;
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
        $this->registerSourceColumnsMacro();
    }

    /**
     * Adds the write-traceability columns used by {@see HasSource}.
     */
    protected function registerSourceColumnsMacro(): void
    {
        Blueprint::macro('sourceColumns', function (): void {
            /** @var Blueprint $this */
            $this->string('source', 16)->default('manual')->index();
            $this->string('external_key')->nullable();
            $this->string('actor', 64)->default('system');
            $this->string('vault_ref')->nullable();
            $this->text('notes')->nullable();

            $this->unique(['source', 'external_key']);
        });
    }
}
