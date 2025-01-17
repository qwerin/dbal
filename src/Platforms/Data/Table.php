<?php declare(strict_types = 1);

namespace Nextras\Dbal\Platforms\Data;


use Nextras\Dbal\Utils\StrictObjectTrait;


class Table
{
	use StrictObjectTrait;


	/** @var string */
	public $name;

	/** @var string */
	public $schema;

	/** @var bool */
	public $isView;


	/**
	 * Returns unescaped string expression with schema (database) name and table name.
	 */
	public function getUnescapedFqn(): string
	{
		if ($this->schema === '') {
			return $this->name;
		} else {
			return "$this->schema.$this->name";
		}
	}


	/**
	 * Returns Dbal's expression that will provide proper escaping for SQL usage.
	 * Use with %ex modifier:
	 * ```php
	 * $connection->query('... WHERE %ex.[id] = 1', $table->getFqnExpression());
	 * ```
	 * @return array<mixed>
	 */
	public function getFqnExpression(): array
	{
		if ($this->schema === '') {
			return ['%table', $this->name];
		} else {
			return ['%table.%table', $this->schema, $this->name];
		}
	}
}
