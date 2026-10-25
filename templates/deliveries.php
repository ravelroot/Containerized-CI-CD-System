<?php
/** @var Vau\Http\View $v */
$v->share('title', 'Entregas de webhook');
?>
<header class="page__head">
  <div>
    <p class="eyebrow">Tudo que chegou em /webhook, aceito ou recusado</p>
    <h1>Entregas de webhook</h1>
  </div>
</header>
<section class="panel">
  <?= $v->partial('partials/delivery_rows', ['deliveries' => $deliveries]) ?>
</section>
