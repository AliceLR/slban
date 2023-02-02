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

require_once('SQL.php');
require_once('config.php');

/**
 * SQL connector via PDO.
 */
class PDO_SQLConnector extends SQLConnector
{
  private array $errorlog = array();
  private bool $errorlogOn = false;
  private $pdo = null;

  /**
   * Instantiate an SQL connector for a single connection.
   * This should typically not be used; use SQL's static functions unless multiple connections
   * are actually required or non-default connection flags need to be specified.
   */
  public function __construct()
  {
    // TODO be able to receive configuration flags maybe.
    // Example: a script running as daemon to process queued requests might want a persistent connection.
  }

  private function connect(): void
  {
    $this->log("connect", []);

    $options =
    [
      //PDO::ATTR_PERSISTENT => true, // Generally considered more trouble than it's worth for Apache.
    ];

    $pdo = new PDO(
      SQL_DRIVER .':host='. SQL_HOST .';dbname='. SQL_DATABASE,
      SQL_USERNAME, SQL_PASSWORD //, $options
    );

    // Not clear if the constructor "driver options" array is supposed to be able to take these.
    $pdo->setAttribute(PDO::ATTR_ERRMODE,           PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_CASE,              PDO::CASE_NATURAL);
    $pdo->setAttribute(PDO::ATTR_ORACLE_NULLS,      PDO::NULL_NATURAL);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES,  false);
    $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);
    $this->pdo = $pdo;
  }

  /**
   * Query and return the first result set as a 2D array or the first result as a 1D array.
   * Returns null on failure (or if no result was found for a 1D return value).
   */
  protected function _query(string $query, array $params, int $flags): ?array
  {
    if(!$this->pdo)
      $this->connect();

    $this->log("query", [$query, $params, $flags]);

    $statement = $this->pdo->prepare($query);
    $this->logPDO("prepare");
    if(!$statement)
      return null;

    $result = $statement->execute($params);
    $this->logStatement("execute", $statement);
    if(!$result)
      return null;

    $fetch_style = PDO::FETCH_ASSOC;
    if($flags & SQL::RESULT_NUM)
      $fetch_style = ($flags & SQL::RESULT_ASSOC) ? PDO::FETCH_BOTH : PDO::FETCH_NUM;

    $fetch2D = true;
    if($flags & SQL::RESULT_AUTO)
    {
      $fetch2D = ($statement->rowCount() > 1) ? true : false;
    }
    else

    if($flags & SQL::RESULT_1D)
      $fetch2D = false;

    if($fetch2D)
    {
      $result = $statement->fetchAll($fetch_style);
      $this->logStatement("fetchAll", $statement);
    }
    else
    {
      $result = $statement->fetch($fetch_style);
      $this->logStatement("fetch", $statement);
    }

    $this->destroyStatement($statement);

    // The fetch functions return false on failure but null is preferable for strong typing...
    return is_array($result) ? $result : null;
  }

  /**
   * Execute an INSERT/DELETE/UPDATE statement. Returns the number of rows affected.
   * Params should be provided as an array and not escaped into the query.
   */
  public function exec(string $query, ?array $params=null): int
  {
    if(!$this->pdo)
      $this->connect();

    $this->log("exec", [$query, $params]);

    $statement = $this->pdo->prepare($query);
    $this->logPDO("prepare");
    if(!$statement)
      return 0;

    $result = $statement->execute($params);
    $this->logStatement("execute", $statement);

    // NOTE: According to the PHP documentation, this may have a driver-specific
    // compatibility issue where some drivers return 0.
    // https://www.php.net/manual/en/pdostatement.rowcount.php
    $rowcount = $result ? $statement->rowCount() : 0;

    $this->destroyStatement($statement);
    return $rowcount;
  }

  /**
   * Prepare a statement and return a reusable statement handler.
   */
  public function prepare(string $query, int $flags=SQL::DEFAULT_FLAGS): ?SQLStatement
  {
    if(!$this->pdo)
      $this->connect();

    $this->log("prepare", [$query, $flags]);

    $statement = $this->pdo->prepare($query);
    $this->logPDO("prepare");

    return $statement ? new PDO_SQLStatement($this, $statement, $flags) : null;
  }

  /**
   * Get the last inserted row ID.
   * Returns NULL on failure.
   */
  public function lastID(): ?int
  {
    if(!$this->pdo)
      return null;

    $this->log("lastID", []);

    $result = $this->pdo->lastInsertId();
    $this->logPDO("lastInsertID");
    return $result ? intval($result) : null;
  }

  /**
   * Begin a transaction.
   *
   * @return bool           True on success or false on failure.
   */
  public function beginTransaction(): bool
  {
    if(!$this->pdo)
      $this->connect();

    return $this->pdo->beginTransaction();
  }

  /**
   * Commit a transaction.
   *
   * @return bool           True on success or false on failure.
   */
  public function commit(): bool
  {
    if(!$this->pdo)
      return false;

    return $this->pdo->commit();
  }

  /**
   * Roll back a transaction.
   *
   * @return bool           True on success or false on failure.
   */
  public function rollBack(): bool
  {
    if(!$this->pdo)
      return false;

    return $this->pdo->rollBack();
  }

  // mysqlnd doesn't seem to respect closeCursor() when emulated statements are disabled.
  // Manually flush all of the results so the connection doesn't break...
  private function destroyStatement(PDOStatement &$statement): void
  {
    do
    {
      while($statement->fetch());
    }
    while($statement->nextRowset());

    $statement->closeCursor();
    $this->logStatement("closeCursor", $statement);
    unset($statement);
  }


/** Logging functions. */


  private function log(string $desc, array $data): void
  {
    if($this->errorlogOn)
      $this->errorlog[] = [ $desc, $data ];
  }

  private function logPDO(string $desc): void
  {
    if($this->errorlogOn)
      $this->errorlog[] = [ "pdo->".$desc, $this->pdo->errorInfo() ];
  }

  public function logStatement(string $desc, PDOStatement &$statement): void
  {
    if($this->errorlogOn)
      $this->errorlog[] = [ "stmt->".$desc, $statement->errorInfo() ];
  }

  public function startLogging(): void
  {
    $this->errorlogOn = true;
    $this->errorlog = [];
  }

  public function stopLogging(): void
  {
    $this->errorlogOn = false;
  }

  public function flushLog(): array
  {
    $result = $this->errorlog;
    $this->errorlog = [];
    return $result;
  }
}

/**
 * SQL statement via PDO.
 */
class PDO_SQLStatement implements SQLStatement
{
  private $connector;
  private $statement;
  private $state;
  private $fetch_style;

  public function __construct(PDO_SQLConnector $connector, PDOStatement $statement, int $flags)
  {
    $this->connector = $connector;
    $this->statement = $statement;
    $this->state = self::READY;

    $this->fetch_style = PDO::FETCH_ASSOC;
    if($flags & SQL::RESULT_NUM)
      $this->fetch_style = ($flags & SQL::RESULT_ASSOC) ? PDO::FETCH_BOTH : PDO::FETCH_NUM;
  }

  /**
   * Fully close this prepared statement.
   */
  public function __destruct()
  {
    $this->reset();
    unset($this->statement);
  }

  /**
   * Execute this prepared statement with the given params.
   *
   * @param array $params   Parameters for the statement (default []).
   * @return bool           TRUE on success, otherwise FALSE.
   */
  public function execute(array $params=[]): bool
  {
    if($this->state != self::READY)
      throw new SQLException('Call SQLStatement::reset before reusing a statement!');

    $result = $this->statement->execute($params);
    $this->connector->logStatement('execute', $this->statement);
    if($result)
      $this->state = self::RESULT;

    return $result;
  }

  /**
   * Get the row count of the results set, or the number of affected rows.
   *
   * @return int            The number of results/affected rows, or 0 on failure.
   */
  public function rowCount(): int
  {
    if($this->state != self::RESULT)
      throw new SQLException('Call SQLStatement::execute before fetching a result!');

    return $this->statement->rowCount();
  }

  /**
   * Get the next row of the result set.
   *
   * @return ?array         A numeric array of the next row, or NULL if there are no more rows.
   */
  public function row(): ?array
  {
    if($this->state != self::RESULT)
      throw new SQLException('Call SQLStatement::execute before fetching a result!');

    $result = $this->statement->fetch($this->fetch_style);
    $this->connector->logStatement("fetch", $this->statement);
    return is_array($result) ? $result : null;
  }

  /**
   * Get every row of the result set.
   *
   * @return ?array         A 2D numeric array of the result set, an empty array if there are no rows, or null on failure.
   */
  public function rows(): ?array
  {
    if($this->state != self::RESULT)
      throw new SQLException('Call SQLStatement::execute before fetching a result!');

    $result = $this->statement->fetchAll($this->fetch_style);
    $this->connector->logStatement("fetchAll", $this->statement);
    return is_array($result) ? $result : null;
  }

  /**
   * Advance to the next result set (if applicable).
   *
   * @return bool           TRUE on success if there is another available result set, otherwise FALSE.
   */
  public function nextResult(): bool
  {
    if($this->state == self::RESULT_END)
      return false;

    if($this->state != self::RESULT)
      throw new SQLException('Call SQLStatement::execute before fetching a result!');

    while($this->statement->fetch());
    $result = $this->statement->nextRowset();
    if(!$result)
      $this->state = self::RESULT_END;

    return $result;
  }

  /**
   * Reset this prepared statement for reuse.
   *
   * @return bool           TRUE on success, otherwise FALSE.
   */
  public function reset(): bool
  {
    if($this->state == self::RESULT)
    {
      while($this->nextResult());
    }

    if($this->state == self::RESULT_END)
    {
      $result = $this->statement->closeCursor();
      $this->connector->logStatement("closeCursor", $this->statement);
      if($result)
        $this->state = self::READY;

      return $result;
    }
    return true;
  }
}
