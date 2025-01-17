<?php declare(strict_types = 1);

namespace Nextras\Dbal\Drivers\Pgsql;


use DateTimeZone;
use Exception;
use Nextras\Dbal\Drivers\Exception\ConnectionException;
use Nextras\Dbal\Drivers\Exception\DriverException;
use Nextras\Dbal\Drivers\Exception\ForeignKeyConstraintViolationException;
use Nextras\Dbal\Drivers\Exception\NotNullConstraintViolationException;
use Nextras\Dbal\Drivers\Exception\QueryException;
use Nextras\Dbal\Drivers\Exception\UniqueConstraintViolationException;
use Nextras\Dbal\Drivers\IDriver;
use Nextras\Dbal\Exception\InvalidArgumentException;
use Nextras\Dbal\Exception\InvalidStateException;
use Nextras\Dbal\Exception\NotSupportedException;
use Nextras\Dbal\IConnection;
use Nextras\Dbal\ILogger;
use Nextras\Dbal\Platforms\IPlatform;
use Nextras\Dbal\Platforms\PostgreSqlPlatform;
use Nextras\Dbal\Result\Result;
use Nextras\Dbal\Utils\LoggerHelper;
use Nextras\Dbal\Utils\StrictObjectTrait;
use function is_string;
use function pg_escape_identifier;


/**
 * Driver for php-pgsql ext.
 *
 * Supported configuration options:
 * - host - server name to connect;
 * - port - port to connect;
 * - database - db name to connect;
 * - options - options for pg_connect();
 * - username - username to connect;
 * - password - password to connect;
 * - sslmode - ssl mode for pg_connect();
 * - service - service config for pg_connect();
 * - searchPath - default search path for connection;
 * - connectionTz - timezone for database connection; possible values are:
 *    - "auto"
 *    - "auto-offset"
 *    - specific +-00:00 timezone offset;
 */
class PgsqlDriver implements IDriver
{
	use StrictObjectTrait;


	/** @var resource|null */
	private $connection;

	/** @var DateTimeZone */
	private $connectionTz;

	/** @var ILogger */
	private $logger;

	/** @var int */
	private $affectedRows = 0;

	/** @var float */
	private $timeTaken = 0.0;

	/** @var PgsqlResultNormalizerFactory */
	private $resultNormalizationFactory;


	public function __destruct()
	{
		$this->disconnect();
	}


	public function connect(array $params, ILogger $logger): void
	{
		static $knownKeys = [
			'host',
			'hostaddr',
			'port',
			'dbname',
			'user',
			'password',
			'connect_timeout',
			'options',
			'sslmode',
			'service',
		];

		$this->logger = $logger;

		$params = $this->processConfig($params);
		$connectionString = '';
		foreach ($knownKeys as $key) {
			if (isset($params[$key])) {
				$connectionString .= $key . '=' . $params[$key] . ' ';
			}
		}

		set_error_handler(function (int $code, string $message): bool {
			restore_error_handler();
			throw $this->createException($message, $code, null);
		}, E_ALL);

		$connection = pg_connect($connectionString, PGSQL_CONNECT_FORCE_NEW);
		assert($connection !== false); // connection error is handled in error_handler
		$this->connection = $connection;

		restore_error_handler();

		$this->resultNormalizationFactory = new PgsqlResultNormalizerFactory();

		$this->connectionTz = new DateTimeZone($params['connectionTz']);
		if (strpos($this->connectionTz->getName(), ':') !== false) {
			$this->loggedQuery('SET TIME ZONE INTERVAL ' . pg_escape_literal($connection, $this->connectionTz->getName()) . ' HOUR TO MINUTE');
		} else {
			$this->loggedQuery('SET TIME ZONE ' . pg_escape_literal($connection, $this->connectionTz->getName()));
		}

		if (isset($params['searchPath'])) {
			$schemas = array_map(function ($part): string {
				return $this->convertIdentifierToSql($part);
			}, (array) $params['searchPath']);
			$this->loggedQuery('SET search_path TO ' . implode(', ', $schemas));
		}
	}


	public function disconnect(): void
	{
		if ($this->connection !== null) {
			pg_close($this->connection);
			$this->connection = null;
		}
	}


	public function isConnected(): bool
	{
		return $this->connection !== null;
	}


	public function getResourceHandle()
	{
		return $this->connection;
	}


	public function getConnectionTimeZone(): DateTimeZone
	{
		return $this->connectionTz;
	}


	public function query(string $query): Result
	{
		assert($this->connection !== null);
		if (pg_send_query($this->connection, $query) === false) {
			throw $this->createException(pg_last_error($this->connection), 0, null);
		}

		$time = microtime(true);
		$resource = pg_get_result($this->connection);
		$this->timeTaken = microtime(true) - $time;

		if ($resource === false) {
			throw $this->createException(pg_last_error($this->connection), 0, null);
		}

		$state = pg_result_error_field($resource, PGSQL_DIAG_SQLSTATE);
		if (is_string($state)) {
			$error = pg_result_error($resource);
			throw $this->createException($error !== false ? $error : 'Unknown error', 0, $state, $query);
		}

		$this->affectedRows = pg_affected_rows($resource);
		return new Result(new PgsqlResultAdapter($resource, $this->resultNormalizationFactory));
	}


	public function getLastInsertedId(?string $sequenceName = null)
	{
		if ($sequenceName === null) {
			throw new InvalidArgumentException('PgsqlDriver requires to pass sequence name for getLastInsertedId() method.');
		}
		assert($this->connection !== null);
		$sql = 'SELECT CURRVAL(' . pg_escape_literal($this->connection, $sequenceName) . ')';
		return $this->loggedQuery($sql)->fetchField();
	}


	public function getAffectedRows(): int
	{
		return $this->affectedRows;
	}


	public function getQueryElapsedTime(): float
	{
		return $this->timeTaken;
	}


	public function createPlatform(IConnection $connection): IPlatform
	{
		return new PostgreSqlPlatform($connection);
	}


	public function getServerVersion(): string
	{
		assert($this->connection !== null);
		return pg_version($this->connection)['server'];
	}


	public function ping(): bool
	{
		assert($this->connection !== null);
		return pg_ping($this->connection);
	}


	public function setTransactionIsolationLevel(int $level): void
	{
		static $levels = [
			IConnection::TRANSACTION_READ_UNCOMMITTED => 'READ UNCOMMITTED',
			IConnection::TRANSACTION_READ_COMMITTED => 'READ COMMITTED',
			IConnection::TRANSACTION_REPEATABLE_READ => 'REPEATABLE READ',
			IConnection::TRANSACTION_SERIALIZABLE => 'SERIALIZABLE',
		];
		if (!isset($levels[$level])) {
			throw new NotSupportedException("Unsupported transaction level $level");
		}
		$this->loggedQuery("SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL {$levels[$level]}");
	}


	public function beginTransaction(): void
	{
		$this->loggedQuery('START TRANSACTION');
	}


	public function commitTransaction(): void
	{
		$this->loggedQuery('COMMIT');
	}


	public function rollbackTransaction(): void
	{
		$this->loggedQuery('ROLLBACK');
	}


	public function createSavepoint(string $name): void
	{
		assert($this->connection !== null);
		$this->loggedQuery('SAVEPOINT ' . $this->convertIdentifierToSql($name));
	}


	public function releaseSavepoint(string $name): void
	{
		assert($this->connection !== null);
		$this->loggedQuery('RELEASE SAVEPOINT ' . $this->convertIdentifierToSql($name));
	}


	public function rollbackSavepoint(string $name): void
	{
		assert($this->connection !== null);
		$this->loggedQuery('ROLLBACK TO SAVEPOINT ' . $this->convertIdentifierToSql($name));
	}


	public function convertStringToSql(string $value): string
	{
		assert($this->connection !== null);
		$escaped = pg_escape_literal($this->connection, $value);
		if ($escaped === false) {
			throw new InvalidStateException();
		}
		return $escaped;
	}


	protected function convertIdentifierToSql(string $identifier): string
	{
		assert($this->connection !== null);
		$escaped = pg_escape_identifier($this->connection, $identifier);
		if ($escaped === false) {
			throw new InvalidStateException();
		}
		return $escaped;
	}


	/**
	 * This method is based on Doctrine\DBAL project.
	 * @link www.doctrine-project.org
	 */
	protected function createException(string $error, int $errorNo, ?string $sqlState, ?string $query = null): Exception
	{
		// see codes at http://www.postgresql.org/docs/9.4/static/errcodes-appendix.html
		if ($sqlState === '0A000' && strpos($error, 'truncate') !== false) {
			// Foreign key constraint violations during a TRUNCATE operation
			// are considered "feature not supported" in PostgreSQL.
			return new ForeignKeyConstraintViolationException($error, $errorNo, $sqlState, null, $query);

		} elseif ($sqlState === '23502') {
			return new NotNullConstraintViolationException($error, $errorNo, $sqlState, null, $query);

		} elseif ($sqlState === '23503') {
			return new ForeignKeyConstraintViolationException($error, $errorNo, $sqlState, null, $query);

		} elseif ($sqlState === '23505') {
			return new UniqueConstraintViolationException($error, $errorNo, $sqlState, null, $query);

		} elseif ($sqlState === null && stripos($error, 'pg_connect()') !== false) {
			return new ConnectionException($error, $errorNo, '');

		} elseif ($query !== null) {
			return new QueryException($error, $errorNo, $sqlState ?? '', null, $query);

		} else {
			return new DriverException($error, $errorNo, $sqlState ?? '');
		}
	}


	protected function loggedQuery(string $sql): Result
	{
		return LoggerHelper::loggedQuery($this, $this->logger, $sql);
	}


	/**
	 * @phpstan-param array<string, mixed> $params
	 * @phpstan-return array<string, mixed>
	 */
	private function processConfig(array $params): array
	{
		if (!isset($params['database']) && isset($params['dbname'])) {
			throw new InvalidArgumentException("You have passed 'dbname' key, did you mean 'database' key?");
		}
		$params['dbname'] = $params['database'] ?? null;
		$params['user'] = $params['username'] ?? null;
		unset($params['database'], $params['username']);
		if (!isset($params['connectionTz']) || $params['connectionTz'] === IDriver::TIMEZONE_AUTO_PHP_NAME) {
			$params['connectionTz'] = date_default_timezone_get();
		} elseif ($params['connectionTz'] === IDriver::TIMEZONE_AUTO_PHP_OFFSET) {
			$params['connectionTz'] = date('P');
		}
		return $params;
	}
}
