<?php

declare(strict_types=1);

namespace Duon\Quma\Tests;

use Duon\Quma\Database;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use stdClass;

/**
 * @internal
 */
class DatabaseTest extends TestCase
{
	public const NUMBER_OF_ALBUMS = 7;
	public const NUMBER_OF_MEMBERS = 17;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();
		self::createTestDb();
	}

	public function testDatabaseConnection(): void
	{
		$db = new Database($this->connection());

		$this->assertInstanceOf(PDO::class, $db->getConn());
	}

	public function testGetConnThrowsWhenConnectionWasNotInitialized(): void
	{
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Database connection not initialized');

		$db = new class ($this->connection()) extends Database {
			public function connect(): static
			{
				return $this;
			}
		};

		$db->getConn();
	}

	public function testSetWhetherItShouldPrintSqlToStdout(): void
	{
		$db = $this->getDb();

		$this->assertFalse($db->print());
		$db->print(true);
		$this->assertTrue($db->print());
	}

	public function testPdoQuote(): void
	{
		$db = $this->getDb();

		$this->assertSame(
			'\'Co\'\'mpl\'\'\'\'ex "st\'\'"ring\'',
			$db->quote("Co'mpl''ex \"st'\"ring"),
		);
	}

	public function testFetchAllQueryAll(): void
	{
		$db = $this->getDb();
		$result = $db->members->list()->all();

		$this->assertCount(self::NUMBER_OF_MEMBERS, $result);
	}

	public function testFetchLazyQueryLazy(): void
	{
		$db = $this->getDb();
		$result = $db->members->list()->lazy();

		$this->assertCount(self::NUMBER_OF_MEMBERS, iterator_to_array($result));
	}

	public function testGetRowCountQueryLen(): void
	{
		// SQLite unlike MySQL/Postgres always returns 0.
		// So this tests does not check for correct result
		// but if the code runs without errors.
		$db = $this->getDb();
		$result = $db->members->list()->len();

		$this->assertSame(0, $result);
	}

	public function testFetchOneQueryOne(): void
	{
		$db = $this->getDb();
		$result = $db->members->list()->one();

		$this->assertIsArray($result);
		$this->assertArrayHasKey('name', $result);
		$this->assertNotEmpty($result['name']);
	}

	public function testRunOnlyQueriesQueryRun(): void
	{
		$db = $this->getDb();

		$db->members->add('Tim Aymar', 1998, 2001)->run();
		$this->assertCount(self::NUMBER_OF_MEMBERS + 1, $db->members->list()->all());
		$db->members->delete(['name' => 'Tim Aymar'])->run();
		$this->assertCount(self::NUMBER_OF_MEMBERS, $db->members->list()->all());
	}

	public function testTransactionsBeginCommit(): void
	{
		$db = $this->getDb();

		$db->begin();
		$db->members->add('Tim Aymar', 1998, 2001)->run();
		$db->commit();
		$this->assertCount(self::NUMBER_OF_MEMBERS + 1, $db->members->list()->all());

		$db->members->delete(['name' => 'Tim Aymar'])->run();

		$db->begin();
		$db->members->add('Tim Aymar', 1998, 2001)->run();
		$db->rollback();
		$this->assertCount(self::NUMBER_OF_MEMBERS, $db->members->list()->all());
	}

	public function testQueryWithPositionalParameters(): void
	{
		$db = $this->getDb();
		$result = $db->members->byId(2)->one();
		$this->assertSame('Rick Rozz', $result['name']);

		// arguments can also be passed as array
		$result = $db->members->byId([4])->one();
		$this->assertSame('Terry Butler', $result['name']);
	}

	public function testQueryWithNamedParameters(): void
	{
		$db = $this->getDb();
		$result = $db->members->activeFromTo([
			'from' => 1990,
			'to' => 1995,
		])->all();

		$this->assertCount(7, $result);
	}

	public function testQueryWithStringParameters(): void
	{
		$db = $this->getDb();
		$query = $db->types->test([
			'val' => 'Death',
		]);

		$this->assertSame("SELECT * FROM typetest WHERE val = 'Death';\n", (string) $query);
	}

	public function testQueryWithBooleanParameters(): void
	{
		$db = $this->getDb();
		$query = $db->types->test([
			'val' => true,
		]);

		$this->assertSame("SELECT * FROM typetest WHERE val = true;\n", (string) $query);
	}

	public function testQueryWithNullParameters(): void
	{
		$db = $this->getDb();
		$query = $db->types->test([
			'val' => null,
		]);

		$this->assertSame("SELECT * FROM typetest WHERE val = NULL;\n", (string) $query);
	}

	public function testQueryWithArrayParameters(): void
	{
		$db = $this->getDb();
		$query = $db->types->test([
			'val' => [1, 2, 3],
		]);

		$this->assertSame("SELECT * FROM typetest WHERE val = '[1,2,3]';\n", (string) $query);
	}

	public function testQueryWithInvalidTypeParameters(): void
	{
		$this->expectException(InvalidArgumentException::class);

		$db = $this->getDb();
		$obj = new stdClass();
		$obj->name = 'Death';
		$db->types->test([
			'val' => $obj,
		]);
	}

	public function testTemplateQuery(): void
	{
		$db = $this->getDb();

		$result = $db->members->joined(['year' => 1983])->one(PDO::FETCH_ASSOC);
		$this->assertCount(4, $result);

		$result = $db->members->joined(['year' => 1983, 'interestedInNames' => true])->one(PDO::FETCH_ASSOC);
		$this->assertCount(5, $result);
	}

	public function testPdoDriverIsHandedToTemplateQuery(): void
	{
		$db = $this->getDb();
		$result = $db->members->joined(['year' => 1983])->one();

		// The PDO driver is handed to the template
		$this->assertSame('sqlite', $result['driver']);
	}

	public function testTemplateQueryWithPositionalArgs(): void
	{
		$this->expectException(InvalidArgumentException::class);

		$db = $this->getDb();

		$db->members->joined(1983);
	}

	public function testTemplateQueryWithNoSqlArgs(): void
	{
		$db = $this->getDb();

		$result = $db->members->ordered(['order' => 'asc'])->all();
		$this->assertSame('Andy LaRocque', $result[0]['name']);

		$result = $db->members->ordered(['order' => 'desc'])->all();
		$this->assertSame('Terry Butler', $result[0]['name']);
	}

	public function testExpandScriptDirsQueryFromDefault(): void
	{
		$db = new Database($this->connection(additionalDirs: true));

		$result = $db->members->list()->all();
		$this->assertCount(self::NUMBER_OF_MEMBERS, $result);
	}

	public function testScriptInstance(): void
	{
		$db = $this->getDb();

		$byId = $db->members->byId;
		$this->assertSame('Bill Andrews', $byId(5)->one()['name']);
	}

	public function testQueryPrintingNamedParameters(): void
	{
		$db = $this->getDb();
		$db->print(true);

		ob_start();
		$result = $db->members->joined([
			'year' => 1997,
			'testPrinting' => true,
			'interestedInNames' => true,
		])->one();
		$output = ob_get_contents();
		ob_end_clean();

		$this->assertSame('Shannon Hamm', $result['name']);
		$this->assertStringContainsString('Emotions :year', $output);
		$this->assertStringContainsString('mantas, -- :year', $output);
		$this->assertStringContainsString("' :year", $output);
		$this->assertStringContainsString('Secret Face :year', $output);
		$this->assertStringContainsString('joined = 1997', $output);
	}

	public function testQueryPrintingPositionalParameters(): void
	{
		$db = $this->getDb();
		$db->print(true);

		ob_start();
		$result = $db->members->left(2001)->one();
		$output = ob_get_contents();
		ob_end_clean();

		$this->assertSame('Shannon Hamm', $result['name']);
		$this->assertStringContainsString('Emotions ?', $output);
		$this->assertStringContainsString('mantas, -- ?', $output);
		$this->assertStringContainsString("' ?", $output);
		$this->assertStringContainsString('Secret Face ?', $output);
		$this->assertStringContainsString('WHERE left = 2001', $output);
	}

	public function testExpandScriptDirsQueryFromExpanded(): void
	{
		$db = new Database($this->connection(additionalDirs: true));

		$result = $db->members->byName(['name' => 'Rick Rozz'])->one();
		$this->assertSame(2, $result['member']);
	}

	public function testExpandScriptDirsQueryFromExpandedNewNamespace(): void
	{
		$db = new Database($this->connection(additionalDirs: true));

		$result = $db->albums->list()->all();
		$this->assertCount(7, $result);
	}

	public function testMultipleQueryOneCalls(): void
	{
		$db = new Database($this->connection());
		$query = $db->members->activeFromTo([
			'from' => 1990,
			'to' => 1995,
		]);

		$i = 0;
		$result = $query->one();

		while ($result) {
			$i++;
			$result = $query->one();
		}

		$this->assertSame(7, $i);
	}

	public function testDatabaseExecute(): void
	{
		$db = new Database($this->connection());
		$query = 'SELECT * FROM albums';

		$this->assertCount(7, $db->execute($query)->all());
	}

	public function testDatabaseExecuteWithArgs(): void
	{
		$db = new Database($this->connection());
		$queryQmark = 'SELECT name FROM members WHERE joined = ? AND left = ?';
		$queryNamed = 'SELECT name FROM members WHERE joined = :joined AND left = :left';

		$this->assertSame(
			'Sean Reinert',
			$db->execute($queryQmark, [1991, 1992])->one()['name'],
		);

		$this->assertSame(
			'Sean Reinert',
			$db->execute($queryQmark, 1991, 1992)->one()['name'],
		);

		$this->assertSame(
			'Sean Reinert',
			$db->execute($queryNamed, ['left' => 1992, 'joined' => 1991])->one()['name'],
		);
	}

	public function testScriptDirShadowingAndDriverSpecific(): void
	{
		$db = $this->getDb();

		// The query in the default dir uses positional parameters
		// and returns the field `left` additionally to `member` and `name`.
		$result = $db->members->byId(2)->one();
		$this->assertSame('Rick Rozz', $result['name']);
		$this->assertSame(1989, $result['left']);

		// The query in the sqlite specific dir uses named parameters
		// and additionally returns the field `joined` in contrast
		// to the default dir, which returns the field `left`.
		$db = $this->getDb(additionalDirs: true);
		// Named parameter queries also support positional arguments
		$result = $db->members->byId(3)->one();
		$this->assertSame('Chris Reifert', $result['name']);
		$this->assertSame(1986, $result['joined']);
		// Passed named args
		$result = $db->members->byId(['member' => 4])->one();
		$this->assertSame('Terry Butler', $result['name']);
		$this->assertSame(1987, $result['joined']);
	}

	public function testAccessingNonExistentNamespaceFolder(): void
	{
		$this->expectException(RuntimeException::class);

		$db = $this->getDb();
		$db->doesNotExist;
	}

	public function testAccessingNonExistentScriptQuery(): void
	{
		$this->expectException(RuntimeException::class);

		$db = $this->getDb();
		$db->members->doesNotExist;
	}
}
