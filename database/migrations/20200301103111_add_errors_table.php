<?php

/**
 * Add the errors table to store failures.
 **/

use Phinx\Migration\AbstractMigration;

class AddErrorsTable extends AbstractMigration
{
    public function change()
    {
        $this->table('errors')
            ->addColumn('date', 'datetime', ['null' => false])
            ->addColumn('class', 'string', ['null' => false])
            ->addColumn('message', 'string')
            ->addColumn('file', 'string')
            ->addColumn('line', 'integer')
            ->addColumn('stack', 'text')
            ->addColumn('headers', 'blob')
            ->addColumn('read', 'boolean', ['null' => false, 'signed' => false])
            ->addIndex(['date'])
            ->addIndex(['file'])
            ->addIndex(['read'])
            ->save();
    }
}
