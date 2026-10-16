<?php

declare(strict_types=1);

$flashQueue = $_SESSION['flash_notifications'] ?? [];
unset($_SESSION['flash_notifications']);
?>
    </div><!-- Fin de .app-shell -->

    <footer class="site-footer">
        <p class="brand-title" style="font-size: 0.75rem; margin-bottom: 0.4rem;">BOÖTES</p>
        <p>Transformer le vide en lieu de vie — Propulsé par Linux Mint &amp; Fabric.</p>
    </footer>

    <script>
        window.BOOTES_FLASH_QUEUE = <?= json_encode($flashQueue, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    </script>
    <script src="/assets/js/Notification.js"></script>
</body>
</html>