<?php

namespace app\controllers;

use app\components\AuditLogger;
use app\components\DashboardCache;
use app\models\Loan;
use app\models\Repayment;
use Yii;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;

/**
 * Recording repayments against a loan. There is no repayment index/view
 * page of its own - repayment history is shown inline on the loan's own
 * view page (see LoanController::actionView and views/loan/view.php),
 * since a repayment only makes sense in the context of its loan.
 */
class RepaymentController extends Controller
{
    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [
                    // 'update' was deliberately gated stricter than
                    // 'create' until 2026-10-16: recording a routine
                    // payment was staff-level (manageRepayments) while
                    // correcting one already on the books needed
                    // manageLoans (manager/admin). The client reversed
                    // that - staff collect the money, so staff fix their
                    // own mistakes - and both are now manageRepayments.
                    // What still separates a staff member from a
                    // colleague's repayments is the row-level check in
                    // actionUpdate(): $loan->isVisibleToCurrentUser(),
                    // i.e. the loan must be assigned to them. Every edit
                    // is written to the audit log either way
                    // (repayment_update, with old and new values).
                    // Kept as its own rule with explicit 'actions' so
                    // ordering can't accidentally widen anything if the
                    // two ever diverge again.
                    [
                        'allow' => true,
                        'actions' => ['update'],
                        'roles' => ['manageRepayments'],
                    ],
                    [
                        'allow' => true,
                        'actions' => ['create'],
                        'roles' => ['manageRepayments'],
                    ],
                ],
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'create' => ['post'],
                    'update' => ['get', 'post'],
                ],
            ],
        ];
    }

    /**
     * Staff may only record repayments on loans assigned to them; manager
     * and admin (viewAllLoans) may record against any loan - exercises
     * the same IsAssignedStaffRule the loan module uses, since the brief
     * scopes staff's whole permission set to their own assigned loans.
     */
    public function actionCreate($loanId)
    {
        $loan = Loan::findOne($loanId);
        if ($loan === null) {
            throw new NotFoundHttpException('Loan not found.');
        }

        if (!$loan->isVisibleToCurrentUser()) {
            throw new ForbiddenHttpException('You are not allowed to record repayments on this loan.');
        }

        // 'overdue' included alongside 'active' (see Loan::isOpenForActivity()):
        // without it, an overdue loan could never receive another repayment
        // (nothing else ever moves a loan back out of 'overdue'), a
        // permanent dead end. The pay-off logic below doesn't care what
        // the prior status was, so widening this check is sufficient on
        // its own.
        if (!$loan->isOpenForActivity()) {
            Yii::$app->session->setFlash('error', 'Repayments can only be recorded on active or overdue loans.');
            return $this->redirect(['loan/view', 'id' => $loan->id]);
        }

        $model = new Repayment();
        $model->loan_id = $loan->id;

        if ($model->load(Yii::$app->request->post()) && $model->validate()) {
            // Never trust a posted loan_id: this action is always scoped
            // to the loan named in the route.
            $model->loan_id = $loan->id;
            $model->recorded_by_staff_id = Yii::$app->user->id;

            // A payment can't predate the loan itself - Repayment::rules()'s
            // future-date rule only bounds the upper side, so this was
            // previously unrestricted, letting a phantom collection skew
            // date-range reports and getRemainingBalance(). Checked
            // against the controller's own route-resolved $loan rather
            // than a model rule re-deriving it from $this->loan_id, since
            // that attribute is still mass-assignable at validate() time
            // and could be tricked into checking a different,
            // attacker-chosen loan.
            if ($model->payment_date < $loan->start_date) {
                Yii::$app->session->setFlash('error', "Payment date cannot be before this loan's start date ({$loan->start_date}).");
                return $this->redirect(['loan/view', 'id' => $loan->id]);
            }

            // Repayments are append-only with no idempotency key, so a
            // double-click or retry is indistinguishable from two real
            // payments. Guarded with a row lock on the loan (SELECT ...
            // FOR UPDATE) so concurrent requests serialize, plus a check
            // for a matching loan/amount/date/staff repayment in the last
            // few seconds - a short window, not permanent, since two
            // genuine same-day payments (e.g. installments) must not be
            // blocked.
            //
            // Flagged by the 2026-09-17 duplicate-function scan as the
            // same shape repeated 3x - here, LoanController::actionCreate()
            // and GuarantorController::actionCreate() (row lock on a
            // parent row, then a short-window exists() check on a
            // per-entity field set, then save+audit inside one
            // transaction). Deliberately left unconsolidated for now: the
            // lock table (customer vs loan), the exists() field set (loan+
            // package+staff vs loan+amount+date+staff vs loan+name+phone)
            // and the flash-message copy all differ per entity, and this
            // is concurrency-safety code - a shared
            // runGuardedAgainstDuplicate(lockTable, lockId, duplicateQuery,
            // save) helper is the obvious shape but getting the lock/
            // rollback semantics exactly right for all three callers needs
            // its own careful pass rather than folding into a broader scan.
            $duplicate = false;
            $transaction = Yii::$app->db->beginTransaction();
            try {
                Yii::$app->db->createCommand(
                    'SELECT id FROM {{%loan}} WHERE id = :id FOR UPDATE',
                    [':id' => $loan->id]
                )->queryScalar();

                $duplicate = Repayment::find()
                    ->where([
                        'loan_id' => $loan->id,
                        'amount' => $model->amount,
                        'payment_date' => $model->payment_date,
                        'recorded_by_staff_id' => $model->recorded_by_staff_id,
                    ])
                    ->andWhere(['>=', 'created_at', time() - 10])
                    ->exists();

                if (!$duplicate) {
                    $model->save(false);
                    AuditLogger::audit('repayment_create', 'repayment', $model->id, null, $model->getAttributes());
                }

                $transaction->commit();
            } catch (\Throwable $e) {
                $transaction->rollBack();
                throw $e;
            }

            if ($duplicate) {
                Yii::$app->session->setFlash('error', 'An identical repayment was just recorded for this loan - if this is a genuine second payment, wait a few seconds and try again.');
                return $this->redirect(['loan/view', 'id' => $loan->id]);
            }

            Loan::invalidateBalanceCache($loan->id);
            DashboardCache::bumpVersion();
            $remaining = $loan->getRemainingBalance();

            // syncStatusFromBalance() also covers "already overdue, still owes
            // money" (a no-op here, since a new repayment only ever narrows the
            // balance) - only the "just paid off" transition can actually fire
            // from this call site.
            $statusBefore = $loan->status;
            if ($loan->syncStatusFromBalance($remaining)) {
                $loan->save(false, ['status']);
                AuditLogger::audit('loan_status_change', 'loan', $loan->id, ['status' => $statusBefore], ['status' => $loan->status]);
            }

            $message = 'Repayment recorded.';
            if ($remaining < 0) {
                $message .= sprintf(' Note: this overpays the loan by %s.', number_format(abs($remaining), 2));
            }
            Yii::$app->session->setFlash('success', $message);
        } else {
            Yii::$app->session->setFlash('error', implode(' ', $model->getFirstErrors()) ?: 'Could not record repayment.');
        }

        return $this->redirect(['loan/view', 'id' => $loan->id]);
    }

    /**
     * Corrects a mistaken repayment's amount or date. Gated by manageLoans,
     * not manageRepayments (which staff hold to record one) - the same
     * "credit-control decision, distinct from staff's routine repayment
     * work" reasoning m260910_210000_assign_manage_loans_to_manager.php
     * already applied to loan editing applies at least as strongly to
     * editing money already on the books. See behaviors() for the rule.
     *
     * Unlike LoanController::actionUpdate(), this is NOT restricted to
     * open loans - correcting the repayment that made a loan look
     * "completed" too early is exactly the case this exists for. A
     * cancelled loan's status is still left alone either way, by
     * Loan::syncStatusFromBalance().
     */
    public function actionUpdate($id)
    {
        $model = Repayment::findOne($id);
        if ($model === null) {
            throw new NotFoundHttpException('Repayment not found.');
        }

        $loan = $model->loan;
        if ($loan === null || !$loan->isVisibleToCurrentUser()) {
            throw new ForbiddenHttpException('You are not allowed to edit this repayment.');
        }

        $oldAttributes = $model->getAttributes();

        if ($model->load(Yii::$app->request->post())) {
            // Never trust a posted loan_id: this action is always scoped
            // to the repayment named in the route.
            $model->loan_id = $oldAttributes['loan_id'];

            // NOT a plain string compare like LoanController::actionUpdate's
            // equivalent check: amount is currency, and the posted value
            // ("3000") and the DB's DECIMAL-formatted one ("3000.00") are
            // the same number in different string forms - a raw string
            // compare called that a "change" and wrote a spurious audit
            // row (caught testing this locally before touching anything
            // else). number_format() to 2dp normalizes both sides first.
            $sameAmount = number_format((float) $model->amount, 2, '.', '') === number_format((float) $oldAttributes['amount'], 2, '.', '');
            if ($sameAmount && $model->payment_date === $oldAttributes['payment_date']) {
                Yii::$app->session->setFlash('success', 'No changes to save.');
                return $this->redirect(['loan/view', 'id' => $loan->id]);
            }

            if ($model->validate(['amount', 'payment_date'])) {
                // Same guard as actionCreate(): a payment can't predate the
                // loan itself.
                if ($model->payment_date < $loan->start_date) {
                    $model->addError('payment_date', "Payment date cannot be before this loan's start date ({$loan->start_date}).");
                }
            }

            if (!$model->hasErrors()) {
                $transaction = Yii::$app->db->beginTransaction();
                try {
                    // Lock the loan row for the same reason every other
                    // money-mutating action here does: serialize against a
                    // concurrent repayment/edit on the same loan so the
                    // balance read below is never stale.
                    Yii::$app->db->createCommand(
                        'SELECT id FROM {{%loan}} WHERE id = :id FOR UPDATE',
                        [':id' => $loan->id]
                    )->queryScalar();

                    // Re-check under the lock: $loan->start_date was read
                    // before the lock, so a concurrent LoanController::
                    // actionUpdate() moving the loan's start date in
                    // between could otherwise slip past the check above -
                    // same shape as that action's own re-check of
                    // $earliestPayment under its lock.
                    $loan->refresh();
                    if ($model->payment_date < $loan->start_date) {
                        $model->addError('payment_date', "Payment date cannot be before this loan's start date ({$loan->start_date}).");
                    }

                    if ($model->hasErrors()) {
                        $transaction->rollBack();
                    } else {
                        $model->save(false, ['amount', 'payment_date']);
                        AuditLogger::audit('repayment_update', 'repayment', $model->id, $oldAttributes, $model->getAttributes());

                        $remaining = Loan::remainingBalancesFor([$loan])[(int) $loan->id];

                        $statusBefore = $loan->status;
                        if ($loan->syncStatusFromBalance($remaining)) {
                            $loan->save(false, ['status']);
                            AuditLogger::audit('loan_status_change', 'loan', $loan->id, ['status' => $statusBefore], ['status' => $loan->status]);
                        }

                        $transaction->commit();
                    }
                } catch (\Throwable $e) {
                    if ($transaction->isActive) {
                        $transaction->rollBack();
                    }
                    throw $e;
                }
            }

            if (!$model->hasErrors()) {
                // Outside the transaction, only reached on a real commit -
                // matches actionCreate()'s and LoanController::actionUpdate()'s
                // own placement, so a rolled-back edit can never invalidate a
                // cache entry that still correctly describes the unchanged row.
                Loan::invalidateBalanceCache($loan->id);
                DashboardCache::bumpVersion();
                Yii::$app->session->setFlash('success', 'Repayment updated.');
                return $this->redirect(['loan/view', 'id' => $loan->id]);
            }
        }

        return $this->render('update', ['model' => $model, 'loan' => $loan]);
    }
}
