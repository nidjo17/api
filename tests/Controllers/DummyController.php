<?php declare(strict_types = 1);

namespace Wedo\Api\Tests\Controllers;

use Psr\Log\NullLogger;
use Wedo\Api\Controllers\Controller;
use Wedo\Api\Tests\Requests\SimpleRequest;

class DummyController extends Controller
{

	public function __construct()
	{
		$this->logger = new NullLogger();
	}

	/**
	 * @return string[]
	 */
	public function hello(string $name = 'world'): array
	{
		return ['name' => $name];
	}

	/**
	 * @return string[]
	 */
	public function submit(SimpleRequest $request): array
	{
		return ['name' => $request->name];
	}

}
