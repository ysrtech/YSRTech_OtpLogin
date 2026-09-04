<?php
/**
 * YSRTech OtpLogin upgrade 1.0.0 -> 1.0.1.
 *
 * Adds the counter that caps how many wrong codes may be tried against one
 * OTP. Installs made before this ran have the column added here; a fresh
 * install gets it from install-1.0.0.php.
 *
 * @category  YSRTech
 * @package   YSRTech_OtpLogin
 *
 * @var Mage_Core_Model_Resource_Setup $this
 */

$installer = $this;
$installer->startSetup();

$table      = $installer->getTable('ysrtech_otplogin/otp');
$connection = $installer->getConnection();

if ($connection->isTableExists($table) && !$connection->tableColumnExists($table, 'attempts')) {
    $connection->addColumn($table, 'attempts', array(
        'type'     => Varien_Db_Ddl_Table::TYPE_SMALLINT,
        'unsigned' => true,
        'nullable' => false,
        'default'  => '0',
        'comment'  => 'Wrong codes entered against this OTP',
    ));
}

$installer->endSetup();
