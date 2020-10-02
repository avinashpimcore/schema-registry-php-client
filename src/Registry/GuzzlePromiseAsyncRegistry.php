<?php

declare(strict_types=1);

namespace FlixTech\SchemaRegistryApi\Registry;

use AvroSchema;
use Closure;
use FlixTech\SchemaRegistryApi\AsynchronousRegistry;
use FlixTech\SchemaRegistryApi\Exception\ExceptionMap;
use FlixTech\SchemaRegistryApi\Exception\RuntimeException;
use FlixTech\SchemaRegistryApi\Exception\SchemaRegistryException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use const FlixTech\SchemaRegistryApi\Constants\VERSION_LATEST;
use function FlixTech\SchemaRegistryApi\Requests\checkIfSubjectHasSchemaRegisteredRequest;
use function FlixTech\SchemaRegistryApi\Requests\decodeResponse;
use function FlixTech\SchemaRegistryApi\Requests\registerNewSchemaVersionWithSubjectRequest;
use function FlixTech\SchemaRegistryApi\Requests\schemaRequest;
use function FlixTech\SchemaRegistryApi\Requests\singleSubjectVersionRequest;
use function FlixTech\SchemaRegistryApi\Requests\validateSchemaId;
use function FlixTech\SchemaRegistryApi\Requests\validateVersionId;

class GuzzlePromiseAsyncRegistry implements AsynchronousRegistry
{
    /**
     * @var ClientInterface
     */
    private $client;

    /**
     * @var Closure
     */
    private $rejectedCallback;

    public function __construct(ClientInterface $client)
    {
        $this->client = $client;
        $exceptionMap = ExceptionMap::instance();

        $responseExistenceGuard = static function (RequestException $exception): ResponseInterface {
            $response = $exception->getResponse();

            if (!$response) {
                throw new RuntimeException(
                    "RequestException does not provide a response to inspect.",
                    $exception->getCode(),
                    $exception
                );
            }

            return $response;
        };

        $this->rejectedCallback = static function (RequestException $exception) use (
            $exceptionMap,
            $responseExistenceGuard
        ): SchemaRegistryException {
            return $exceptionMap->exceptionFor($responseExistenceGuard($exception));
        };
    }

    /**
     * {@inheritdoc}
     *
     * @throws RuntimeException
     */
    public function register(string $subject, AvroSchema $schema): PromiseInterface
    {
        $request = registerNewSchemaVersionWithSubjectRequest((string) $schema, $subject);

        $onFulfilled = function (ResponseInterface $response) {
            return decodeResponse($response)['id'];
        };

        return $this->makeRequest($request, $onFulfilled);
    }

    /**
     * {@inheritdoc}
     *
     * @throws RuntimeException
     */
    public function schemaId(string $subject, AvroSchema $schema): PromiseInterface
    {
        $request = checkIfSubjectHasSchemaRegisteredRequest($subject, (string) $schema);

        $onFulfilled = function (ResponseInterface $response) {
            return decodeResponse($response)['id'];
        };

        return $this->makeRequest($request, $onFulfilled);
    }

    /**
     * {@inheritdoc}
     *
     * @throws RuntimeException
     */
    public function schemaForId(int $schemaId): PromiseInterface
    {
        $request = schemaRequest(validateSchemaId($schemaId));

        $onFulfilled = function (ResponseInterface $response) {
            return AvroSchema::parse(
                decodeResponse($response)['schema']
            );
        };

        return $this->makeRequest($request, $onFulfilled);
    }

    /**
     * {@inheritdoc}
     *
     * @throws RuntimeException
     */
    public function schemaForSubjectAndVersion(string $subject, int $version): PromiseInterface
    {
        $request = singleSubjectVersionRequest($subject, validateVersionId($version));

        $onFulfilled = function (ResponseInterface $response) {
            return AvroSchema::parse(
                decodeResponse($response)['schema']
            );
        };

        return $this->makeRequest($request, $onFulfilled);
    }

    /**
     * {@inheritdoc}
     *
     * @throws RuntimeException
     */
    public function schemaVersion(string $subject, AvroSchema $schema): PromiseInterface
    {
        $request = checkIfSubjectHasSchemaRegisteredRequest($subject, (string) $schema);

        $onFulfilled = function (ResponseInterface $response) {
            return decodeResponse($response)['version'];
        };

        return $this->makeRequest($request, $onFulfilled);
    }

    /**
     * {@inheritdoc}
     *
     * @throws RuntimeException
     */
    public function latestVersion(string $subject): PromiseInterface
    {
        $request = singleSubjectVersionRequest($subject, VERSION_LATEST);

        $onFulfilled = function (ResponseInterface $response) {
            return AvroSchema::parse(
                decodeResponse($response)['schema']
            );
        };

        return $this->makeRequest($request, $onFulfilled);
    }

    /**
     * @param RequestInterface $request
     * @param callable         $onFulfilled
     *
     * @return PromiseInterface
     */
    private function makeRequest(RequestInterface $request, callable $onFulfilled): PromiseInterface
    {
        return $this->client
            ->sendAsync($request)
            ->then($onFulfilled, $this->rejectedCallback);
    }
}