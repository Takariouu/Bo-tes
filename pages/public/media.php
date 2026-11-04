<?php

declare(strict_types=1);

use Bootes\Src\MediaManager;

// Image téléversée (icône, photo de profil, bannière, galerie), servie depuis le stockage privé.
MediaManager::send($_GET['f'] ?? null, ($_GET['t'] ?? '') === 'mini');
