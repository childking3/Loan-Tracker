<?php

use yii\db\Migration;

/**
 * Lets a password change invalidate that user's existing sessions.
 *
 * Before this, resetting a compromised account's password did NOT evict the
 * intruder: the per-request identity check only looked at id + status +
 * deleted_at, nothing derived from the credential, so an already-established
 * session kept working until the 30-minute idle timeout or the 12-hour
 * absolute cap. Verified 2026-10-19 (AUTH_SESSION_REVIEW.md F2) by changing a
 * password_hash underneath a live session and watching it carry on.
 *
 * Deliberately NULLABLE with no backfill. Sessions created before this
 * migration stored no stamp, and existing rows have none, so nothing is
 * logged out by deploying it. The first password change for a user sets a
 * stamp, which is what then invalidates that user's older sessions.
 */
class m261019_200000_add_password_changed_at_to_user extends Migration
{
    public function safeUp()
    {
        $this->addColumn(
            '{{%user}}',
            'password_changed_at',
            $this->integer()->unsigned()->null()->after('password_hash')
        );
    }

    public function safeDown()
    {
        $this->dropColumn('{{%user}}', 'password_changed_at');
    }
}
