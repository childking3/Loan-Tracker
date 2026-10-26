<?php

use yii\db\Migration;

/**
 * Adds two optional columns to customer, plus an index for date ordering.
 *
 * gender is a plain nullable VARCHAR (values validated in Customer::rules(),
 * not a DB ENUM) so adding an option later needs no schema change. NULL
 * means "not recorded" - every customer that already exists keeps NULL until
 * someone edits them, nothing is guessed.
 *
 * photo_filename mirrors user.avatar_filename: only the random stored
 * filename is kept here, the bytes live under protected/uploads/customers/
 * (outside the public docroot) and are streamed by CustomerController::
 * actionPhoto(). NULL means no photo, which is the normal case - the upload
 * is optional everywhere.
 *
 * idx-customer-created_at backs the customer list's default "newest first"
 * ordering and its registration-date range filter.
 */
class m260926_010000_add_gender_and_photo_to_customer extends Migration
{
    public function safeUp()
    {
        $this->addColumn('{{%customer}}', 'gender', $this->string(10)->null()->after('address'));
        $this->addColumn('{{%customer}}', 'photo_filename', $this->string(64)->null()->after('gender'));
        $this->createIndex('idx-customer-created_at', '{{%customer}}', 'created_at');
    }

    public function safeDown()
    {
        $this->dropIndex('idx-customer-created_at', '{{%customer}}');
        $this->dropColumn('{{%customer}}', 'photo_filename');
        $this->dropColumn('{{%customer}}', 'gender');
    }
}
