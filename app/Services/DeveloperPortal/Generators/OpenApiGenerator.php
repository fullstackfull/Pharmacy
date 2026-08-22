<?php

namespace App\Services\DeveloperPortal\Generators;

use App\Services\DeveloperPortal\ApiManifest;
use App\Services\DeveloperPortal\Support\AuthResolver;
use App\Services\DeveloperPortal\Support\RuleTranslator;

/**
 * An OpenAPI 3.1 document, generated from the manifest rather than maintained beside it.
 *
 * The point is not the file. The point is that this file cannot describe an endpoint the
 * application does not serve, cannot omit one it does, and cannot claim a field is required when
 * the validator says it is optional — because every line of it is derived from the same route
 * table and the same validation rules that run in production.
 *
 * Where the code genuinely does not say something — a response body from a controller that returns
 * a model directly, of which this project has many — the spec says so with a description rather
 * than inventing a schema. A generated spec that guesses is worse than a gap, because a client
 * generated from it fails at runtime instead of at review.
 */
class OpenApiGenerator
{
    public function __construct(
        private readonly ApiManifest $manifest,
        private readonly RuleTranslator $translator,
        private readonly AuthResolver $auth,
    ) {
    }

    /**
     * @param  array<string, mixed>  $filters  narrow the spec, e.g. to one audience or version
     * @return array<string, mixed>
     */
    public function generate(array $filters = []): array
    {
        $manifest = $this->manifest->get();
        $endpoints = array_filter(
            $this->manifest->endpoints($filters),
            static fn (array $endpoint) => $endpoint['surface'] === 'api',
        );

        $paths = [];

        foreach ($endpoints as $endpoint) {
            $path = $this->openApiPath($endpoint['path']);

            foreach ($endpoint['methods'] as $method) {
                $paths[$path][strtolower($method)] = $this->operation($endpoint, $method);
            }
        }

        ksort($paths);

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => (string) config('app.name') . ' API',
                'version' => (string) ($manifest['app_version'] ?? '1.0.0'),
                'description' => $this->description($manifest),
            ],
            'servers' => [[
                'url' => $manifest['base_url'] ?: url('/'),
                'description' => 'This installation',
            ]],
            'tags' => $this->tags($endpoints),
            'paths' => $paths,
            'components' => [
                'securitySchemes' => $this->securitySchemes(),
                'schemas' => $this->sharedSchemas(),
            ],
        ];
    }

    public function toJson(array $filters = []): string
    {
        return (string) json_encode($this->generate($filters), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** A minimal YAML writer: no dependency added for one output format. */
    public function toYaml(array $filters = []): string
    {
        return "# Generated from the live route table. Do not edit by hand.\n"
            . $this->yaml($this->generate($filters));
    }

    /**
     * Where the spec falls short, and why — the input to the Documentation Quality screen.
     *
     * @return array<int, array<string, mixed>>
     */
    public function warnings(): array
    {
        $warnings = [];

        foreach ($this->manifest->endpoints() as $endpoint) {
            if ($endpoint['surface'] !== 'api') {
                continue;
            }

            $missing = [];

            if (!$endpoint['documented']) {
                $missing[] = 'no summary or description (add an #[ApiDoc] attribute)';
            }

            if ($endpoint['body'] === [] && array_intersect(['POST', 'PUT', 'PATCH'], $endpoint['methods']) !== []) {
                $missing[] = $endpoint['body_source'] === 'none'
                    ? 'no request schema could be recovered'
                    : 'request schema unreadable (' . $endpoint['body_source'] . ')';
            }

            if ($endpoint['response_example'] === null) {
                $missing[] = 'no response schema or example';
            }

            if ($endpoint['deprecated'] && $endpoint['replaced_by'] === null) {
                $missing[] = 'deprecated with no replacement named';
            }

            if ($endpoint['version'] === null) {
                $missing[] = 'no API version in the path';
            }

            if ($missing !== []) {
                $warnings[] = [
                    'id' => $endpoint['id'],
                    'endpoint' => implode('|', $endpoint['methods']) . ' ' . $endpoint['path'],
                    'audience' => $endpoint['audience'],
                    'missing' => $missing,
                ];
            }
        }

        return $warnings;
    }

    // -------------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $endpoint
     * @return array<string, mixed>
     */
    private function operation(array $endpoint, string $method): array
    {
        $operation = [
            'operationId' => $endpoint['name'] ?: strtolower($method) . '_' . $endpoint['id'],
            'summary' => $endpoint['summary'],
            'tags' => [$endpoint['audience'] . ':' . $endpoint['group']],
            'parameters' => $this->parameters($endpoint, $method),
            'responses' => $this->responses($endpoint),
        ];

        if ($endpoint['description'] !== null) {
            $operation['description'] = $endpoint['description'];
        }

        if ($endpoint['deprecated']) {
            $operation['deprecated'] = true;
            $operation['description'] = trim(($operation['description'] ?? '') . "\n\n"
                . 'Deprecated' . ($endpoint['deprecated_since'] ? ' since ' . $endpoint['deprecated_since'] : '') . '.'
                . ($endpoint['replaced_by'] ? ' Use ' . $endpoint['replaced_by'] . ' instead.' : '')
                . ($endpoint['sunset_at'] ? ' Scheduled for removal on ' . $endpoint['sunset_at'] . '.' : ''));
        }

        $security = $this->security($endpoint);

        if ($security !== null) {
            $operation['security'] = $security;
        }

        if (in_array($method, ['POST', 'PUT', 'PATCH'], true) && $endpoint['body'] !== []) {
            $operation['requestBody'] = $this->requestBody($endpoint);
        }

        return $operation;
    }

    /**
     * @param  array<string, mixed>  $endpoint
     * @return array<int, array<string, mixed>>
     */
    private function parameters(array $endpoint, string $method): array
    {
        $parameters = [];

        foreach ($endpoint['path_parameters'] as $parameter) {
            $parameters[] = [
                'name' => $parameter['name'],
                'in' => 'path',
                'required' => $parameter['required'] ?? true,
                'schema' => array_filter([
                    'type' => $parameter['type'] ?? 'string',
                    'pattern' => $parameter['pattern'] ?? null,
                ], static fn ($value) => $value !== null),
            ];
        }

        // On a GET the validated fields are query parameters, not a body. Putting them in a body
        // would generate clients that send a payload the endpoint never reads.
        if ($method === 'GET') {
            foreach ($endpoint['body'] as $field) {
                $parameters[] = [
                    'name' => $field['name'],
                    'in' => 'query',
                    'required' => (bool) ($field['required'] ?? false),
                    'schema' => $this->translator->schema($field),
                ];
            }
        }

        // The header every mobile client already sends, documented rather than folklore.
        if ($endpoint['audience'] !== 'web') {
            $parameters[] = [
                'name' => 'X-App-Version',
                'in' => 'header',
                'required' => false,
                'schema' => ['type' => 'string'],
                'description' => 'The calling app build. Recorded against usage so an endpoint is never removed while an old release still calls it.',
            ];
        }

        return $parameters;
    }

    /**
     * @param  array<string, mixed>  $endpoint
     * @return array<string, mixed>
     */
    private function requestBody(array $endpoint): array
    {
        $properties = [];
        $required = [];
        $example = [];
        $hasFile = false;

        foreach ($endpoint['body'] as $field) {
            $properties[$field['name']] = $this->translator->schema($field);
            $example[$field['name']] = $this->translator->example($field);

            if (!empty($field['required'])) {
                $required[] = $field['name'];
            }

            if (($field['type'] ?? null) === 'file') {
                $hasFile = true;
            }
        }

        $schema = array_filter([
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
        ], static fn ($value) => $value !== []);

        // A body with a file in it is multipart, not JSON. Generating application/json for an
        // upload endpoint produces a client that cannot upload.
        $contentType = $hasFile ? 'multipart/form-data' : 'application/json';

        return [
            'required' => $required !== [],
            'content' => [$contentType => array_filter([
                'schema' => $schema,
                'example' => $hasFile ? null : $example,
            ], static fn ($value) => $value !== null)],
        ];
    }

    /**
     * The responses this endpoint can actually produce.
     *
     * Only the ones its own middleware and validation make possible: a public endpoint cannot
     * return 401, and an endpoint with no validation rules cannot return a validation error. A
     * spec listing every status code for every operation teaches a client to handle failures that
     * will never arrive and hides the ones that will.
     *
     * @param  array<string, mixed>  $endpoint
     * @return array<string, mixed>
     */
    private function responses(array $endpoint): array
    {
        $success = in_array('POST', $endpoint['methods'], true) ? '200' : '200';

        $responses = [
            $success => array_filter([
                'description' => 'Success',
                'content' => $endpoint['response_example'] !== null
                    ? ['application/json' => ['example' => $endpoint['response_example']]]
                    : null,
            ], static fn ($value) => $value !== null),
        ];

        if ($endpoint['response_example'] === null) {
            $responses[$success]['description'] = 'Success. The response body is produced by the controller directly and is not declared as a schema; call the endpoint in the API console to see its exact shape.';
        }

        if ($endpoint['body'] !== []) {
            $responses['403'] = ['description' => 'Validation failed.', 'content' => ['application/json' => [
                'schema' => ['$ref' => '#/components/schemas/ErrorEnvelope'],
            ]]];
        }

        if ($endpoint['auth']['required'] ?? false) {
            $responses['401'] = ['description' => 'Missing, expired or invalid credentials.', 'content' => ['application/json' => [
                'schema' => ['$ref' => '#/components/schemas/ErrorEnvelope'],
            ]]];
        }

        if ($endpoint['permissions'] !== []) {
            $responses['403'] = ['description' => 'Authenticated, but lacking ' . implode(', ', $endpoint['permissions']) . '.'];
        }

        if ($endpoint['path_parameters'] !== []) {
            $responses['404'] = ['description' => 'The referenced record does not exist.'];
        }

        if (($endpoint['rate_limit']['requests'] ?? null) !== null) {
            $responses['429'] = [
                'description' => 'Rate limit exceeded: ' . $endpoint['rate_limit']['requests']
                    . ' requests per ' . $endpoint['rate_limit']['minutes'] . ' minute(s).',
            ];
        }

        ksort($responses);

        return $responses;
    }

    /**
     * @param  array<string, mixed>  $endpoint
     * @return array<int, array<string, array<int, string>>>|null
     */
    private function security(array $endpoint): ?array
    {
        if (!($endpoint['auth']['required'] ?? false)) {
            return ($endpoint['auth']['optional_auth'] ?? false) ? [[], ['customerToken' => []]] : null;
        }

        $scheme = match ($endpoint['auth']['mechanism'] ?? '') {
            'passport', 'sanctum' => 'customerToken',
            'seller_token' => 'vendorToken',
            'delivery_token' => 'deliveryToken',
            'webhook_secret' => 'webhookSecret',
            default => null,
        };

        return $scheme === null ? null : [[$scheme => $endpoint['permissions']]];
    }

    /** @return array<string, mixed> */
    private function securitySchemes(): array
    {
        return [
            'customerToken' => [
                'type' => 'http',
                'scheme' => 'bearer',
                'description' => 'A Laravel Passport access token from the customer login endpoints.',
            ],
            'vendorToken' => [
                'type' => 'http',
                'scheme' => 'bearer',
                'description' => 'The vendor auth_token from the seller login endpoint. Not a Passport token and not interchangeable with the customer one.',
            ],
            'deliveryToken' => [
                'type' => 'http',
                'scheme' => 'bearer',
                'description' => 'The delivery man token from the delivery app login endpoint.',
            ],
            'webhookSecret' => [
                'type' => 'apiKey',
                'in' => 'header',
                'name' => 'Authorization',
                'description' => 'A shared secret agreed with the integration partner.',
            ],
        ];
    }

    /**
     * The shapes every endpoint shares, declared once.
     *
     * @return array<string, mixed>
     */
    private function sharedSchemas(): array
    {
        return [
            'ErrorEnvelope' => [
                'type' => 'object',
                'description' => 'The error shape this API returns. Note that validation failures are returned with HTTP 403 rather than 422 — long-standing behaviour that existing apps depend on.',
                'properties' => [
                    'errors' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'code' => ['type' => 'string', 'description' => 'The field or condition that failed.'],
                                'message' => ['type' => 'string', 'description' => 'A translated, human-readable explanation.'],
                            ],
                        ],
                    ],
                ],
            ],
            'PaginatedList' => [
                'type' => 'object',
                'description' => 'The list envelope this API uses. Offset-based, not page-based: pass limit and offset, and read total_size to know when to stop.',
                'properties' => [
                    'total_size' => ['type' => 'integer'],
                    'limit' => ['type' => 'integer'],
                    'offset' => ['type' => 'integer'],
                ],
            ],
        ];
    }

    /** @param array<int, array<string, mixed>> $endpoints */
    private function tags(array $endpoints): array
    {
        $tags = [];

        foreach ($endpoints as $endpoint) {
            $name = $endpoint['audience'] . ':' . $endpoint['group'];
            $tags[$name] ??= ['name' => $name, 'description' => ucfirst(str_replace('_', ' ', $endpoint['group'])) . ' endpoints for the ' . str_replace('_', ' ', $endpoint['audience']) . '.'];
        }

        ksort($tags);

        return array_values($tags);
    }

    private function description(array $manifest): string
    {
        return implode("\n\n", [
            'Generated from the live route table on ' . ($manifest['generated_at'] ?? 'unknown') . '. Every path, parameter, rule and rate limit below is read from the code that serves it, so this document cannot describe an endpoint the application does not have.',
            'Errors are returned as {"errors":[{"code":"...","message":"..."}]}. Lists are offset-based: {total_size, limit, offset, items}.',
            'Where a response has no schema, the controller returns a model directly and the shape is not declared anywhere in the code. Those are listed in the portal under Documentation Quality.',
        ]);
    }

    private function openApiPath(string $path): string
    {
        // Laravel writes optional parameters as {id?}; OpenAPI has no such notation.
        return str_replace('?}', '}', $path);
    }

    private function yaml(mixed $value, int $indent = 0): string
    {
        $pad = str_repeat('  ', $indent);

        if (is_array($value)) {
            if ($value === []) {
                return "{}\n";
            }

            $isList = array_is_list($value);
            $output = '';

            foreach ($value as $key => $item) {
                if ($isList) {
                    $rendered = $this->yaml($item, $indent + 1);
                    $output .= $pad . '- ' . (is_array($item) ? "\n" . $rendered : $rendered);

                    continue;
                }

                $output .= $pad . $this->yamlKey((string) $key) . ':';
                $output .= is_array($item) && $item !== []
                    ? "\n" . $this->yaml($item, $indent + 1)
                    : ' ' . $this->yaml($item, $indent + 1);
            }

            return $output;
        }

        return $this->yamlScalar($value) . "\n";
    }

    private function yamlKey(string $key): string
    {
        return preg_match('/^[A-Za-z0-9_.\/-]+$/', $key) === 1 ? $key : "'" . str_replace("'", "''", $key) . "'";
    }

    private function yamlScalar(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return 'null';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        $string = (string) $value;

        return str_contains($string, "\n") || preg_match('/^[A-Za-z0-9_.\/ -]+$/', $string) !== 1
            ? "'" . str_replace("'", "''", $string) . "'"
            : $string;
    }
}
