protected $middlewareAliases = [
    // ... other middleware
    'role' => \App\Http\Middleware\CheckRole::class,
];