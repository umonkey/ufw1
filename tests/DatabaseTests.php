<?php

/**
 * Test database service methods.
 **/

declare(strict_types=1);

use Ufw1\Services\Database;

class DatabaseTests extends \Ufw1\Tests\Base
{
    public function testLazyConnection()
    {
        $db = new Database([
            'foo' => 'bar',
        ]);

        $this->assertTrue(true);
    }

    public function testTransactions()
    {
        $db = new Database([
            'name' => 'sqlite::memory:',
        ]);

        $db->query('CREATE TABLE test (value int)');

        $count = $db->fetchcell('SELECT COUNT(1) FROM test');
        $this->assertEquals(0, $count, 'test table not empty');

        $db->insert('test', [
            'value' => 1,
        ]);

        $count = $db->fetchcell('SELECT COUNT(1) FROM test');
        $this->assertEquals(1, $count, 'test table not initialized');

        $db->beginTransaction();
        $db->query('DELETE FROM test');

        $count = $db->fetchcell('SELECT COUNT(1) FROM test');
        $this->assertEquals(0, $count, 'DELETE not working');

        $db->rollback();

        $count = $db->fetchcell('SELECT COUNT(1) FROM test');
        $this->assertEquals(1, $count, 'rollback not working');

        $db->beginTransaction();
        $db->query('DELETE FROM test');
        $db->commit();

        $count = $db->fetchcell('SELECT COUNT(1) FROM test');
        $this->assertEquals(0, $count, 'commit not working');
    }

    public function testTransact()
    {
        $db = new Database([
            'name' => 'sqlite::memory:',
        ]);

        $db->query('CREATE TABLE test (value int)');

        $count = $db->fetchcell('SELECT COUNT(1) FROM test');
        $this->assertEquals(0, $count, 'table not empty');

        $db->transact(function ($db) {
            $db->insert('test', [
                'value' => 1,
            ]);
        });

        $count = $db->fetchcell('SELECT COUNT(1) FROM test');
        $this->assertEquals(1, $count, 'table not filled');

        try {
            $db->transact(function ($db) {
                $db->insert('test', [
                    'value' => 1,
                ]);

                // Now we have 2 records.

                throw new \RuntimeException('test');
            });
        } catch (\RuntimeException $e) {
            $count = $db->fetchcell('SELECT COUNT(1) FROM test');
            $this->assertEquals(1, $count, 'rollback on exception not working');
        }
    }

    public function testFetch()
    {
        $db = new Database([
            'name' => 'sqlite::memory:',
        ]);

        $db->query('CREATE TABLE test (value int)');

        $db->insert('test', [
            'value' => 1,
        ]);

        $db->insert('test', [
            'value' => 2,
        ]);

        $rows = $db->fetch('SELECT * FROM test');

        $this->assertEquals(2, count($rows), 'wrong row number selected');
        $this->assertEquals(1, $rows[0]['value'], 'wrong row value');
        $this->assertEquals(2, $rows[1]['value'], 'wrong row value');
    }

    public function testFetchKV()
    {
        $db = new Database([
            'name' => 'sqlite::memory:',
        ]);

        $db->query('CREATE TABLE test (k text, v text)');

        $db->insert('test', [
            'k' => 'foo',
            'v' => 'bar',
        ]);

        $rows = $db->fetchkv('SELECT k, v FROM test');

        $this->assertEquals(1, count($rows), 'wrong row number selected');
        $this->assertEquals(['foo' => 'bar'], $rows, 'wrong row value');
    }

    public function testFetchOne()
    {
        $db = new Database([
            'name' => 'sqlite::memory:',
        ]);

        $db->query('CREATE TABLE test (value text)');

        $db->insert('test', [
            'value' => 'foo',
        ]);

        $db->insert('test', [
            'value' => 'bar',
        ]);

        $rows = $db->fetchOne('SELECT * FROM test');

        $this->assertEquals(1, count($rows), 'wrong row number selected');
        $this->assertEquals(['value' => 'foo'], $rows, 'wrong row value');
    }

    public function testFetchCell()
    {
        $db = new Database([
            'name' => 'sqlite::memory:',
        ]);

        $db->query('CREATE TABLE test (value text)');

        $db->insert('test', [
            'value' => 'foo',
        ]);

        $res = $db->fetchCell('SELECT * FROM test');

        $this->assertEquals('foo', $res, 'wrong row value');
    }

    public function testQuery()
    {
        $db = new Database([
            'name' => 'sqlite::memory:',
        ]);

        $db->query('CREATE TABLE test (value text)');

        $db->insert('test', [
            'value' => 'foo',
        ]);

        $sel = $db->query('SELECT * FROM test WHERE value = ?', ['foo']);

        while ($row = $sel->fetch(\PDO::FETCH_ASSOC)) {
            $this->assertEquals(['value' => 'foo'], $row, 'wrong row value');
        }
    }

    public function testPrepare()
    {
        $db = new Database([
            'name' => 'sqlite::memory:',
        ]);

        $sel = $db->prepare('SELECT 1');

        $this->assertEquals(false, empty($sel), 'error preparing a valid statement');

        try {
            $sel = $db->prepare('to die');
            $this->assertFalse('must never succeed');
        } catch (\PDOException $e) {
            $this->assertEquals('HY000', $e->getCode(), 'wrong SQL state');
            $this->assertEquals('SQLSTATE[HY000]: General error: 1 near "to": syntax error', $e->getMessage(), 'wrong error message');
        }
    }

    public function testConnectionType()
    {
        $db = new Database([
            'name' => 'sqlite::memory:',
        ]);

        $res = $db->getConnectionType();
        $this->assertEquals('sqlite', $res, 'wrong connection type');
    }

    public function testUpdate()
    {
        $db = new Database([
            'name' => 'sqlite::memory:',
        ]);

        $db->query('CREATE TABLE test (value text)');

        $db->insert('test', ['value' => 'foo']);
        $db->insert('test', ['value' => 'bar']);

        $count = $db->update('test', [
            'value' => 'boo',
        ], [
            'value' => 'foo',
        ]);

        $this->assertSame(1, $count, 'wrong number of updated rows');

        $count = $db->update('test', [
            'value' => 'boo',
        ], []);

        $this->assertSame(2, $count, 'wrong number of updated rows');
    }

    public function testStats()
    {
        $db = new Database([
            'name' => 'sqlite::memory:',
        ]);

        $stats = $db->getStats();
        $this->assertSame([], $stats, 'non-empty stats on empty database');

        $db->query('CREATE TABLE test (value text)');
        $db->insert('test', ['value' => 'foo']);

        $stats = $db->getStats();
        $this->assertEquals(1, count($stats), 'wrong number of tables in the stats report');
        $this->assertSame([0 => ['name' => 'test', 'row_count' => 1]], $stats, 'wrong stats report');
    }
}
