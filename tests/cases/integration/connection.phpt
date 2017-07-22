<?php declare(strict_types = 1);

/**
 * @testCase
 * @dataProvider? ../../databases.ini
 */

namespace NextrasTests\Dbal;

use Nextras\Dbal\InvalidStateException;
use Tester\Assert;


require_once __DIR__ . '/../../bootstrap.php';


class ConnectionTest extends IntegrationTestCase
{
	public function testPing()
	{
		Assert::false($this->connection->getDriver()->isConnected());
		Assert::false($this->connection->ping());
		Assert::false($this->connection->getDriver()->isConnected());
		$this->connection->connect();
		Assert::true($this->connection->getDriver()->isConnected());
		Assert::true($this->connection->ping());
	}


	public function testFireEvent()
	{
		$log = [];
		$this->connection->onConnect[] = function () use (& $log) {
			$log[] = 'connect';
		};
		$this->connection->onDisconnect[] = function () use (& $log) {
			$log[] = 'disconnect';
		};

		$this->connection->ping();
		$this->connection->reconnect();
		$this->connection->disconnect();

		Assert::same([
			'connect',
			'disconnect',
		], $log);
	}


	public function testFireEvent2()
	{
		$log = [];
		$this->connection->onConnect[] = function () use (& $log) {
			$log[] = 'connect';
		};
		$this->connection->onDisconnect[] = function () use (& $log) {
			$log[] = 'disconnect';
		};

		$this->connection->disconnect();
		$this->connection->reconnect();
		$this->connection->connect();
		Assert::same(['connect'], $log);
	}


	public function testMissingDriver()
	{
		Assert::exception(function () {
			$this->createConnection(['driver' => null]);
		}, InvalidStateException::class);
	}
}


$test = new ConnectionTest();
$test->run();
