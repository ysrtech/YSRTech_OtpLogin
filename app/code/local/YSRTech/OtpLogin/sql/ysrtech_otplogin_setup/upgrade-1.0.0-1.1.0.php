<?php
/**
 * YSRTech OtpLogin 1.1.0: wrong-guess and request limits.
 *
 * - attempts: wrong guesses made against a code; at the limit the code is burned.
 * - ip: where the code was requested from, for the per-IP request limit.
 * Indexes serve the "codes requested in the last N minutes" counts.
 *
 * @category  YSRTech
 * @package   YSRTech_OtpLogin
 *
 * @var Mage_Core_Model_Resource_Setup $this
 */

$installer = $this;
$installer->startSetup();

$conn  = $installer->getConnection();
$table = $installer->getTable('ysrtech_otplogin/otp');

if (!$conn->tableColumnExists($table, 'attempts')) {
    $conn->addColumn($table, 'attempts', array(
        'type'     => Varien_Db_Ddl_Table::TYPE_SMALLINT,
        'unsigned' => true,
        'nullable' => false,
        'default'  => '0',
        'comment'  => 'Wrong guesses against this code',
    ));
}
if (!$conn->tableColumnExists($table, 'ip')) {
    $conn->addColumn($table, 'ip', array(
        'type'     => Varien_Db_Ddl_Table::TYPE_TEXT,
        'length'   => 45,
        'nullable' => true,
        'comment'  => 'IP address the code was requested from',
    ));
}
$conn->addIndex($table, $installer->getIdxName('ysrtech_otplogin/otp', array('email', 'created_at')), array('email', 'created_at'));
$conn->addIndex($table, $installer->getIdxName('ysrtech_otplogin/otp', array('ip', 'created_at')), array('ip', 'created_at'));

$installer->endSetup();
