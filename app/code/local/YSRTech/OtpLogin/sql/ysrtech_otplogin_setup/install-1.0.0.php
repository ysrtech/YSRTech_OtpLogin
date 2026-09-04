<?php
/**
 * YSRTech OtpLogin install script.
 *
 * Creates the table that stores generated email OTP codes.
 *
 * @category  YSRTech
 * @package   YSRTech_OtpLogin
 *
 * @var Mage_Core_Model_Resource_Setup $this
 */

$installer = $this;
$installer->startSetup();

$table = $installer->getConnection()
    ->newTable($installer->getTable('ysrtech_otplogin/otp'))
    ->addColumn('entity_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, array(
        'identity' => true,
        'unsigned' => true,
        'nullable' => false,
        'primary'  => true,
    ), 'Entity Id')
    ->addColumn('email', Varien_Db_Ddl_Table::TYPE_TEXT, 255, array(
        'nullable' => false,
    ), 'Customer Email')
    ->addColumn('name', Varien_Db_Ddl_Table::TYPE_TEXT, 255, array(
        'nullable' => true,
    ), 'Name')
    ->addColumn('otp', Varien_Db_Ddl_Table::TYPE_TEXT, 255, array(
        'nullable' => false,
    ), 'Otp Hash')
    ->addColumn('status', Varien_Db_Ddl_Table::TYPE_SMALLINT, null, array(
        'nullable' => false,
        'default'  => '1',
    ), 'Status (1 = active, 0 = used/expired)')
    ->addColumn('created_at', Varien_Db_Ddl_Table::TYPE_TIMESTAMP, null, array(
        'nullable' => false,
        'default'  => Varien_Db_Ddl_Table::TIMESTAMP_INIT,
    ), 'Created At')
    ->addIndex(
        $installer->getIdxName('ysrtech_otplogin/otp', array('email')),
        array('email')
    )
    ->addIndex(
        $installer->getIdxName('ysrtech_otplogin/otp', array('status')),
        array('status')
    )
    ->setComment('YSRTech Email OTP Login Table');

$installer->getConnection()->createTable($table);

$installer->endSetup();
