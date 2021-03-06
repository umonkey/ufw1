<?php

use Phinx\Migration\AbstractMigration;

class AddRewriteTable extends AbstractMigration
{
    public function change()
    {
        $this->table('rewrite')
             ->addColumn('src', 'string')
             ->addColumn('dst', 'string')
             ->save();
    }
}
