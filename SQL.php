<?php declare(strict_types=1);

/* slbans
 * Copyright (C) 2022 Lachesis
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

require_once('config.php');
require_once('SQLConnector.PDO.php');
//require_once('SQLConnector.MySQLi.php');

class SQLException extends Exception {}

/**
 * SQL factory class.
 * Contains various SQL constants and static functions to fetch/create SQL connectors.
 */
final class SQL
{
  // Query flags.
  const RESULT_1D    = 1;  // Output a 1D array of the first result, or null if there were no results.
  const RESULT_2D    = 2;  // Output a 2D array of the first result set (default).
  const RESULT_AUTO  = 4;  // Automatically select 1D or 2D array based on result size. Generally, don't use this...
  const RESULT_NUM   = 8;  // Return a numeric array.
  const RESULT_ASSOC = 16; // Return an associative array (default).

  const DEFAULT_FLAGS = SQL::RESULT_2D | SQL::RESULT_ASSOC;

  private static string $defaultClass = SQL_CONNECTOR ."_SQLConnector";
  private static ?SQLConnector $defaultInstance = null;

  /**
   * Return the default SQLConnector instance.
   * This should be used in most cases to avoid creating redundant connections.
   */
  public static function getInstance(): SQLConnector
  {
    if(is_null(self::$defaultInstance))
      self::$defaultInstance = self::newInstance();

    return self::$defaultInstance;
  }

  /**
   * Get a new SQLConnector instance.
   * Generally this isn't necessary and getInstance() should be used instead.
   */
  public static function newInstance(): SQLConnector
  {
    if(class_exists(self::$defaultClass))
    {
      return new self::$defaultClass();
    }
    else
      return new PDO_SQLConnector();
  }
}

/**
 * Interface for SQL connectors.
 * This is an abstract class mainly so the query monstrosity below doesn't need to be duplicated.
 */
abstract class SQLConnector
{
  /**
   * Query for a result from the database.
   * PHP 8 TODO: union types could clean this up a little bit while preserving type safety.
   *
   * @param  string $query   Query string.
   * @param  mixed $param1   Prepared statement parameters (array, optional, default []).
   * @param  mixed $param2   Flags for the query (i.e. return type) (int, optional, default SQL::RESULT_2D).
   * @return ?array          A results array, or null on failure. This may be affected by flags.
   */
  final public function query(string $query, $param1=null, $param2=null): ?array
  {
    $params = [];
    $flags = SQL::DEFAULT_FLAGS;

    $types = gettype($param1).','.gettype($param2);
    switch($types)
    {
      case 'NULL,NULL':
        break;

      case 'array,NULL':
        $params = $param1;
        break;

      case 'integer,NULL':
        $flags = $param1;
        break;

      case 'array,integer':
        $params = $param1;
        $flags = $param2;
        break;

      default:
        throw new TypeError("SQLConnector::query received invalid params: string,". $types);
    }
    return $this->_query($query, $params, $flags);
  }

  /**
   * Query implementation (see above).
   */
  abstract protected function _query(string $query, array $params, int $flags): ?array;

  /**
   * Execute an INSERT, UPDATE, DELETE, etc. statement.
   *
   * @param  string $query  Query string.
   * @param  array $params  Prepared statement parameters (optional, default []).
   * @return int            The number of affected rows (0 on failure).
   */
  abstract public function exec(string $query, ?array $params=null): int;

  /**
   * Open a prepared statement for reuse. See SQLStatement.
   *
   * @return ?SQLStatement  The prepared statement on success, or null on failure.
   */
  abstract public function prepare(string $query, int $flags=SQL::DEFAULT_FLAGS): ?SQLStatement;

  /**
   * Get the last inserted ID.
   *
   * @return int            The last inserted ID, or NULL on failure.
   */
  abstract public function lastID(): ?int;

  /**
   * Begin a transaction.
   *
   * @return bool           True on success or false on failure.
   */
  abstract public function beginTransaction(): bool;

  /**
   * Commit a transaction.
   *
   * @return bool           True on success or false on failure.
   */
  abstract public function commit(): bool;

  /**
   * Roll back a transaction.
   *
   * @return bool           True on success or false on failure.
   */
  abstract public function rollBack(): bool;

  /**
   * Enable debug logging.
   */
  abstract public function startLogging(): void;

  /**
   * Stop debug logging.
   */
  abstract public function stopLogging(): void;

  /**
   * Flush and reset the debug log.
   *
   * @return array          The contents of the debug log.
   */
  abstract public function flushLog(): array;
}

/**
 * Interface for manually interacting with prepared statements. An instance is returned by SQLConnector::prepare.
 * For simple use cases where no reuse is required, use SQLConnector::query or SQLConnector::exec instead.
 * This interface also supports queries with multiple result sets.
 */
interface SQLStatement
{
  public const READY      = 0;
  public const RESULT     = 1;
  public const RESULT_END = 2;

  /**
   * Fully close this prepared statement.
   */
  public function __destruct();

  /**
   * Execute this prepared statement with the given params.
   *
   * @param array $params   Parameters for the statement (default []).
   * @return bool           TRUE on success, otherwise FALSE.
   */
  public function execute(array $params=[]): bool;

  /**
   * Get the row count of the results set, or the number of affected rows.
   *
   * @return int            The number of results/affected rows, or 0 on failure.
   */
  public function rowCount(): int;

  /**
   * Get the next row of the result set.
   *
   * @return ?array         A numeric array of the next row, or NULL if there are no more rows.
   */
  public function row(): ?array;

  /**
   * Get every row of the result set.
   *
   * @return ?array         A 2D numeric array of the result set, an empty array if there are no rows, or null on failure.
   */
  public function rows(): ?array;

  /**
   * Advance to the next result set (if applicable).
   *
   * @return bool           TRUE on success if there is another available result set, otherwise FALSE.
   */
  public function nextResult(): bool;

  /**
   * Reset this prepared statement for reuse.
   *
   * @return bool           TRUE on success, otherwise FALSE.
   */
  public function reset(): bool;
}

