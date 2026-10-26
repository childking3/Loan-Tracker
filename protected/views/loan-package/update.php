<?php

/** @var \yii\web\View $this */
/** @var \app\models\LoanPackage $model */

$this->title = 'Edit ' . $model->name;
?>
<h1>Edit loan package</h1>

<?= $this->render('_form', ['model' => $model]) ?>
