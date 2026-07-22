<?php declare(strict_types = 1);

namespace Wedo\Api\Controllers;

use Nette\Application\AbortException;
use Nette\Application\IPresenter;
use Nette\Application\Request;
use Nette\Application\Response;
use Nette\Application\Responses\JsonResponse;
use Nette\Http\IRequest;
use Nette\Http\IResponse;
use Nette\Localization\Translator;
use Nette\Utils\Json;
use Nette\Utils\Strings;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use Throwable;
use Wedo\Api\Attributes\HttpMethod;
use Wedo\Api\Exceptions\BadRequestException;
use Wedo\Api\Exceptions\NotFoundException;
use Wedo\Api\Exceptions\ResponseException;
use Wedo\Api\Exceptions\ValidationException;
use Wedo\Api\Requests\BaseRequest;
use Wedo\Api\Responses\BaseResponse;
use Wedo\Api\Responses\ErrorResponse;
use Wedo\Api\Responses\ValidationErrorResponse;
use Wedo\Utilities\Json\JsonDateTime;
use Wedo\Utilities\Json\JsonObject;
use Wedo\Utilities\Json\JsonTranslatableMessage;

/**
 * @codeCoverageIgnore To hard to test ATM and this class is not likely to change
 */
class Controller implements IPresenter
{

	public mixed $payload = [];

	public Request $request;

	protected LoggerInterface $logger;

	protected Translator $translator;

	private IRequest $httpRequest;

	private IResponse $httpResponse;

	final public function getHttpRequest(): IRequest
	{
		return $this->httpRequest;
	}

	final public function getHttpResponse(): IResponse
	{
		return $this->httpResponse;
	}

	final public function injectRequestAndResponse(
		IRequest $httpRequest,
		IResponse $httpResponse
	): void
	{
		$this->httpRequest = $httpRequest;
		$this->httpResponse = $httpResponse;
	}

	/**
	 * @return JsonResponse
	 */
	public function run(Request $request): Response
	{
		$this->request = $request;

		if ($this->getHttpRequest()->getHeader('origin') !== null) {
			$this->getHttpResponse()->addHeader('Access-Control-Allow-Origin', $this->getHttpRequest()->getHeader('origin'));
		}

		if (Strings::upper($this->request->getMethod() ?? '') === 'OPTIONS') {
			$this->setOptionsRequestHeaders();

			return new JsonResponse([]);
		}

		try {
			$result = $this->process();
		} catch (AbortException $ex) {
			//all ok, abort exception shouldn't be thrown to user
		}

		$result ??= $this->payload;

		$this->setTranslatorOnJsonTranslatable($result); //@phpstan-ignore-line

		return new JsonResponse($result ?? $this->payload);
	}

	public function injectTranslator(Translator $translator): void
	{
		$translator = clone $translator;
		$this->translator = $translator;
	}

	public function sendJson(mixed $item): void
	{
		$this->payload = $item;
		$this->terminate();
	}

	/**
	 * Correctly terminates controller.
	 *
	 * @throws AbortException
	 */
	public function terminate(): void
	{
		throw new AbortException();
	}

	/**
	 * @param mixed[] $params
	 * @throws NotFoundException
	 */
	protected function tryCall(string $method, array $params): bool
	{
		if (!method_exists($this, $method)) {
			throw new NotFoundException();
		}

		$rm = new ReflectionMethod($this, $method);
		$this->validateRequest($rm);
		$methodParams = $rm->getParameters();

		$params = count($methodParams) > 0 ? $this->createParams($params, $methodParams) : [];

		$this->payload = $rm->invokeArgs($this, $params);

		return true;
	}

	/**
	 * @throws BadRequestException
	 */
	protected function validateRequest(ReflectionMethod $rm): void
	{
		$expectedMethod = $this->getExpectedHttpMethod($rm);

		$request = $this->getHttpRequest();

		if (strcasecmp($this->getHttpRequest()->getMethod(), $expectedMethod) !== 0) {
			throw new BadRequestException(
				'Action requires ' . $expectedMethod . ' http method but received ' . $request->getMethod()
			);
		}
	}

	/**
	 * @param mixed[] $params
	 * @param ReflectionParameter[] $methodParams
	 * @return mixed[]
	 */
	protected function createParams(array $params, array $methodParams): array
	{
		foreach ($methodParams as $key => $methodParam) {
			/** @var ReflectionNamedType|null $classType */
			$classType = $methodParam->getType();

			if ($classType === null || !class_exists($classType->getName())) {
				continue;
			}

			$reflectionClass = new ReflectionClass($classType->getName());

			if ($reflectionClass->isSubclassOf(BaseRequest::class)) {
				$post = $this->getHttpRequest()->getRawBody();

				if ($post === null || $post === '') {
					throw new BadRequestException('Request is empty!');
				}

				$params[$key] = $reflectionClass->newInstance();
				$inputData = Json::decode($post, Json::FORCE_ARRAY);
				$params[$key]->buildForm($inputData); //@phpstan-ignore-line
				$params[$key]->validate();

				break;
			}

			if ($reflectionClass->getName() === JsonDateTime::class) {
				$params[$key] = new JsonDateTime($this->getHttpRequest()->getQuery($key)); //@phpstan-ignore-line
			}
		}

		$allowedKeys = [];

		foreach ($methodParams as $key => $methodParam) {
			$allowedKeys[$methodParam->getName()] = true;
			$allowedKeys[$key] = true;
		}

		return array_intersect_key($params, $allowedKeys);
	}

	protected function process(): ?BaseResponse
	{
		try {
			$this->beforeProcess();
			$params = $this->request->getParameters();
			$action = $params['action'];
			unset($params['action']);
			$this->tryCall($action, $params);

			return null;
		} catch (ResponseException $responseException) {
			if ($responseException->getCode() >= 100 && $responseException->getCode() < 600) {
				$this->getHttpResponse()->setCode($responseException->getCode());
			}

			$result = $responseException instanceof ValidationException
				? new ValidationErrorResponse($responseException)
				: new ErrorResponse($responseException);
		} catch (Throwable $ex) {
			$this->logger->error($ex->getMessage(), ['exception' => $ex]);
			$this->getHttpResponse()->setCode(500);
			$result = new ErrorResponse(new ResponseException($ex->getMessage()));
		}

		return $result;
	}

	protected function beforeProcess(): void
	{
		//override if needed
	}

	protected function setOptionsRequestHeaders(): void
	{
		$this->getHttpResponse()->addHeader('Access-Control-Allow-Headers', 'api-key, accept, Content-Type, session-id');
		$this->getHttpResponse()->addHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
		$this->getHttpResponse()->addHeader('Access-Control-Allow-Credentials', 'true');
		$this->getHttpResponse()->setCode(200);
	}

	/**
	 * @param BaseResponse|JsonObject|mixed[] $data
	 */
	private function setTranslatorOnJsonTranslatable(mixed $data): void
	{
		if (is_array($data)) {
			foreach ($data as $value) {
				if ($value instanceof JsonTranslatableMessage) {
					$value->setTranslator($this->translator);
				}

				if ($value instanceof JsonObject) {
					$this->setTranslatorOnJsonTranslatable($value);
				}
			}

			return;
		}

		$properties = get_object_vars($data);

		foreach ($properties as $property) {
			if ($property instanceof JsonObject || is_array($property)) {
				$this->setTranslatorOnJsonTranslatable($property);
			}

			if ($property instanceof JsonTranslatableMessage) {
				$property->setTranslator($this->translator);
			}
		}
	}

	private function getExpectedHttpMethod(ReflectionMethod $rm): string
	{
		$attributes = $rm->getAttributes(HttpMethod::class);

		if (count($attributes) > 0) {
			/** @var HttpMethod $httpMethod */
			$httpMethod = $attributes[0]->newInstance();

			return Strings::upper($httpMethod->value);
		}

		$params = $rm->getParameters();

		if (count($params) === 0) {
			return 'GET';
		}

		/** @var ReflectionNamedType|null $paramClass */
		$paramClass = $params[0]->getType();

		if ($paramClass === null) {
			return 'GET';
		}

		$paramClassName = $paramClass->getName();

		if (class_exists($paramClassName) && (new ReflectionClass($paramClassName))->isSubclassOf(BaseRequest::class)) {
			return 'POST';
		}

		return 'GET';
	}

}
