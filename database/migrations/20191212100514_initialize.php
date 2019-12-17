<?php

use Phinx\Migration\AbstractMigration;

class Initialize extends AbstractMigration
{
    /**
     * Change Method.
     *
     * Write your reversible migrations using this method.
     *
     * More information on writing migrations is available here:
     * http://docs.phinx.org/en/latest/migrations.html#the-abstractmigration-class
     *
     * The following commands can be used in this method and Phinx will
     * automatically reverse them when rolling back:
     *
     *    createTable
     *    renameTable
     *    addColumn
     *    addCustomColumn
     *    renameColumn
     *    addIndex
     *    addForeignKey
     *
     * Any other destructive changes will result in an error when trying to
     * rollback the migration.
     *
     * Remember to call "create()" or "update()" and NOT "save()" when working
     * with the Table class.
     */
    public function up()
    {
        $this->table('cache', ['id' => false, 'primary_key' => 'key'])
             ->addColumn('key', 'string', ['limit' => 32, 'null' => false])
             ->addColumn('added', 'integer')
             ->addColumn('value', 'blob')
             ->addIndex(['added'])
             ->save();

        $this->table('categories', ['signed' => false])
             ->addColumn('parent', 'integer', ['signed' => false])
             ->addColumn('name', 'string', ['null' => false])
             ->save();

        $this->table('nodes', ['signed' => false])
            ->addColumn('parent', 'integer', ['signed' => false])
            ->addColumn('lb', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('rb', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('type', 'string', ['null' => false])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addColumn('updated', 'datetime', ['null' => false])
            ->addColumn('key', 'string')
            ->addColumn('published', 'boolean', ['null' => false, 'default' => 1])
            ->addColumn('deleted', 'boolean', ['null' => false, 'default' => 0])
            ->addColumn('more', 'blob')
            ->addIndex(['parent'])
            ->addIndex(['lb'], ['unique' => true])
            ->addIndex(['rb'], ['unique' => true])
            ->addIndex(['type'])
            ->addIndex(['created'])
            ->addIndex(['updated'])
            ->addIndex(['published'])
            ->addIndex(['deleted'])
            ->save();

        $this->table('nodes_picture_idx', ['id' => false])
             ->addColumn('id', 'integer', ['null' => false, 'signed' => false])
             ->addColumn('author', 'integer', ['null' => false, 'signed' => false])
             ->addColumn('status', 'integer', ['null' => false, 'signed' => false])
             ->addForeignKey('id', 'nodes', 'id', ['delete' => 'CASCADE'])
             ->addForeignKey('author', 'nodes', 'id', ['delete' => 'SET_NULL'])
             ->addIndex(['status'])
             ->save();

        $this->table('nodes_user_idx', ['id' => false])
             ->addColumn('id', 'integer', ['null' => false, 'signed' => false])
             ->addColumn('email', 'string')
             ->addForeignKey('id', 'nodes', 'id', ['delete' => 'CASCADE'])
             ->addIndex(['email'])
             ->save();

        $this->table('payout', ['id' => false])
             ->addColumn('user_id', 'integer', ['null' => false, 'signed' => false])
             ->addColumn('date', 'datetime', ['null' => false])
             ->addColumn('amount', 'integer', ['null' => false, 'signed' => false])
             ->addForeignKey('user_id', 'nodes', 'id', ['delete' => 'CASCADE'])
             ->addIndex(['date'])
             ->save();

        $this->table('sales', ['id' => false])
             ->addColumn('order_id', 'integer', ['null' => false, 'signed' => false])
             ->addColumn('product_id', 'integer', ['null' => false, 'signed' => false])
             ->addColumn('author_id', 'integer', ['null' => false, 'signed' => false])
             ->addColumn('date', 'datetime', ['null' => false])
             ->addColumn('qty', 'integer', ['null' => false, 'signed' => false])
             ->addColumn('price', 'integer', ['null' => false, 'signed' => false])
             ->addColumn('cancelled', 'boolean', ['null' => false, 'default' => '0'])
             ->addForeignKey('author_id', 'nodes', 'id', ['delete' => 'CASCADE'])
             ->addIndex(['order_id'])
             ->addIndex(['product_id'])
             ->addIndex(['date'])
             ->save();

        $this->table('sessions', ['id' => false])
             ->addColumn('id', 'string', ['null' => false, 'limit' => 32])
             ->addColumn('updated', 'datetime', ['null' => false])
             ->addColumn('data', 'blob')
             ->addIndex(['id'], ['unique' => true])
             ->addIndex(['updated'])
             ->save();

        $this->table('taskq')
             ->addColumn('added', 'datetime', ['null' => false])
             ->addColumn('priority', 'integer', ['null' => false, 'default' => '0'])
             ->addColumn('payload', 'blob')
             ->addIndex(['added'])
             ->addIndex(['priority'])
             ->save();
    }

    public function down()
    {
        $this->table('cache')->drop()->save();
        $this->table('categories')->drop()->save();
        $this->table('nodes')->drop()->save();
        $this->table('nodes_picture_idx')->drop()->save();
        $this->table('nodes_user_idx')->drop()->save();
        $this->table('payout')->drop()->save();
        $this->table('sales')->drop()->save();
        $this->table('sessions')->drop()->save();
        $this->table('taskq')->drop()->save();
    }
}
