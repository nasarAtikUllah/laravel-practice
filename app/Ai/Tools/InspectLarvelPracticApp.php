<?php

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Process;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class InspectLarvelPracticApp implements Tool
{
    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Inspect the Laravel application and return useful information about its environment, packages, routes, and framework configuration.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $action = $request['action'];

        return match ($action) {
            'about' => $this->about(),
            'routes' => $this->route(),
            'packages' => $this->package(),
            default => 'Unknown inspection action',
        };
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema
                ->string()
                ->enum(['about', 'routes', 'packages'])
                ->required(),
        ];
    }

    public function about()
    {
        $result = Process::run('php artisan about');

        return $result->output();
    }

    public function route()
    {
        $result = Process::run('php artisan route:list --except-vendor');

        return $result->output();
    }

    public function package()
    {
        $result = Process::run('composer show --direct');

        return $result->output();
    }
}
