<?php declare(strict_types = 1);

namespace Wedo\Api\Tests\Requests;

use Wedo\Api\Attributes\ContainerType;
use Wedo\Api\Attributes\Control;
use Wedo\Api\Requests\BaseRequest;

class TestSingleContainerRequest extends BaseRequest
{

	#[Control(Control::TEXT)]
	public ?string $title = null;

	#[Control(Control::CONTAINER)]
	#[ContainerType(SimpleRequest2::class)]
	public ?SimpleRequest2 $single = null;

	/** @var SimpleRequest2[] $items */
	#[Control(Control::CONTAINER)]
	#[ContainerType(SimpleRequest2::class)]
	public array $items = [];

}
