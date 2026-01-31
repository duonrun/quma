# Quma

[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg)](LICENSE.md)
[![Codacy Badge](https://app.codacy.com/project/badge/Grade/274d61ae59344c48868d88da2acd6a5c)](https://app.codacy.com/gh/duonrun/quma/dashboard?utm_source=gh&utm_medium=referral&utm_content=&utm_campaign=Badge_grade)
[![Codacy Badge](https://app.codacy.com/project/badge/Coverage/274d61ae59344c48868d88da2acd6a5c)](https://app.codacy.com/gh/duonrun/quma/dashboard?utm_source=gh&utm_medium=referral&utm_content=&utm_campaign=Badge_coverage)
[![Psalm level](https://shepherd.dev/github/duonrun/quma/level.svg?)](https://duonrun.dev/quma)
[![Psalm coverage](https://shepherd.dev/github/duonrun/quma/coverage.svg?)](https://shepherd.dev/github/duonrun/quma)

This project is a PHP port of Python library [quma](https://quma.readthedocs.io).

## Test Databases

Set up a local test database with a dedicated user. Both CLI and SQL commands create equivalent results.

The examples below use default values (`quma` for database name, username, and password). You can change these to your preferred values, but if you do, you must set the corresponding environment variables to make the test suite aware of your changes (see [Environment Variables](#environment-variables)).

### PostgreSQL

Using the CLI:

```bash
echo "quma" | createuser --pwprompt --createdb quma
createdb --owner quma quma
```

Using SQL:

```sql
CREATE ROLE quma WITH LOGIN PASSWORD 'quma' CREATEDB;
CREATE DATABASE quma WITH OWNER quma;
```

### MySQL/MariaDB

Using the CLI:

```bash
mysql -u root -p -e "CREATE DATABASE quma; CREATE USER 'quma'@'localhost' IDENTIFIED BY 'quma'; GRANT ALL PRIVILEGES ON quma.* TO 'quma'@'localhost';"
```

Using SQL:

```sql
CREATE DATABASE quma;
CREATE USER 'quma'@'localhost' IDENTIFIED BY 'quma';
GRANT ALL PRIVILEGES ON quma.* TO 'quma'@'localhost';
```

### Environment Variables

Override test database configuration using environment variables. By default, the test suite runs SQLite only; set `QUMA_TEST_DRIVERS` to include MySQL or PostgreSQL.

- `QUMA_TEST_DRIVERS`: Comma-separated list of drivers to test (default: `sqlite`; available: `sqlite`, `mysql`, `pgsql`)
- `QUMA_DB_SQLITE_DB_PATH_1`: Path for primary SQLite database (default: `quma_db1.sqlite3`)
- `QUMA_DB_SQLITE_DB_PATH_2`: Path for secondary SQLite database (default: `quma_db2.sqlite3`)
- `QUMA_DB_PGSQL_HOST`: PostgreSQL host (default: `localhost`)
- `QUMA_DB_MYSQL_HOST`: MySQL host (default: `127.0.0.1`)
- `QUMA_DB_NAME`: Database name (default: `quma`)
- `QUMA_DB_USER`: Database user (default: `quma`)
- `QUMA_DB_PASSWORD`: Database password (default: `quma`)

Example:

```bash
export QUMA_DB_PGSQL_HOST=192.168.1.100
export QUMA_DB_USER=testuser
export QUMA_DB_PASSWORD=testpass
export QUMA_TEST_DRIVERS=sqlite,mysql,pgsql
composer test
```

## Running Tests

```bash
composer test
composer test:sqlite
composer test:mysql
composer test:pgsql
composer test:all
```

## License

This project is licensed under the [MIT license](LICENSE.md).
