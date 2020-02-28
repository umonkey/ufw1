<?php

use Phinx\Migration\AbstractMigration;

class AddRewriteTable extends AbstractMigration
{
    public function change()
    {
        $this->table('rewrite', ['id' => false, 'primary_key' => 'src'])
             ->addColumn('src', 'string')
             ->addColumn('dst', 'string')
             ->save();
    }
}
