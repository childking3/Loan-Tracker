<?php

/** @var \yii\web\View $this */

use yii\helpers\Html;

$this->title = 'Reports';

/**
 * Staff Performance (report/staff, ReportController::actionStaff()) was
 * previously unreachable from anywhere in the app - a real, working
 * report with no link to it. Found while matching this page to the
 * mockup, which lists it as one of the 7 tiles; added it here rather than
 * leaving the gap the mockup happened to surface.
 */
$reports = [
    ['route' => 'report/customers', 'label' => 'Customers', 'icon' => '<circle cx="9" cy="8" r="3.5"/><path d="M2 20c0-3.5 3-6 7-6s7 2.5 7 6"/><circle cx="18" cy="9" r="2.5"/><path d="M16.5 20c0-2 1-3.5 2.5-4.2"/>'],
    ['route' => 'report/loans', 'label' => 'Loans', 'icon' => '<path d="M6 2h9l5 5v15H6z"/><path d="M15 2v5h5"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="15" y2="17"/>'],
    ['route' => 'report/repayments', 'label' => 'Repayments', 'icon' => '<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="3"/>'],
    ['route' => 'report/outstanding', 'label' => 'Outstanding Balances', 'icon' => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>'],
    ['route' => 'report/overdue', 'label' => 'Overdue Loans', 'icon' => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>'],
    ['route' => 'report/daily-collections', 'label' => 'Daily Collections', 'icon' => '<line x1="12" y1="20" x2="12" y2="10"/><line x1="18" y1="20" x2="18" y2="4"/><line x1="6" y1="20" x2="6" y2="16"/>'],
    ['route' => 'report/staff', 'label' => 'Staff Performance', 'icon' => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-7 8-7s8 3 8 7"/>'],
];
?>
<h1>Reports</h1>

<div class="quick-actions-grid" style="grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));">
    <?php foreach ($reports as $report): ?>
        <?= Html::a('<span class="quick-action-icon"><svg class="icon" viewBox="0 0 24 24">' . $report['icon'] . '</svg></span>' . Html::encode($report['label']), [$report['route']], ['class' => 'quick-action']) ?>
    <?php endforeach; ?>
</div>
