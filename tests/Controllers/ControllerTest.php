<?php declare(strict_types = 1);

namespace Wedo\Api\Tests\Controllers;

use Nette\Application\Request as AppRequest;
use Nette\Application\Responses\JsonResponse;
use Nette\Http\Request;
use Nette\Http\Response;
use Nette\Http\UrlScript;
use PHPUnit\Framework\TestCase;

class ControllerTest extends TestCase
{

	public function testRun_WithUnknownNamedParam_ParamIsIgnored(): void
	{
		$response = $this->runController('hello', ['name' => 'John', 'utm_source' => 'newsletter']);

		$this->assertSame(['name' => 'John'], $response->getPayload());
	}

	public function testRun_WithExtraPositionalParam_ParamIsIgnored(): void
	{
		$response = $this->runController('hello', ['John', 'extra-url-segment']);

		$this->assertSame(['name' => 'John'], $response->getPayload());
	}

	public function testRun_WithKnownParamsOnly_BehavesAsBefore(): void
	{
		$response = $this->runController('hello', ['name' => 'John']);

		$this->assertSame(['name' => 'John'], $response->getPayload());
	}

	public function testRun_WithRequestObjectAndUnknownParam_RequestObjectStaysBound(): void
	{
		$response = $this->runController(
			'submit',
			['utm_source' => 'newsletter'],
			'POST',
			'{"name": "John"}'
		);

		$this->assertSame(['name' => 'John'], $response->getPayload());
	}

	/**
	 * @param mixed[] $params
	 */
	private function runController(
		string $action,
		array $params,
		string $method = 'GET',
		?string $rawBody = null
	): JsonResponse
	{
		$controller = new DummyController();
		$controller->injectRequestAndResponse(
			new Request(new UrlScript('http://domain.local/'), method: $method, rawBodyCallback: static fn () => $rawBody),
			new Response()
		);

		$params['action'] = $action;

		/** @var JsonResponse $response */
		$response = $controller->run(new AppRequest('Dummy', $method, $params));

		return $response;
	}

}
