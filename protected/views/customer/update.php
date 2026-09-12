<?php

/** @var \yii\web\View $this */
/** @var \app\models\Customer $model */

$this->title = 'Edit ' . $model->full_name;
?>
<h1>Edit customer</h1>

<?= $this->render('_form', ['model' => $model]) ?>
