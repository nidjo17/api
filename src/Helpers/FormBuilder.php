<?php declare (strict_types = 1);

namespace Wedo\Api\Helpers;

use Nette\Application\UI\Form;
use Nette\ArgumentOutOfRangeException;
use Nette\Forms\Container;
use Nette\Forms\Controls\BaseControl;
use Nette\Forms\Controls\ChoiceControl;
use Nette\NotSupportedException;
use ReflectionNamedType;
use ReflectionProperty;
use Throwable;
use Wedo\Api\Attributes\ChoiceControlItems;
use Wedo\Api\Attributes\ContainerType;
use Wedo\Api\Attributes\Control;
use Wedo\Api\Requests\BaseRequest;

class FormBuilder
{

	/**
	 * @param ReflectionProperty[] $properties
	 * @param mixed[] $data
	 */
	public function createForm(array $properties, BaseRequest $request, Container $form, array $data): void
	{
		foreach ($properties as $property) {
			$controlType = $this->getControlType($property);
			$controlAddCallback = [$form, 'add' . $controlType];

			try {
				$control = $controlAddCallback($property->getName()); //@phpstan-ignore-line
			} catch (Throwable $ex) {
				throw new NotSupportedException('Cannot add control of type ' . $controlType, 0, $ex);
			}

			if ($controlType === Control::CONTAINER) {
				/** @var Container $container */
				$container = $control;

				if ($this->isSingleObjectContainer($property)) {
					$this->buildSingleObjectContainer($property, $request, $container, $data);
				} else {
					$this->buildContainerList($property, $request, $container, $data);
				}
			}

			if ($control instanceof BaseControl) {
				$request->setValidationRules($property, $control);
			}

			if ($control instanceof ChoiceControl) {
				$itemsAttributes = $property->getAttributes(ChoiceControlItems::class);

				if (count($itemsAttributes) > 0) {
					/** @var ChoiceControlItems $itemAttribute */
					$itemAttribute = $itemsAttributes[0]->newInstance();
					$control->setItems($itemAttribute->items, $itemAttribute->useKeys);
				}
			}
		}
	}

	/**
	 * A container property typed as a BaseRequest subclass holds one nested object,
	 * not a list of them.
	 */
	protected function isSingleObjectContainer(ReflectionProperty $property): bool
	{
		$type = $property->getType();

		return $type instanceof ReflectionNamedType
			&& !$type->isBuiltin()
			&& is_subclass_of($type->getName(), BaseRequest::class);
	}

	/**
	 * @param mixed[] $data
	 */
	protected function buildContainerList(
		ReflectionProperty $property,
		BaseRequest $request,
		Container $container,
		array $data
	): void
	{
		/** @phpstan-ignore-next-line */
		$request->{$property->getName()} = [];

		/** @phpstan-ignore-next-line */
		if (empty($data[$property->getName()])) {
			return;
		}

		/** @var mixed[][] $values */
		$values = $data[$property->getName()];

		$requestType = $this->getContainerType($property);

		foreach ($values as $key => $value) {
			/** @var Container $itemContainer */
			$itemContainer = $container->addContainer($key);
			$item = new $requestType();
			$item->buildForm($value, $itemContainer, $item);
			/** @phpstan-ignore-next-line */
			$request->{$property->getName()}[] = $item;
		}
	}

	/**
	 * @param mixed[] $data
	 */
	protected function buildSingleObjectContainer(
		ReflectionProperty $property,
		BaseRequest $request,
		Container $container,
		array $data
	): void
	{
		/** @var ReflectionNamedType $type */
		$type = $property->getType();
		$values = $data[$property->getName()] ?? null;

		if (!is_array($values) || $values === []) {
			if ($type->allowsNull()) {
				/** @phpstan-ignore-next-line */
				$request->{$property->getName()} = null;
			}

			return;
		}

		$requestType = $this->getContainerType($property);
		$item = new $requestType();
		$item->buildForm($values, $container, $item);
		/** @phpstan-ignore-next-line */
		$request->{$property->getName()} = $item;
	}

	/**
	 * @return class-string<BaseRequest>
	 */
	protected function getContainerType(ReflectionProperty $property): string
	{
		$requestTypeAttributes = $property->getAttributes(ContainerType::class);

		if (count($requestTypeAttributes) === 0) {
			throw new NotSupportedException('ContainerType attribute not found for ' .
				$property->getDeclaringClass()->getName() . '::' . $property->getName());
		}

		/** @var ContainerType $requestTypeAttribute */
		$requestTypeAttribute = $requestTypeAttributes[0]->newInstance();

		/** @var class-string<BaseRequest> $requestType */
		$requestType = $requestTypeAttribute->value;

		return $requestType;
	}

	public function createEmptyForm(): Form
	{
		return new Form();
	}

	/**
	 * @throws ArgumentOutOfRangeException
	 */
	protected function getControlType(ReflectionProperty $property): string
	{
		$controlAttributes = $property->getAttributes(Control::class);

		if (count($controlAttributes) === 0) {
			throw new ArgumentOutOfRangeException('#[Control] Attribute not set on ' .
				$property->getDeclaringClass()->getName() . '::' . $property->getName());
		}

		/** @var Control $controlAttribute */
		$controlAttribute = $controlAttributes[0]->newInstance();

		return $controlAttribute->value;
	}

}
