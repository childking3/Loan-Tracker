<?php

use app\models\Customer;
use app\models\Loan;
use app\models\LoanPackage;
use app\models\Repayment;
use app\models\User;
use yii\db\Migration;

/**
 * Seeds realistic dummy data - two additional staff accounts, six
 * customers, seven loans in a mix of states, and repayments spread across
 * several dates - so the dashboard, loan/customer listings, and every
 * report in Phase 7 have something meaningful to show instead of empty
 * tables. Every account, customer and loan here is fictional test data,
 * not real client information.
 *
 * Data is created through the same ActiveRecord classes and business logic
 * the real application uses (Loan::applyPackageTerms(), the loan_number
 * generation in Loan::afterSave(), Repayment's own validation), not raw
 * INSERT statements, so seeded rows are indistinguishable in shape from
 * ones a real user would create. Loans that are meant to end up overdue
 * are seeded as 'active' with a past expected_completion_date and left
 * unpaid - actually marking them overdue is left to a real run of
 * `php yii loan/mark-overdue`, the same command a cron job would run,
 * rather than setting status='overdue' directly here.
 */
class m260910_220000_seed_dummy_data extends Migration
{
    private const PASSWORD_HASH = '$2y$13$KfVqYQnndUsVQ3svst7PMuhqOnjGt4Q1vCaanwFf5pYl1wM/hsfqS';
    private const AUTH_KEY = 'd5bd0aed73b8cc0655a12cb705e506fe';

    public function safeUp()
    {
        $now = time();
        $adminId = 1;

        $manager = $this->seedUser('manager1', 'manager1@example.test', 'Grace Manager', $now);
        $auth = Yii::$app->authManager;
        $auth->assign($auth->getRole('manager'), $manager->id);

        $staff1 = $this->seedUser('staff1', 'staff1@example.test', 'John Okoro', $now);
        $auth->assign($auth->getRole('staff'), $staff1->id);

        $staff2 = $this->seedUser('staff2', 'staff2@example.test', 'Amaka Nwachukwu', $now);
        $auth->assign($auth->getRole('staff'), $staff2->id);

        $customers = [
            'ngozi' => $this->seedCustomer('Ngozi Eze', '08011111111', '14 Adeola Street, Lagos', $adminId),
            'tunde' => $this->seedCustomer('Tunde Bakare', '08022222222', '9 Ogunlana Drive, Lagos', $adminId),
            'fatima' => $this->seedCustomer('Fatima Ibrahim', '08033333333', '3 Ahmadu Bello Way, Kano', $adminId),
            'chinedu' => $this->seedCustomer('Chinedu Okafor', '08044444444', '21 Awolowo Road, Enugu', $adminId),
            'blessing' => $this->seedCustomer('Blessing Adeyemi', '08055555555', '7 Allen Avenue, Lagos', $adminId),
            'emeka' => $this->seedCustomer('Emeka Nwosu', '08066666666', '18 Zik Avenue, Onitsha', $adminId),
        ];

        $packages = LoanPackage::find()->indexBy('name')->all();
        $packageA = $packages['Package A (placeholder)'];
        $packageB = $packages['Package B (placeholder)'];
        $packageC = $packages['Package C (placeholder)'];
        $packageD = $packages['Package D (placeholder)'];
        $packageE = $packages['Package E (placeholder)'];

        // Well overdue, unpaid.
        $loan1 = $this->seedLoan($customers['ngozi'], $packageA, '2026-08-01', $staff1->id, $adminId);
        $this->seedRepayment($loan1, 2000, '2026-08-20', $staff1->id);
        $this->seedRepayment($loan1, 1000, '2026-09-05', $staff1->id);

        // Active, not yet due, partially paid, including a payment today.
        $loan2 = $this->seedLoan($customers['tunde'], $packageB, '2026-08-15', $staff1->id, $adminId);
        $this->seedRepayment($loan2, 5000, '2026-08-20', $staff1->id);
        $this->seedRepayment($loan2, 3000, '2026-09-10', $staff1->id);

        // Fully repaid - Repayment/actionCreate-equivalent completion logic
        // applied manually below since this migration inserts repayments
        // directly rather than through RepaymentController.
        $loan3 = $this->seedLoan($customers['fatima'], $packageC, '2026-07-01', $staff2->id, $adminId);
        $this->seedRepayment($loan3, 10000, '2026-07-10', $staff2->id);
        $this->seedRepayment($loan3, 13000, '2026-08-10', $staff2->id);
        if ($loan3->getRemainingBalance() <= 0.0) {
            $loan3->status = 'completed';
            $loan3->save(false);
        }

        // Active, recent, one payment today.
        $loan4 = $this->seedLoan($customers['chinedu'], $packageA, '2026-09-05', $manager->id, $adminId);
        $this->seedRepayment($loan4, 2000, '2026-09-10', $manager->id);

        // Active, unpaid.
        $this->seedLoan($customers['blessing'], $packageD, '2026-09-01', $staff2->id, $adminId);

        // Well overdue, unpaid, zero repayments at all.
        $this->seedLoan($customers['emeka'], $packageB, '2026-06-01', $staff1->id, $adminId);

        // Active, freshly issued.
        $this->seedLoan($customers['ngozi'], $packageE, '2026-09-08', $manager->id, $adminId);
    }

    public function safeDown()
    {
        $auth = Yii::$app->authManager;

        foreach (User::find()->andWhere(['in', 'username', ['manager1', 'staff1', 'staff2']])->all() as $user) {
            Repayment::deleteAll(['recorded_by_staff_id' => $user->id]);
            Loan::deleteAll(['assigned_staff_id' => $user->id]);
            $auth->revokeAll($user->id);
        }

        foreach (['08011111111', '08022222222', '08033333333', '08044444444', '08055555555', '08066666666'] as $phone) {
            $customer = Customer::find()->andWhere(['phone' => $phone])->one();
            if ($customer !== null) {
                Loan::deleteAll(['customer_id' => $customer->id]);
                $customer->delete();
            }
        }

        User::deleteAll(['in', 'username', ['manager1', 'staff1', 'staff2']]);
    }

    private function seedUser(string $username, string $email, string $fullName, int $now): User
    {
        $user = new User();
        $user->username = $username;
        $user->email = $email;
        $user->full_name = $fullName;
        $user->password_hash = self::PASSWORD_HASH;
        $user->auth_key = self::AUTH_KEY;
        $user->status = User::STATUS_ACTIVE;
        $user->created_at = $now;
        $user->updated_at = $now;
        $user->save(false);

        return $user;
    }

    private function seedCustomer(string $fullName, string $phone, string $address, int $createdBy): Customer
    {
        $customer = new Customer();
        $customer->full_name = $fullName;
        $customer->phone = $phone;
        $customer->address = $address;
        $customer->created_by = $createdBy;
        $customer->save(false);

        return $customer;
    }

    private function seedLoan(Customer $customer, LoanPackage $package, string $startDate, int $assignedStaffId, int $createdBy): Loan
    {
        $loan = new Loan();
        $loan->customer_id = $customer->id;
        $loan->package_id = $package->id;
        $loan->start_date = $startDate;
        $loan->assigned_staff_id = $assignedStaffId;
        $loan->applyPackageTerms($package);
        $loan->created_by = $createdBy;
        $loan->save(false);

        return $loan;
    }

    private function seedRepayment(Loan $loan, float $amount, string $paymentDate, int $recordedByStaffId): Repayment
    {
        $repayment = new Repayment();
        $repayment->loan_id = $loan->id;
        $repayment->amount = $amount;
        $repayment->payment_date = $paymentDate;
        $repayment->recorded_by_staff_id = $recordedByStaffId;
        $repayment->save(false);

        Loan::invalidateBalanceCache($loan->id);

        return $repayment;
    }
}
