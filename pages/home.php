<?php

declare(strict_types=1);

$publicServers  = [];
$publicModPacks = [];

try {
    $db = Database::getInstance();

    // Récupération des 4 derniers serveurs créés pour la vitrine publique
    $srvStmt = $db->query(
        'SELECT s.SVR_NOM, s.SVR_JOUEURS_MAX, s.SVR_RAM_ALLOUEE_MO, v.VER_LIBELLE, e.ETA_LIBELLE, s.ETA_ID
         FROM SERVEUR s
         LEFT JOIN VERSION v ON s.VER_ID = v.VER_ID
         LEFT JOIN ETAT e ON s.ETA_ID = e.ETA_ID
         ORDER BY s.SVR_ID DESC
         LIMIT 4'
    );
    $publicServers = $srvStmt->fetchAll();

    // Récupération des 4 ModPacks publics les plus téléchargés
    $mopaStmt = $db->query(
        'SELECT mp.MOPA_NOM, mp.MOPA_DESCRIPTION, mp.MOPA_NB_DOWNLOAD, v.VER_LIBELLE
         FROM MOD_PACK mp
         LEFT JOIN VERSION v ON mp.VER_ID = v.VER_ID
         WHERE mp.MOPA_EST_PUBLIC = 1
         ORDER BY mp.MOPA_NB_DOWNLOAD DESC, mp.MOPA_ID DESC
         LIMIT 4'
    );
    $publicModPacks = $mopaStmt->fetchAll();
} catch (Throwable $e) {
    // Mode dégradé silencieux si la base n'est pas encore peuplée
}
?>

<main class="app-main">
    <!-- SECTION HÉROS : SYSTÈME SOLAIRE CUBIQUE WIREFRAME -->
    <section class="hero-cosmic-section">
        <p class="mono" style="color: var(--accent-cyan); font-size: 0.8rem; letter-spacing: 0.25em; text-transform: uppercase; margin-bottom: 0.6rem;">
            Découvrez votre univers
        </p>
        <h1 class="hero-title">BOÖTES</h1>
        <p class="hero-subtitle">Transformer le vide en lieu de vie</p>

        <!-- Illustration centrale : Soleil cubique noir + 4 planètes cubiques en orbite -->
        <div class="solar-cube-system" aria-hidden="true">
            <div class="solar-glow"></div>
            <div class="orbit-ring orbit-ring-inner"></div>
            <div class="orbit-ring orbit-ring-outer"></div>

            <!-- Soleil cubique central -->
            <div class="wireframe-cube cube-sun"></div>

            <!-- 4 Planètes cubiques -->
            <div class="wireframe-cube cube-planet cube-planet-1"></div>
            <div class="wireframe-cube cube-planet cube-planet-2"></div>
            <div class="wireframe-cube cube-planet cube-planet-3"></div>
            <div class="wireframe-cube cube-planet cube-planet-4"></div>
        </div>

        <div style="display: flex; gap: 1rem; justify-content: center; flex-wrap: wrap; margin-top: 1.5rem;">
            <?php if (Auth::isLogged()): ?>
                <a href="/dashboard" class="btn btn-start">Accéder à mes serveurs</a>
            <?php else: ?>
                <a href="/auth" class="btn btn-start">Lancer mon serveur</a>
                <a href="#presentation" class="btn btn-cyan">Découvrir l'architecture</a>
            <?php endif; ?>
        </div>
    </section>

    <!-- SECTION PRÉSENTATION (AVEC SYSTÈME CUBIQUE VUE DE DESSUS) -->
    <section id="presentation" style="margin-top: 4rem;">
        <div style="text-align: center; margin-bottom: 2.5rem;">
            <h2 style="font-size: 1.3rem; display: inline-block; border-bottom: 2px solid var(--accent-cyan); padding-bottom: 0.4rem;">
                PRÉSENTATION
            </h2>
        </div>

        <div class="bento-grid">
            <!-- Colonne gauche : Système cubique vue de dessus avec 1 planète -->
            <div class="bento-col-6 bento-card" style="display: flex; flex-direction: column; align-items: center; justify-content: center;">
                <div class="topdown-orbit-system" aria-hidden="true">
                    <div class="solar-glow" style="width: 150px; height: 150px;"></div>
                    <div class="wireframe-cube cube-sun" style="width: 48px; height: 48px;"></div>
                    <div class="wireframe-cube cube-planet cube-planet-single"></div>
                </div>
                <p class="mono" style="font-size: 0.8rem; color: var(--text-secondary); margin-top: 0.75rem;">
                    Architecture orbitale — Isolation des processus Linux
                </p>
            </div>

            <!-- Colonne droite : Explication de l'optimisation Fabric -->
            <div class="bento-col-6 bento-card card-cyan-accent" style="display: flex; flex-direction: column; justify-content: center;">
                <h3 class="card-title" style="color: var(--accent-cyan); margin-bottom: 1rem;">
                    Performance Vanilla Optimisée sous Fabric
                </h3>
                <p style="color: var(--text-secondary); margin-bottom: 1rem;">
                    Chaque instance déployée sur <strong>Boötes</strong> repose sur un noyau Linux Mint allégé et un moteur
                    <strong>Fabric</strong> préconfiguré avec des mods d'optimisation serveur (Lithium, FerriteCore, Krypton).
                </p>
                <p style="color: var(--text-secondary); margin-bottom: 1.5rem;">
                    Même sans mod ajouté par vos soins, votre monde bénéficie d'un tickrate maximal, d'une consommation mémoire réduite
                    et d'outils de sauvegarde instantanés en <code>.tar.gz</code>.
                </p>
                <div>
                    <a href="/auth" class="btn btn-cyan">Créer un compte gratuitement</a>
                </div>
            </div>
        </div>
    </section>

    <!-- SECTION SERVEURS (LISTE EMPILÉE COMME SUR LA MAQUETTE) -->
    <section id="serveurs" style="margin-top: 4.5rem;">
        <div style="text-align: center; margin-bottom: 2rem;">
            <h2 style="font-size: 1.25rem; display: inline-block; border-bottom: 2px solid var(--text-primary); padding-bottom: 0.4rem;">
                SERVEURS
            </h2>
        </div>

        <div class="stacked-list">
            <?php if (!empty($publicServers)): ?>
                <?php foreach ($publicServers as $srv): ?>
                    <div class="stacked-item">
                        <div class="stacked-item-left">
                            <div class="stacked-cube-icon">SRV</div>
                            <div>
                                <div style="font-weight: 600; color: var(--text-primary);">
                                    <?= Security::sanitizeString((string) $srv['SVR_NOM']) ?>
                                </div>
                                <div class="mono" style="font-size: 0.8rem; color: var(--text-secondary);">
                                    Version <?= Security::sanitizeString((string) ($srv['VER_LIBELLE'] ?? 'Fabric')) ?>
                                    • <?= (int) $srv['SVR_JOUEURS_MAX'] ?> slots
                                    • <?= (int) $srv['SVR_RAM_ALLOUEE_MO'] ?> Mo RAM
                                </div>
                            </div>
                        </div>
                        <div>
                            <?php if ((int) $srv['ETA_ID'] === MinecraftServerManager::STATE_RUNNING): ?>
                                <span class="badge badge-online">En ligne</span>
                            <?php else: ?>
                                <span class="badge badge-offline">Éteint</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <!-- Aperçu par défaut si aucun serveur n'est encore créé -->
                <div class="stacked-item">
                    <div class="stacked-item-left">
                        <div class="stacked-cube-icon">01</div>
                        <div>
                            <div style="font-weight: 600;">Instance Arcturus — Survie</div>
                            <div class="mono" style="font-size: 0.8rem; color: var(--text-secondary);">Fabric 1.20.4 • Lithium + FerriteCore • 2048 Mo RAM</div>
                        </div>
                    </div>
                    <span class="badge badge-online">Prêt</span>
                </div>
                <div class="stacked-item">
                    <div class="stacked-item-left">
                        <div class="stacked-cube-icon">02</div>
                        <div>
                            <div style="font-weight: 600;">Nébuleuse Créative</div>
                            <div class="mono" style="font-size: 0.8rem; color: var(--text-secondary);">Fabric 1.20.1 • WorldEdit • 1024 Mo RAM</div>
                        </div>
                    </div>
                    <span class="badge badge-offline">En veille</span>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- SECTION MODPACKS (LISTE EMPILÉE COMME SUR LA MAQUETTE) -->
    <section id="modpacks" style="margin-top: 4.5rem; margin-bottom: 3rem;">
        <div style="text-align: center; margin-bottom: 2rem;">
            <h2 style="font-size: 1.25rem; display: inline-block; border-bottom: 2px solid var(--text-primary); padding-bottom: 0.4rem;">
                MODPACK
            </h2>
        </div>

        <div class="stacked-list">
            <?php if (!empty($publicModPacks)): ?>
                <?php foreach ($publicModPacks as $mp): ?>
                    <div class="stacked-item">
                        <div class="stacked-item-left">
                            <div class="stacked-cube-icon" style="border-color: var(--accent-orange); color: var(--accent-orange);">MP</div>
                            <div>
                                <div style="font-weight: 600; color: var(--text-primary);">
                                    <?= Security::sanitizeString((string) $mp['MOPA_NOM']) ?>
                                </div>
                                <div style="font-size: 0.85rem; color: var(--text-secondary);">
                                    <?= Security::sanitizeString((string) ($mp['MOPA_DESCRIPTION'] ?? 'Profil Fabric optimisé')) ?>
                                </div>
                            </div>
                        </div>
                        <div class="mono" style="font-size: 0.8rem; color: var(--accent-cyan);">
                            <?= (int) $mp['MOPA_NB_DOWNLOAD'] ?> téléchargements
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="stacked-item">
                    <div class="stacked-item-left">
                        <div class="stacked-cube-icon" style="border-color: var(--accent-orange); color: var(--accent-orange);">MP</div>
                        <div>
                            <div style="font-weight: 600;">Boötes Core — Vanilla Optimisé</div>
                            <div style="font-size: 0.85rem; color: var(--text-secondary);">Pack système préinstallé : Lithium, FerriteCore, Krypton et Spark.</div>
                        </div>
                    </div>
                    <span class="mono" style="font-size: 0.8rem; color: var(--accent-cyan);">Inclus par défaut</span>
                </div>
            <?php endif; ?>
        </div>
    </section>
</main>