<?php

namespace NextrasTests\Dbal;

use Nextras\Dbal\SqlProcessor;
use Tester\Assert;

require_once __DIR__ . '/../../bootstrap.php';


class SqlProcessorArrayTest extends TestCase
{
	/** @var SqlProcessor */
	private $parser;


	protected function setUp()
	{
		parent::setUp();
		$driver = \Mockery::mock('Nextras\Dbal\Drivers\IDriver');
		$driver->shouldReceive('getTokenRegexp')->andReturn('');
		$this->parser = new SqlProcessor($driver);
	}


	public function testArray()
	{
		Assert::same(
			'SELECT FROM test WHERE id IN (1, 2, 3)',
			$this->convert('SELECT FROM test WHERE id IN %i[]', [1, '02.0', 3])
		);

		Assert::same(
			'SELECT FROM test WHERE id IN ()',
			$this->convert('SELECT FROM test WHERE id IN %i[]', NULL)
		);

		Assert::same(
			'SELECT FROM test WHERE id IN (NULL, 2, 3)',
			$this->convert('SELECT FROM test WHERE id IN %i?[]', [NULL, 2, 3])
		);
	}


	private function convert($sql)
	{
		return $this->parser->process(func_get_args());
	}

}

$test = new SqlProcessorArrayTest();
$test->run();