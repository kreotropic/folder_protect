<?php
script('folder_protection', 'admin');
style('folder_protection', 'admin');
?>

<div id="folder-protection-admin" class="section">
    <h2><?php p($l->t('Folder Protection')); ?></h2>
    <p class="settings-hint">
        <?php p($l->t('Protect folders from being moved, copied, or deleted.')); ?>
    </p>

    <div id="folder-protection-app">
        <!-- Vue.js app será montado aqui -->
    </div>
</div>
