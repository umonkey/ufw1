<?php

use Phinx\Migration\AbstractMigration;

class AddSearchTable extends AbstractMigration
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
        if ($this->isSqlite()) {
            $this->execute("CREATE VIRTUAL TABLE IF NOT EXISTS `search` USING fts5 (key UNINDEXED, meta UNINDEXED, title, body)");
        } elseif ($this->IsMysql()) {
            $this->table('search', ['id' => false, 'primary_key' => 'key'])
                 ->addColumn('key', 'string')
                 ->addColumn('meta', 'blob')
                 ->addColumn('title', 'text')
                 ->addColumn('body', 'text')
                 ->addIndex('title', ['type' => 'fulltext'])
                 ->addIndex('body', ['type' => 'fulltext'])
                 ->save();
        }

        $this->table('search_log')
             ->addColumn('date', 'datetime')
             ->addColumn('query', 'string')
             ->addColumn('results', 'integer')
             ->addIndex('date')
             ->addIndex('results')
             ->save();
    }

    public function down()
    {
        $this->execute("DROP TABLE `search`");
        $this->execute("DROP TABLE `search_log`");
    }

    private function isSqlite()
    {
        return $this->getAdapter()->getAdapterType() == 'sqlite';
    }

    private function isMysql()
    {
        return $this->getAdapter()->getAdapterType() == 'mysql';
    }
}
