<?php

declare(strict_types=1);

$brandHref = $href ?? base_url('dashboard');
$brandLabel = $label ?? 'Mantenimiento';
$brandLogoUrl = base_url('assets/brand/vogel-consultoria.png');
?>
<a class="navbar-brand d-inline-flex align-items-center gap-2" href="<?= esc($brandHref, 'attr') ?>">
    <span
        role="img"
        aria-label="Vogel Consultoría"
        style="display:inline-block;width:52px;height:32px;flex:none;border-radius:2px;background-color:#031a3e;background-image:url('<?= esc($brandLogoUrl, 'attr') ?>');background-repeat:no-repeat;background-size:146% auto;background-position:center 47.5%"
    ></span>
    <span><?= esc($brandLabel) ?></span>
</a>
