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
 * SQL connector via MySQLi
 * This connector is based on the original connector for the deprecated PHP mysql library.
 * It has been tested less than the PDO connector and may be less reliable.
 * Use the PDO connector instead.
 */
class MySQLi_SQLConnector extends SQLConnector
{
  private $mysqli = null;
  private bool $errorlogOn = false;
  private array $errorlog = [];

  public function __construct() {}

  private function connect(): void
  {
    $this->log("connect", []);

    $this->mysqli = new MySQLi(SQL_HOST, SQL_USERNAME, SQL_PASSWORD, SQL_DATABASE);
    $this->mysqli->options(MYSQLI_OPT_INT_AND_FLOAT_NATIVE, true);
  }

  /**
   * Bind the params array for a query.
   * this function sux
   */
  public function statementBindParams(MySQLi_stmt $statement, array &$params): bool
  {
    $result = true;
    if(count($params))
    {
      $types = '';
      $realparams = [];

      for($i = 0; $i < count($params); $i++)
      {
        switch(gettype($params[$i]))
        {
          case "boolean":
          case "integer":
          case "NULL": // IDK if this goes here mysqli sux
            $types .= 'i';
            break;

          case "double":
            $types .= 'd';
            break;

          case "string":
            $types .= 's';
            break;

          case "object":
            if(method_exists($params[$i], '__toString'))
            {
              // kill me
              $types .= 's';
              $params[$i] = (string)$params[$i];
              break;
            }
            /* fall-through */

          default:
            throw new Exception("a bloo bla bloo bloo bloooo! bloo blooo! a bloo bla blooo!");
        }
        $realparams[] = &$params[$i];
      }
      $result = $statement->bind_param($types, ...$realparams);
      if($this->errorlogOn)
        $this->logStatement("bind_param", $statement);
    }
    return $result;
  }

  /**
   * Query and return the first result set as a 2D array or the first result as a 1D array.
   * Returns null on failure (or if no result was found for a 1D return value).
   */
  protected function _query(string $query, array $params, int $flags): ?array
  {
    if(!$this->mysqli)
      $this->connect();

    $this->log("query", [$query, $params, $flags]);

    $statement = $this->mysqli->prepare($query);
    $this->logMySQLi("prepare");
    if(!$statement)
      return null;

    $result = $this->statementBindParams($statement, $params);
    if(!$result)
      return null;

    $result = $statement->execute();
    $this->logStatement("execute", $statement);
    if(!$result)
      return null;

    // To bypass result binding the mysqli_result object needs to be fetched.
    // This requires mysqlnd, which should probably be installed anyway.
    // mysqli sux
    $result = null;
    $mysqli_result = $statement->get_result();
    $this->logStatement("get_result", $statement);
    if(!$mysqli_result)
      goto error;

    $fetch_style = MYSQLI_ASSOC;
    if($flags & SQL::RESULT_NUM)
      $fetch_style = ($flags & SQL::RESULT_ASSOC) ? MYSQLI_BOTH : MYSQLI_NUM;

    $fetch2D = true;
    if($flags & SQL::RESULT_AUTO)
    {
      $fetch2D = ($mysqli_result->num_rows > 1) ? true : false;
    }
    else

    if($flags & SQL::RESULT_1D)
      $fetch2D = false;

    if($fetch2D)
    {
      $result = $mysqli_result->fetch_all($fetch_style);
      if(!$result)
        $result = array();
    }
    else
    {
      $result = $mysqli_result->fetch_array($fetch_style);
    }
    $mysqli_result->free();

error:
    $statement->close();
    $this->logStatement("close", $statement);

    return $result;
  }

  public function exec(string $query, ?array $params=null): int
  {
    if(!$this->mysqli)
      $this->connect();

    $this->log("exec", [$query, $params]);
    $params ??= [];

    $statement = $this->mysqli->prepare($query);
    $this->logMySQLi("prepare");
    if(!$statement)
      return 0;

    $affected = 0;
    $result = $this->statementBindParams($statement, $params);
    if(!$result)
      goto error;

    $result = $statement->execute();
    $this->logStatement("execute", $statement);
    if($result)
      $affected = $statement->affected_rows;

error:
    $statement->close();
    $this->logStatement("close", $statement);

    return $affected;
  }

  public function prepare(string $query, int $flags=SQL::DEFAULT_FLAGS): ?SQLStatement
  {
    if(!$this->mysqli)
      $this->connect();

    $this->log("prepare", [$query, $flags]);

    $statement = $this->mysqli->prepare($query);
    $this->logMySQLi("prepare");

    return $statement ? new MySQLi_SQLStatement($this, $statement, $flags) : null;
  }

  public function lastID(): ?int
  {
    if(!$this->mysqli)
      return null;

    $this->log("lastID", []);
    return $this->mysqli->insert_id ? $this->mysqli->insert_id : null;
  }

  public function beginTransaction(): bool
  {
    if(!$this->mysqli)
      $this->connect();

    // TODO flags or name? idk lol...
    return $this->mysqli->begin_transaction();
  }

  public function commit(): bool
  {
    if(!$this->mysqli)
      return false;

    return $this->mysqli->commit();
  }

  public function rollBack(): bool
  {
    if(!$this->mysqli)
      return false;

    return $this->mysqli->rollback();
  }


/** Logging functions. */


  private function log(string $desc, array $data): void
  {
    if($this->errorlogOn)
      $this->errorlog[] = [ $desc, $data ];
  }

  private function logMySQLi(string $desc): void
  {
    if($this->errorlogOn)
      $this->errorlog[] = [ "mysqli->".$desc, $this->mysqli->error_list ];
  }

  public function logStatement(string $desc, MySQLi_stmt &$statement): void
  {
    if($this->errorlogOn)
      $this->errorlog[] = [ "stmt->".$desc, $statement->error_list ];
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
 * SQL statement abstraction via MySQLi.
 */
class MySQLi_SQLStatement implements SQLStatement
{
  private ?mysqli_result $mysqli_result = null;
  private $connector;
  private $statement;
  private $state;
  private $fetch_style;

  public function __construct(MySQLi_SQLConnector &$connector, MySQLi_stmt &$statement, int $flags)
  {
    $this->connector = $connector;
    $this->statement = $statement;
    $this->state = self::READY;

    $this->fetch_style = MYSQLI_ASSOC;
    if($flags & SQL::RESULT_NUM)
      $this->fetch_style = ($flags & SQL::RESULT_ASSOC) ? MYSQLI_BOTH : MYSQLI_NUM;
  }

  /**
   * Fully close this prepared statement.
   */
  public function __destruct()
  {
    $this->statement->close();
    $this->connector->logStatement("close", $this->statement);
    unset($this->statement);
  }

  private function getResultSet(): bool
  {
    if($this->mysqli_result)
    {
      $this->mysqli_result->free();
      unset($this->mysqli_result);
    }

    $mysqli_result = $this->statement->get_result();
    $this->connector->logStatement("get_result", $this->statement);

    $this->mysqli_result = $mysqli_result ? $mysqli_result : null;
    return !!($mysqli_result);
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

    $result = $this->connector->statementBindParams($this->statement, $params);
    if(!$result)
      return false;

    $result = $this->statement->execute();
    $this->connector->logStatement('execute', $this->statement);
    if($result)
    {
      $this->state = self::RESULT;
      $this->getResultSet();
    }
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

    return $this->statement->num_rows ? $this->statement->num_rows : $this->statement->affected_rows;
  }

  /**
   * Get the next row of the result set.
   *
   * @return ?array         An array of the next row, or NULL if there are no more rows.
   */
  public function row(): ?array
  {
    if($this->state != self::RESULT)
      throw new SQLException('Call SQLStatement::execute before fetching a result!');

    if(!$this->mysqli_result)
      return null;

    $result = $this->mysqli_result->fetch_array($this->fetch_style);
    return is_array($result) ? $result : null;
  }

  /**
   * Get every row of the result set.
   *
   * @return ?array         A 2D array of the result set, an empty array if there are no rows, or null on failure.
   */
  public function rows(): ?array
  {
    if($this->state != self::RESULT)
      throw new SQLException('Call SQLStatement::execute before fetching a result!');

    if(!$this->mysqli_result)
      return null;

    return $this->mysqli_result->fetch_all($this->fetch_style);
    //return is_array($result) ? $result : null;
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

    $result = $this->getResultSet();
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
      $result = $this->statement->reset();
      $this->connector->logStatement("reset", $this->statement);
      if($result)
        $this->state = self::READY;

      return $result;
    }
    return true;
  }
}
