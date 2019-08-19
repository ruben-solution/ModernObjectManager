<?php

require_once __DIR__ . '/SysDatabaseMgr.class.php';
require_once __DIR__ . '/ObjectManager.php';

$DbMgr = new SysDatabaseMgr('root', 'pswd1234', 'classicmodels', 'localhost');

/*
$obj = (new ObjManager($DbMgr))->get(
    'offices',
    ['officeCode' => 5]
);

echo $obj->attr('phone');
($obj->change('phone', '+81 33 224 5000'))->save();

$obj = (new ObjManager($DbMgr))->get(
    'offices',
    ['officeCode' => 5]
);

echo $obj->attr('phone');
*/

$new_row = new stdClass();
$new_row->officeCode   = 8;
$new_row->city         = 'Bern';
$new_row->phone        = 'some number';
$new_row->addressLine1 = 'some line';
$new_row->addressLine2 = 'some line 2';
$new_row->state        = 'thurgau';
$new_row->country      = 'schweiz';
$new_row->postalCode   = '8586';
$new_row->territory    = 'district 9';

(new ObjManager($DbMgr))
    ->create('offices', $new_row)
    ->save();
