<?php namespace rdbms\mysqlx;

use rdbms\DBConnection;
use rdbms\Transaction;
use rdbms\StatementFormatter;
use rdbms\mysql\MysqlDialect;

/**
 * Connection to MySQL Databases
 *
 * @see   http://mysql.org/
 * @test  xp://net.xp_framework.unittest.rdbms.TokenizerTest
 * @test  xp://net.xp_framework.unittest.rdbms.DBTest
 */
class MySqlxConnection extends DBConnection {
  protected $affected= -1;

  static function __static() {
    \rdbms\DriverManager::register('mysql+x', new \lang\XPClass(__CLASS__));
  }

  /**
   * Constructor
   *
   * @param   rdbms.DSN dsn
   */
  public function __construct($dsn) { 
    parent::__construct($dsn);
    $this->formatter= new StatementFormatter($this, new MysqlDialect());

    // Use local socket (unix socket on Un*x systems, named pipe on Windows)
    // if "." is supplied as hostname
    $host= $this->dsn->getHost();
    if ('.' === $host) {
      $sock= LocalSocket::forName(PHP_OS)->newInstance($this->dsn->getProperty('socket', null));
    } else {
      $sock= new \peer\Socket($host, $this->dsn->getPort(3306));
    }

    $this->handle= new MySqlxProtocol($sock);
  }

  /**
   * Returns a hashcode for this connection
   *
   * Example:
   * <pre>
   *   mysqlx link #50
   * </pre>
   *
   * @return  string
   */
  public function hashCode() {
    return 'mysqlx link #'.$this->handle->hashCode();
  }

  /**
   * Connect
   *
   * @param   bool reconnect default FALSE
   * @return  bool success
   * @throws  rdbms.SQLConnectException
   */
  public function connect($reconnect= false) {
    if ($this->handle->connected) return true;                    // Already connected
    if (!$reconnect && (null === $this->handle->connected)) return false;   // Previously failed connecting

    $this->_obs && $this->notifyObservers(new \rdbms\DBEvent(\rdbms\DBEvent::CONNECT, $reconnect));
    try {
      $this->handle->connect($this->dsn->getUser(), $this->dsn->getPassword());
      $this->_obs && $this->notifyObservers(new \rdbms\DBEvent(\rdbms\DBEvent::CONNECTED, $reconnect));
    } catch (\io\IOException $e) {
      $this->handle->connected= null;
      $this->_obs && $this->notifyObservers(new \rdbms\DBEvent(\rdbms\DBEvent::CONNECTED, $reconnect));
      throw new \rdbms\SQLConnectException($e->getMessage(), $this->dsn);
    }

    try {
      $this->handle->exec('set names UTF8');

      // Figure out sql_mode and update formatter's escaperules accordingly
      // - See: http://bugs.mysql.com/bug.php?id=10214
      // - Possible values: http://dev.mysql.com/doc/refman/5.0/en/server-sql-mode.html
      // "modes is a list of different modes separated by comma (,) characters."
      $modes= array_flip(explode(',', this(this($this->handle->consume($this->handle->query(
        "show variables like 'sql_mode'"
      )), 0), 1)));
    } catch (\io\IOException $e) {
      // Ignore
    }
    
    // NO_BACKSLASH_ESCAPES: Disable the use of the backslash character 
    // (\) as an escape character within strings. With this mode enabled, 
    // backslash becomes any ordinary character like any other. 
    // (Implemented in MySQL 5.0.1)
    isset($modes['NO_BACKSLASH_ESCAPES']) && $this->formatter->dialect->setEscapeRules(array(
      '"'   => '""'
    ));

    return parent::connect();
  }
  
  /**
   * Disconnect
   *
   * @return  bool success
   */
  public function close() {
    if (!$this->handle->connected) return false;
    $this->handle->close();
    return true;
  }
  
  /**
   * Select database
   *
   * @param   string db name of database to select
   * @return  bool success
   * @throws  rdbms.SQLStatementFailedException
   */
  public function selectdb($db) {
    try {
      $this->handle->exec('use '.$db);
      return true;
    } catch (\io\IOException $e) {
      throw new \rdbms\SQLStatementFailedException($e->getMessage());
    }
  }

  /**
   * Retrieve identity
   *
   * @return  var identity value
   */
  public function identity($field= null) {
    $i= $this->query('select last_insert_id() as xp_id')->next('xp_id');
    $this->_obs && $this->notifyObservers(new \rdbms\DBEvent(\rdbms\DBEvent::IDENTITY, $i));
    return $i;
  }

  /**
   * Retrieve number of affected rows for last query
   *
   * @return  int
   */
  protected function affectedRows() {
    return $this->affected;
  }    
  
  /**
   * Execute any statement
   *
   * @param   string sql
   * @param   bool buffered default TRUE
   * @return  rdbms.ResultSet or TRUE if no resultset was created
   * @throws  rdbms.SQLException
   */
  protected function query0($sql, $buffered= true) {
    if (!$this->handle->connected) {
      if (!($this->flags & DB_AUTOCONNECT)) throw new \rdbms\SQLStateException('Not connected');
      $c= $this->connect();
      
      // Check for subsequent connection errors
      if (false === $c) throw new \rdbms\SQLStateException('Previously failed to connect.');
    }
    
    try {
      $this->handle->ready() || $this->handle->cancel();
      $result= $this->handle->query($sql);
    } catch (MySqlxProtocolException $e) {
      $message= $e->getMessage().' (sqlstate '.$e->sqlstate.')';
      switch ($e->error) {
        case 2006: // MySQL server has gone away
        case 2013: // Lost connection to MySQL server during query
          throw new \rdbms\SQLConnectionClosedException('Statement failed: '.$message, $sql, $e->error);

        case 1213: // Deadlock
          throw new \rdbms\SQLDeadlockException($message, $sql, $e->error);
        
        default:   // Other error
          throw new \rdbms\SQLStatementFailedException($message, $sql, $e->error);
      }
    } catch (\io\IOException $e) {
      throw new \rdbms\SQLStatementFailedException($e->getMessage());
    }
    
    if (!is_array($result)) {
      $this->affected= $result;
      return true;
    }

    $this->affected= -1;
    if (!$buffered || $this->flags & DB_UNBUFFERED) {
      return new MySqlxResultSet($this->handle, $result, $this->tz);
    } else {
      return new MySqlxBufferedResultSet($this->handle, $result, $this->tz);
    }
  }

  /**
   * Begin a transaction
   *
   * @param   rdbms.Transaction transaction
   * @return  rdbms.Transaction
   */
  public function begin($transaction) {
    if (!$this->query('begin')) return false;
    $transaction->db= $this;
    return $transaction;
  }
  
  /**
   * Rollback a transaction
   *
   * @param   string name
   * @return  bool success
   */
  public function rollback($name) { 
    return $this->query('rollback');
  }
  
  /**
   * Commit a transaction
   *
   * @param   string name
   * @return  bool success
   */
  public function commit($name) { 
    return $this->query('commit');
  }

  /**
   * Creates a string representation
   *
   * @return  string
   */
  public function toString() {
    return $this->getClassName().'(->'.$this->dsn->toString().', '.$this->handle->toString().')';
  }
}
