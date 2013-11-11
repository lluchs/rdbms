RDBMS support for the XP Framework
========================================================================
RDBMS access APIs, connection manager, reverse engineering, O/R mapping.

Supported drivers
-----------------

* Sybase (name: `sybase`)
* MySQL (name: `mysql`)
* PostgreSQL (name: `pgsql`)
* SQLite (name: `sqlite`)
* Interbase/FireBird (name: `ibase`)
* MSSQL (name: `mssql`)

Note: All of the above will require corresponding PHP extensions to be
loaded. See the classes' apidocs for dependency details.

The DriverManager model
-----------------------
To retrieve a connection class from the driver manager, you need to use 
the rdbms.DriverManager class. 

```php
$conn= \rdbms\DriverManager::getConnection('sybase://user:pass@server/NICOTINE');
```

The DriverManager class expects a unified connection string (we call it DSN).
For details, see the `DriverManager`'s apidoc.

Exceptions
----------
Methods will throw exceptions for failed SQL queries, syntax errors, connection
failure etc. All these exceptions are subclasses of `rdbms.SQLException`, so to
catch all possible errors, use it in the catch clause.

Basics
------
Once we have fetched a specific database connection class, we can now 
invoke a number of methods on it. 

```php
$conn= \rdbms\DriverManager::getConnection('sybase://user:pass@server/NICOTINE?autoconnect=1');
$news= $conn->select('news_id, caption, author_id from news');
```

The variable `$news` will now contain an array of all result sets
which in turn are associative arrays containing `field => value `
associations.

Dynamically creating SQL queries 
--------------------------------
To "bind" parameters to an SQL query, the query, select, update, delete 
and insert methods offer a printf style tokenizer and support varargs 
syntax. These take care of NULL and proper escaping for you. 

```php
// Selecting
$q= $conn->query('selectfrom news where news_id= %d', $newsId);

// Inserting
$conn->insert('
  into news (
    caption, author_id, body, extended, created_at
  ) values (
    %s, -- caption
    %d, -- author_id
    %s, -- body
    %s, -- extended
    %s  -- created_at
  )',
  $caption,
  $authorId,
  $body,
  $extended,
  Date::now()
);
```

Transactions
------------
To start a transaction, you can use the connection's begin method as 
follows: 

```php
public function createAuthor(...) {
  try {
    $tran= $conn->begin(new Transaction('create_author'));

    $id= $conn->insert('into author ...');
    $conn->insert('into notify ...');

    $tran->commit();
    return $id;
  } catch (SQLException $e) {
    $tran && $tran->rollback();
    throw $e;
  }
}
```

Note: Not all RDBMS' support transactions, and of those that do, not all 
support nested transactions. Be sure to read the manual pages of the RDBMS 
you are accessing. 

See also
--------
http://news.xp-framework.net/category/14/Databases/