<?php

declare(strict_types=1);

/**
 * Suite de tests de non-régression et de sécurité de Boötes.
 *
 * Usage : php tests/run.php [filtre]
 * Prérequis : PHP 8.2+ avec pdo_sqlite et mbstring (fileinfo et zip conseillés).
 *
 * Le script démarre le serveur PHP intégré sur une base SQLite jetable
 * (voir tests/support/schema.sql) et rejoue des parcours HTTP complets.
 */

require __DIR__ . '/support/TestPdo.php';
require __DIR__ . '/support/HttpClient.php';
require __DIR__ . '/support/Fixtures.php';

use Bootes\Src\Auth;
use Bootes\Src\RateLimiter;
use Bootes\Src\Security;
use Bootes\Src\ServerManager;
use Bootes\Tests\Fixtures;
use Bootes\Tests\HttpClient;
use Bootes\Tests\TestPdo;

final class TestFailure extends RuntimeException
{
}

final class TestContext
{
    public string $baseUrl = '';
    public string $dbFile = '';
    public string $storageDir = '';
    public string $errorLog = '';
    private int $errorLogOffset = 0;

    public function client(): HttpClient
    {
        return new HttpClient($this->baseUrl);
    }

    public function login(string $pseudo, ?HttpClient $client = null): HttpClient
    {
        $client ??= $this->client();
        $client->submit('/auth?mode=login', [
            'action'     => 'login',
            'identifier' => $pseudo,
            'password'   => Fixtures::PASSWORD,
        ]);

        return $client;
    }

    public function db(): PDO
    {
        return TestPdo::open($this->dbFile);
    }

    public function scalar(string $sql, array $params = []): mixed
    {
        $stmt = $this->db()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchColumn();
    }

    public function reset(): void
    {
        Fixtures::seed($this->dbFile);
        self::removeTree($this->storageDir);
        foreach (['instances', 'backups', 'uploads'] as $dir) {
            mkdir($this->storageDir . '/' . $dir, 0777, true);
        }
        file_put_contents($this->storageDir . '/backups/server_1_backup_test.tar.gz', 'BACKUP-DATA!');
        $this->drainPhpErrors();
    }

    /** @return list<string> Lignes d'erreurs PHP apparues depuis le dernier appel */
    public function drainPhpErrors(): array
    {
        clearstatcache();
        if (!is_file($this->errorLog)) {
            return [];
        }
        $content = (string) file_get_contents($this->errorLog, false, null, $this->errorLogOffset);
        $this->errorLogOffset += strlen($content);

        return array_values(array_filter(
            explode("\n", $content),
            static fn (string $l): bool => (bool) preg_match('/PHP (Warning|Notice|Deprecated|Fatal error|Parse error)|Uncaught/', $l)
        ));
    }

    public static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new TestFailure($message);
    }
}

/** @param array{status: int, headers: array<string, list<string>>, body: string} $response */
function header_value(array $response, string $name): string
{
    return implode(', ', $response['headers'][strtolower($name)] ?? []);
}

/** Construit une archive ZIP valide en mémoire (format jar/zip/mrpack). */
function make_zip(string $entryName, string $content): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'zip');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    $zip->addFromString($entryName, $content);
    $zip->close();
    $data = (string) file_get_contents($tmp);
    unlink($tmp);

    return $data;
}

/** @var array<int, array{0: string, 1: callable(TestContext): void}> $tests */
$tests = [];

// ---------------------------------------------------------------------------
// 1. Fumée : chaque route s'affiche sans erreur PHP, avec le bon code HTTP
// ---------------------------------------------------------------------------

$tests[] = ['Pages publiques accessibles sans erreur PHP', static function (TestContext $t): void {
    $client = $t->client();
    foreach (['/', '/home', '/catalogue', '/catalogue?type=mod', '/catalogue?type=modpack', '/catalogue?vue=grille', '/cgu', '/offres',
              '/auth', '/auth?mode=register', '/ressource-detail?id=1', '/serveur-public?id=1', '/serveur-public',
              '/serveurs', '/serveurs?tag=survie&en_ligne=1&vue=grille'] as $url) {
        $res = $client->get($url);
        check($res['status'] === 200, "$url doit répondre 200 (reçu {$res['status']})");
        check(str_contains($res['body'], '</html>'), "$url doit produire une page complète");
    }
    $legacy = $client->get('/catalogue?type=serveur');
    check($legacy['status'] === 302 && str_contains(implode(' ', $legacy['headers']['location'] ?? []), '/serveurs'), "L'ancien onglet Serveurs du catalogue doit rediriger vers l'annuaire");
    $errors = $t->drainPhpErrors();
    check($errors === [], "Erreurs PHP : " . implode(' | ', $errors));
}];

$tests[] = ['Routes protégées refusées aux visiteurs, route inconnue en 404', static function (TestContext $t): void {
    $client = $t->client();
    foreach (['/user/compte', '/user/createur-edition', '/serveur/dashboard?id=1', '/admin/dashboard', '/admin/super-admin'] as $url) {
        $res = $client->get($url);
        check($res['status'] === 403, "$url doit répondre 403 (reçu {$res['status']})");
    }
    check($client->get('/page-inexistante')['status'] === 404, 'Une route inconnue doit répondre 404');
    check(in_array($client->get('/../config/database')['status'], [400, 403, 404], true), 'Une tentative de traversée de répertoire doit être bloquée');
    check($client->get('/index.php?page=../config/database')['status'] === 404, 'Une route invalide passée en paramètre doit répondre 404');
}];

$tests[] = ['Espace utilisateur et gestion de serveur sans erreur PHP', static function (TestContext $t): void {
    $alice = $t->login('alice');
    $urls  = ['/user/compte', '/user/createur-edition', '/user/createur-edition?id=1', '/user/createur-statistiques?id=1'];
    foreach (['dashboard', 'console', 'logs', 'map', 'backups', 'joueurs', 'acces-admin', 'statistiques'] as $page) {
        $urls[] = '/serveur/' . $page . '?id=1';
    }
    foreach ($urls as $url) {
        $res = $alice->get($url);
        check($res['status'] === 200, "$url doit répondre 200 pour la propriétaire (reçu {$res['status']})");
    }
    check($alice->get('/serveur/dashboard?id=3')['status'] === 403, "Alice ne doit pas gérer le serveur d'un autre utilisateur");
    check($alice->get('/admin/dashboard')['status'] === 403, "Alice ne doit pas accéder à l'administration");
    $errors = $t->drainPhpErrors();
    check($errors === [], "Erreurs PHP : " . implode(' | ', $errors));
}];

$tests[] = ['Pages d\'administration sans erreur PHP', static function (TestContext $t): void {
    $carol = $t->login('carol');
    foreach (['/admin/dashboard', '/admin/hardware', '/admin/utilisateurs', '/admin/utilisateurs?tab=serveurs',
              '/admin/utilisateurs?tab=ressources', '/admin/signalements', '/user/compte?id=1', '/serveur/dashboard?id=1'] as $url) {
        $res = $carol->get($url);
        check($res['status'] === 200, "$url doit répondre 200 pour une admin (reçu {$res['status']})");
    }
    check($carol->get('/admin/super-admin')['status'] === 403, 'Une admin ne doit pas accéder au panneau Super-Admin');
    check($t->login('dave')->get('/admin/super-admin')['status'] === 200, 'Le Super-Admin doit accéder à son panneau');
    $errors = $t->drainPhpErrors();
    check($errors === [], "Erreurs PHP : " . implode(' | ', $errors));
}];

// ---------------------------------------------------------------------------
// 2. Authentification et sessions
// ---------------------------------------------------------------------------

$tests[] = ['Connexion : redirection effective et identifiant de session renouvelé (anti-fixation)', static function (TestContext $t): void {
    $client = $t->client();
    $page   = $client->get('/auth?mode=login');
    $before = $client->cookie('BOOTES_SESSID');
    check($before !== null, 'Un cookie de session doit être émis avant la connexion');

    $res = $client->post('/auth?mode=login', [
        'csrf_token' => (string) HttpClient::extractCsrfToken($page['body']),
        'action'     => 'login',
        'identifier' => 'alice',
        'password'   => Fixtures::PASSWORD,
    ]);
    check($res['status'] === 302, "La connexion doit rediriger (reçu {$res['status']})");
    check(header_value($res, 'Location') === '/user/compte', 'Redirection attendue vers /user/compte');
    check($client->cookie('BOOTES_SESSID') !== $before, "L'identifiant de session doit changer à la connexion");
    check($client->get('/user/compte')['status'] === 200, 'La session authentifiée doit être active');
}];

$tests[] = ['Déconnexion : la session est réellement détruite', static function (TestContext $t): void {
    $alice = $t->login('alice');
    $res   = $alice->submit('/auth', ['action' => 'logout']);
    check(in_array($res['status'], [200, 302], true), 'La déconnexion doit aboutir');
    check($alice->get('/user/compte')['status'] === 403, "L'espace membre ne doit plus être accessible après déconnexion");
}];

$tests[] = ['Déconnexion par GET (jeton dans l\'URL) refusée', static function (TestContext $t): void {
    $alice = $t->login('alice');
    $token = HttpClient::extractCsrfToken($alice->get('/user/compte')['body']);
    $alice->get('/auth?action=logout&csrf_token=' . $token);
    check($alice->get('/user/compte')['status'] === 200, 'Un GET ne doit pas pouvoir déconnecter (jeton CSRF exposé dans l\'URL)');
}];

$tests[] = ['Anti brute-force : blocage après plusieurs échecs', static function (TestContext $t): void {
    $client = $t->client();
    for ($i = 0; $i < 6; $i++) {
        $client->submit('/auth?mode=login', ['action' => 'login', 'identifier' => 'eve', 'password' => 'mauvais-mot-de-passe']);
    }
    $res = $client->submit('/auth?mode=login', ['action' => 'login', 'identifier' => 'eve', 'password' => Fixtures::PASSWORD]);
    check($res['status'] !== 302, 'Le bon mot de passe ne doit plus être accepté pendant le blocage');
    check(str_contains($res['body'], 'Trop de tentatives'), 'Un message de blocage explicite est attendu');
}];

$tests[] = ['Bannissement appliqué immédiatement aux sessions ouvertes', static function (TestContext $t): void {
    $eve = $t->login('eve');
    check($eve->get('/user/compte')['status'] === 200, 'Eve doit être connectée');
    $t->login('carol')->submit('/admin/utilisateurs', ['action' => 'toggle_ban_user', 'user_id' => Fixtures::EVE, 'ban_value' => '1']);
    check((int) $t->scalar('SELECT est_banni FROM UTILISATEUR WHERE id_utilisateur = ?', [Fixtures::EVE]) === 1, 'Eve doit être bannie en base');
    check($eve->get('/user/compte')['status'] === 403, 'La session d\'un compte banni doit être invalidée');
}];

$tests[] = ['Rétrogradation d\'un admin appliquée immédiatement', static function (TestContext $t): void {
    $carol = $t->login('carol');
    check($carol->get('/admin/dashboard')['status'] === 200, 'Carol doit être admin');
    $t->login('dave')->submit('/admin/super-admin', ['action' => 'revoke_admin', 'admin_user_id' => Fixtures::CAROL]);
    check($t->scalar('SELECT role FROM UTILISATEUR WHERE id_utilisateur = ?', [Fixtures::CAROL]) === 'UTILISATEUR', 'Carol doit être rétrogradée en base');
    check($carol->get('/admin/dashboard')['status'] === 403, 'Les droits admin doivent disparaître de la session ouverte');
}];

$tests[] = ['Pseudo et e-mail réservés refusés à l\'inscription', static function (TestContext $t): void {
    $client = $t->client();
    foreach ([['Anonyme_RGPD', 'pirate@example.test'], ['anonyme_rgpd', 'pirate2@example.test'], ['pirate3', 'anonyme-rgpd@bootes.local']] as [$pseudo, $email]) {
        $client->submit('/auth?mode=register', [
            'action' => 'register', 'pseudo' => $pseudo, 'email' => $email,
            'password' => 'Un-mot-de-passe-long', 'password_confirm' => 'Un-mot-de-passe-long', 'accept_cgu' => '1',
        ]);
        $client->clearCookies();
    }
    $count = (int) $t->scalar("SELECT COUNT(*) FROM UTILISATEUR WHERE pseudo LIKE 'anonyme%' OR email LIKE '%@bootes.local' OR pseudo = 'pirate3'");
    check($count === 0, 'Aucun compte ne doit pouvoir usurper l\'identité réservée Anonyme_RGPD');
}];

$tests[] = ['Inscription standard fonctionnelle', static function (TestContext $t): void {
    $client = $t->client();
    $res    = $client->submit('/auth?mode=register', [
        'action' => 'register', 'pseudo' => 'nouveau_joueur', 'email' => 'nouveau@example.test',
        'password' => 'Un-mot-de-passe-long', 'password_confirm' => 'Un-mot-de-passe-long', 'accept_cgu' => '1',
    ]);
    check($res['status'] === 302, 'L\'inscription doit connecter et rediriger');
    check($client->get('/user/compte')['status'] === 200, 'Le nouveau compte doit être connecté');
}];

// ---------------------------------------------------------------------------
// 3. Contrôle d'accès (escalade de privilèges, IDOR)
// ---------------------------------------------------------------------------

$tests[] = ['Une admin ne peut pas modifier le compte du Super-Admin', static function (TestContext $t): void {
    $carol = $t->login('carol');
    $carol->submit('/user/compte?id=' . Fixtures::DAVE, [
        'action' => 'update_profile', 'target_user_id' => Fixtures::DAVE,
        'pseudo' => 'dave', 'email' => 'carol-controle@example.test', 'new_password' => 'Pris-Par-Carol-2026',
    ]);
    $carol->submit('/user/compte?id=' . Fixtures::DAVE, [
        'action' => 'admin_update_account', 'target_user_id' => Fixtures::DAVE, 'type_compte' => 'STANDARD',
        'max_ram_allouable' => '512', 'est_banni' => '1',
    ]);
    check($t->scalar('SELECT email FROM UTILISATEUR WHERE id_utilisateur = ?', [Fixtures::DAVE]) === 'dave@example.test', "L'e-mail du Super-Admin ne doit pas changer");
    check((int) $t->scalar('SELECT est_banni FROM UTILISATEUR WHERE id_utilisateur = ?', [Fixtures::DAVE]) === 0, 'Le Super-Admin ne doit pas être banni');
    check($t->login('dave')->get('/admin/super-admin')['status'] === 200, 'Le Super-Admin doit toujours pouvoir se connecter');
}];

$tests[] = ['Une admin ne peut pas bannir le Super-Admin via la modération', static function (TestContext $t): void {
    $carol = $t->login('carol');
    $carol->submit('/admin/utilisateurs', ['action' => 'toggle_ban_user', 'user_id' => Fixtures::DAVE, 'ban_value' => '1']);
    $carol->submit('/admin/signalements', ['action' => 'ban_reported_user', 'report_id' => 2]);
    check((int) $t->scalar('SELECT est_banni FROM UTILISATEUR WHERE id_utilisateur = ?', [Fixtures::DAVE]) === 0, 'Le Super-Admin ne doit pas être banni');
}];

$tests[] = ['Une admin gère toujours les comptes ordinaires', static function (TestContext $t): void {
    $carol = $t->login('carol');
    $carol->submit('/user/compte?id=' . Fixtures::EVE, [
        'action' => 'admin_update_account', 'target_user_id' => Fixtures::EVE, 'type_compte' => 'PREMIUM', 'max_ram_allouable' => '4096',
    ]);
    check($t->scalar('SELECT type_compte FROM UTILISATEUR WHERE id_utilisateur = ?', [Fixtures::EVE]) === 'PREMIUM', 'Eve doit passer Premium');
}];

$tests[] = ['Co-admin LECTURE : consultation seule', static function (TestContext $t): void {
    $bob = $t->login('bob');
    check($bob->get('/serveur/dashboard?id=1')['status'] === 200, 'Bob doit pouvoir consulter le serveur');
    $bob->submit('/serveur/dashboard?id=1', ['action' => 'start', 'server_id' => 1]);
    $bob->submit('/serveur/dashboard?id=1', [
        'action' => 'update_settings', 'server_id' => 1, 'nom_serveur' => 'Pris par Bob', 'version_mc' => '1.21.4',
        'type_loader' => 'VANILLA', 'mode_jeu' => 'SURVIE', 'slots_max' => 20, 'ram_allouee' => 2048, 'description' => 'x',
    ]);
    $bob->submit('/serveur/backups?id=1', ['action' => 'delete_backup', 'server_id' => 1, 'backup_id' => 1]);
    $bob->submit('/serveur/acces-admin?id=1', ['action' => 'add_listed_player', 'server_id' => 1, 'pseudo_mc' => 'Griefer', 'type_liste' => 'OP']);
    check($t->scalar('SELECT statut FROM SERVEUR WHERE id_serveur = 1') === 'HORS_LIGNE', 'Bob ne doit pas démarrer le serveur');
    check($t->scalar('SELECT nom_serveur FROM SERVEUR WHERE id_serveur = 1') === 'Survie Alice', 'Bob ne doit pas modifier la configuration');
    check((int) $t->scalar('SELECT COUNT(*) FROM BACKUP WHERE id_backup = 1') === 1, 'Bob ne doit pas supprimer de sauvegarde');
    check((int) $t->scalar("SELECT COUNT(*) FROM ETRE_LISTER WHERE pseudo_mc = 'Griefer'") === 0, 'Bob ne doit pas modifier les listes joueurs');
    $res = $bob->submit('/serveur/backups?id=1', ['action' => 'download_backup', 'server_id' => 1, 'backup_id' => 1]);
    check(!str_contains($res['body'], 'BACKUP-DATA!'), 'Bob ne doit pas télécharger les sauvegardes');
}];

$tests[] = ['Co-admin COMPLET : gestion du serveur autorisée', static function (TestContext $t): void {
    $frank = $t->login('frank');
    $frank->submit('/serveur/dashboard?id=1', ['action' => 'start', 'server_id' => 1]);
    check($t->scalar('SELECT statut FROM SERVEUR WHERE id_serveur = 1') === 'EN_LIGNE', 'Frank doit pouvoir démarrer le serveur');
    $frank->submit('/serveur/console?id=1', ['action' => 'send_command', 'server_id' => 1, 'command' => 'say Bonjour']);
    $log = (string) @file_get_contents($t->storageDir . '/instances/server_1/logs/latest.log');
    check(str_contains($log, 'say Bonjour'), 'La commande console doit être journalisée');
    $errors = $t->drainPhpErrors();
    check($errors === [], "Erreurs PHP : " . implode(' | ', $errors));
}];

$tests[] = ['Serveur privé : ni avis ni signalement possibles par un tiers', static function (TestContext $t): void {
    $mallory = $t->login('mallory');
    $mallory->submit('/serveur-public?id=1', ['action' => 'rate_server', 'note' => 5, 'commentaire' => 'Super serveur public']);
    check((int) $t->scalar('SELECT COUNT(*) FROM AVIS_SERVEUR WHERE id_serveur = 1') === 1, 'Un avis sur un serveur public doit être accepté');
    $mallory->post('/serveur-public?id=2', [
        'csrf_token' => (string) HttpClient::extractCsrfToken($mallory->get('/serveur-public?id=1')['body']),
        'action' => 'rate_server', 'note' => 1, 'commentaire' => 'Avis sur un serveur privé',
    ]);
    check((int) $t->scalar('SELECT COUNT(*) FROM AVIS_SERVEUR WHERE id_serveur = 2') === 0, 'Aucun avis ne doit être possible sur un serveur privé');
}];

$tests[] = ['Ressource suspendue invisible et non installable', static function (TestContext $t): void {
    check($t->client()->get('/ressource-detail?id=3')['status'] === 404, 'Une ressource suspendue doit être masquée au public');
    $alice = $t->login('alice');
    $alice->submit('/serveur/dashboard?id=1', ['action' => 'quick_install_resource', 'server_id' => 1, 'resource_id' => 3]);
    check((int) $t->scalar('SELECT COUNT(*) FROM INSTALLER_RESSOURCE WHERE id_ressource = 3') === 0, 'Une ressource suspendue ne doit pas être installable');
    check($t->login('mallory')->get('/ressource-detail?id=3')['status'] === 200, 'L\'auteur doit encore voir sa ressource suspendue');
    $alice->submit('/serveur/dashboard?id=1', ['action' => 'quick_install_resource', 'server_id' => 1, 'resource_id' => 1]);
    check((int) $t->scalar('SELECT COUNT(*) FROM INSTALLER_RESSOURCE WHERE id_ressource = 1') === 1, 'Une ressource validée doit rester installable');
}];

// ---------------------------------------------------------------------------
// 4. Fichiers : upload, téléchargements, sauvegardes
// ---------------------------------------------------------------------------

$tests[] = ['Upload : contenu non-ZIP refusé, archive valide stockée sous un nom aléatoire', static function (TestContext $t): void {
    $alice = $t->login('alice');
    $fields = ['action' => 'upload_version', 'resource_id' => 1, 'numero_version' => '1.php.', 'changelog' => 'Test', 'versions_mc' => ['1.21.4']];

    $alice->submit('/user/createur-edition?id=1', $fields, [
        'archive_file' => ['name' => 'shell.jar', 'content' => '<?php echo "ceci n est pas une archive"; ?>', 'type' => 'application/java-archive'],
    ]);
    check((int) $t->scalar('SELECT COUNT(*) FROM FICHIER_RESSOURCE') === 0, 'Un faux .jar (contenu PHP) doit être refusé');

    $zip = make_zip('fabric.mod.json', '{"id":"alpha"}');
    $alice->submit('/user/createur-edition?id=1', $fields, [
        'archive_file' => ['name' => 'alpha-mod.jar', 'content' => $zip, 'type' => 'application/java-archive'],
    ]);
    $stored = (string) $t->scalar('SELECT chemin_fichier FROM FICHIER_RESSOURCE LIMIT 1');
    check($stored !== '', 'Une archive ZIP valide doit être acceptée');
    check(!str_contains(strtolower($stored), 'php'), 'Le nom stocké ne doit contenir aucune donnée fournie par l\'utilisateur');
    $files = glob($t->storageDir . '/uploads/*/*') ?: [];
    check(count($files) === 1 && file_get_contents($files[0]) === $zip, 'Le fichier doit être stocké dans le stockage privé');
    $errors = $t->drainPhpErrors();
    check($errors === [], "Erreurs PHP : " . implode(' | ', $errors));
}];

$tests[] = ['Téléchargement d\'une ressource servi par l\'application', static function (TestContext $t): void {
    $alice = $t->login('alice');
    $zip   = make_zip('plugin.yml', 'name: Alpha');
    $alice->submit('/user/createur-edition?id=1', [
        'action' => 'upload_version', 'resource_id' => 1, 'numero_version' => '2.0.0', 'versions_mc' => ['1.21.4'],
    ], ['archive_file' => ['name' => 'Alpha Mod été (v2).jar', 'content' => $zip]]);

    $visitor = $t->client();
    $res     = $visitor->submit('/ressource-detail?id=1', ['action' => 'download']);
    check($res['status'] === 200 && $res['body'] === $zip, 'Le visiteur doit recevoir exactement l\'archive');
    check(str_contains(header_value($res, 'Content-Disposition'), 'attachment'), 'Le fichier doit être servi en pièce jointe');
    check(!preg_match('/[\r\n]/', header_value($res, 'Content-Disposition')), 'Le nom de fichier ne doit pas injecter d\'en-tête');
    check((int) $t->scalar('SELECT nb_telechargements FROM RESSOURCE WHERE id_ressource = 1') === 1, 'Le compteur doit être incrémenté une fois');
}];

$tests[] = ['Téléchargement d\'une sauvegarde par la propriétaire', static function (TestContext $t): void {
    $res = $t->login('alice')->submit('/serveur/backups?id=1', ['action' => 'download_backup', 'server_id' => 1, 'backup_id' => 1]);
    check($res['status'] === 200 && $res['body'] === 'BACKUP-DATA!', 'La sauvegarde doit être téléchargée intacte');
    check(str_contains(header_value($res, 'Content-Disposition'), 'server_1_backup_test.tar.gz'), 'Le nom de l\'archive doit être transmis');
}];

// ---------------------------------------------------------------------------
// 5. Quotas et cycle de vie des comptes
// ---------------------------------------------------------------------------

$tests[] = ['Quota de serveurs par compte Standard', static function (TestContext $t): void {
    $alice  = $t->login('alice');
    $create = static fn (string $name) => $alice->submit('/user/compte', [
        'action' => 'create_server', 'nom_serveur' => $name, 'version_mc' => '1.21.4', 'mode_jeu' => 'SURVIE',
        'ram_allouee' => 1024, 'slots_max' => 10, 'description' => '', 'est_public' => '1',
    ]);
    $create('Troisieme serveur');
    $create('Quatrieme serveur');
    check((int) $t->scalar('SELECT COUNT(*) FROM SERVEUR WHERE id_proprietaire = ?', [Fixtures::ALICE]) === 3, 'Un compte Standard est limité à 3 serveurs');
    $ports = $t->db()->query('SELECT port FROM SERVEUR')->fetchAll(PDO::FETCH_COLUMN);
    check(count($ports) === count(array_unique($ports)), 'Chaque serveur doit recevoir un port unique');
}];

$tests[] = ['Validation des paramètres de création de serveur', static function (TestContext $t): void {
    $t->login('alice')->submit('/user/compte', [
        'action' => 'create_server', 'nom_serveur' => 'Serveur exotique', 'version_mc' => 'b1.7.3; DROP', 'mode_jeu' => '<script>',
        'ram_allouee' => 1024, 'slots_max' => 10,
    ]);
    check((int) $t->scalar("SELECT COUNT(*) FROM SERVEUR WHERE nom_serveur = 'Serveur exotique'") === 0, 'Version et mode de jeu hors liste blanche doivent être refusés');
}];

$tests[] = ['Plafond de RAM par utilisateur au démarrage', static function (TestContext $t): void {
    $db = $t->db();
    $db->exec("UPDATE SERVEUR SET ram_allouee = 2048 WHERE id_serveur IN (1, 2)");
    $alice = $t->login('alice');
    $alice->submit('/serveur/dashboard?id=1', ['action' => 'start', 'server_id' => 1]);
    $alice->submit('/serveur/dashboard?id=2', ['action' => 'start', 'server_id' => 2]);
    check($t->scalar('SELECT statut FROM SERVEUR WHERE id_serveur = 1') === 'EN_LIGNE', 'Le premier serveur doit démarrer');
    check($t->scalar('SELECT statut FROM SERVEUR WHERE id_serveur = 2') === 'HORS_LIGNE', 'Le second dépasserait le quota de 2048 Mo du compte');
}];

$tests[] = ['Suppression RGPD complète du compte (avec ré-authentification)', static function (TestContext $t): void {
    $alice = $t->login('alice');
    $alice->submit('/user/compte', ['action' => 'delete_account_rgpd', 'rgpd_resources_mode' => 'anonymize', 'confirm_delete_text' => 'SUPPRIMER', 'current_password' => 'faux']);
    check((int) $t->scalar('SELECT COUNT(*) FROM UTILISATEUR WHERE id_utilisateur = ?', [Fixtures::ALICE]) === 1, 'Sans mot de passe valide, le compte doit être conservé');

    $alice->submit('/user/compte', ['action' => 'delete_account_rgpd', 'rgpd_resources_mode' => 'anonymize', 'confirm_delete_text' => 'SUPPRIMER', 'current_password' => Fixtures::PASSWORD]);
    check((int) $t->scalar('SELECT COUNT(*) FROM UTILISATEUR WHERE id_utilisateur = ?', [Fixtures::ALICE]) === 0, 'Le compte doit être supprimé');
    check((int) $t->scalar('SELECT COUNT(*) FROM SERVEUR WHERE id_proprietaire = ?', [Fixtures::ALICE]) === 0, 'Ses serveurs doivent être supprimés');
    check((int) $t->scalar('SELECT COUNT(*) FROM TICKET_SUPPORT WHERE id_utilisateur = ?', [Fixtures::ALICE]) === 0, 'Ses tickets doivent être supprimés');
    $anon = $t->db()->query("SELECT u.pseudo, u.est_banni FROM RESSOURCE r JOIN UTILISATEUR u ON u.id_utilisateur = r.id_auteur WHERE r.id_ressource = 1")->fetch();
    check(is_array($anon) && $anon['pseudo'] === 'Anonyme_RGPD' && (int) $anon['est_banni'] === 1, 'Les ressources doivent être rattachées au compte anonyme verrouillé');
    check(!is_dir($t->storageDir . '/instances/server_1') && !is_file($t->storageDir . '/backups/server_1_backup_test.tar.gz'), 'Les fichiers des serveurs doivent être effacés');
    check($alice->get('/user/compte')['status'] === 403, 'La session doit être fermée');
}];

$tests[] = ['Suppression d\'un serveur signalé (modération)', static function (TestContext $t): void {
    $db = $t->db();
    $db->exec("INSERT INTO SIGNALEMENT (id_signalement, id_auteur_signalement, type_cible, id_cible, motif, description, statut, date_signalement)
               VALUES (3, 6, 'SERVEUR', 1, 'Triche', 'Serveur de triche signalé.', 'EN_ATTENTE', NOW())");
    $db->exec("INSERT INTO AVIS_SERVEUR (id_serveur, id_utilisateur, note, commentaire, date_avis) VALUES (1, 6, 1, 'Nul', NOW())");
    $t->login('carol')->submit('/admin/signalements', ['action' => 'delete_reported_content', 'report_id' => 3]);
    check((int) $t->scalar('SELECT COUNT(*) FROM SERVEUR WHERE id_serveur = 1') === 0, 'Le serveur signalé doit être supprimé avec ses dépendances');
    check($t->scalar('SELECT statut FROM SIGNALEMENT WHERE id_signalement = 3') === 'TRAITE', 'Le signalement doit être marqué traité');
}];

// ---------------------------------------------------------------------------
// 6. Durcissement général
// ---------------------------------------------------------------------------

$tests[] = ['En-têtes de sécurité HTTP', static function (TestContext $t): void {
    $res = $t->client()->get('/home');
    check(str_contains(header_value($res, 'Content-Security-Policy'), "default-src 'self'"), 'CSP attendue');
    check(header_value($res, 'X-Content-Type-Options') === 'nosniff', 'nosniff attendu');
    check(header_value($res, 'X-Frame-Options') !== '', 'X-Frame-Options attendu');
    check(header_value($res, 'Referrer-Policy') !== '', 'Referrer-Policy attendu');
    $cookie = header_value($res, 'Set-Cookie');
    check(stripos($cookie, 'HttpOnly') !== false && stripos($cookie, 'SameSite=Lax') !== false, 'Cookie de session HttpOnly + SameSite attendu');
}];

$tests[] = ['Erreur interne : page 500 générique, sans détail technique', static function (TestContext $t): void {
    $client = $t->client();
    $page   = $client->get('/home');
    $ctx    = stream_context_create(['http' => ['ignore_errors' => true, 'header' => "X-Test-Fault: FROM SERVEUR s\r\nConnection: close"]]);
    $body   = (string) @file_get_contents($t->baseUrl . '/home', false, $ctx);
    $status = (int) (preg_match('#HTTP/\S+ (\d{3})#', $http_response_header[0] ?? '', $m) ? $m[1] : 0);
    check($status === 500, "Une exception non gérée doit produire un code 500 (reçu $status)");
    check(!str_contains($body, 'secret/path.php') && !str_contains($body, 'SQLSTATE') && !str_contains($body, '#0 '), 'Aucun détail technique ne doit fuiter');
    check(str_contains($body, '</html>'), 'Une page d\'erreur complète doit être rendue');
    $t->drainPhpErrors();
    unset($page);
}];

$tests[] = ['Requête sans jeton CSRF rejetée', static function (TestContext $t): void {
    $alice = $t->login('alice');
    $alice->post('/user/compte', ['action' => 'create_server', 'nom_serveur' => 'Sans CSRF', 'version_mc' => '1.21.4', 'mode_jeu' => 'SURVIE', 'ram_allouee' => 1024, 'slots_max' => 10]);
    check((int) $t->scalar("SELECT COUNT(*) FROM SERVEUR WHERE nom_serveur = 'Sans CSRF'") === 0, 'Une requête sans jeton CSRF ne doit rien modifier');
}];

$tests[] = ['Échappement XSS des données utilisateur', static function (TestContext $t): void {
    $t->db()->exec("UPDATE SERVEUR SET nom_serveur = '<script>alert(1)</script>' WHERE id_serveur = 1");
    $body = $t->login('alice')->get('/serveur/dashboard?id=1')['body'];
    check(!str_contains($body, '<script>alert(1)</script>') && str_contains($body, '&lt;script&gt;'), 'Le nom du serveur doit être échappé');
}];

$tests[] = ['Sauvegarde : création puis restauration par la propriétaire', static function (TestContext $t): void {
    $alice = $t->login('alice');
    $alice->submit('/serveur/dashboard?id=1', ['action' => 'start', 'server_id' => 1]);
    $alice->submit('/serveur/backups?id=1', ['action' => 'create_backup', 'server_id' => 1, 'nom_backup' => 'Avant le dragon']);
    $backup = $t->db()->query("SELECT id_backup, nom_fichier FROM BACKUP WHERE nom_backup = 'Avant le dragon'")->fetch();
    check(is_array($backup), 'La sauvegarde doit être enregistrée en base');
    check(is_file($t->storageDir . '/backups/' . $backup['nom_fichier']), 'L\'archive doit être écrite dans le stockage privé');
    $alice->submit('/serveur/backups?id=1', ['action' => 'restore_backup', 'server_id' => 1, 'backup_id' => (int) $backup['id_backup']]);
    check($t->scalar('SELECT statut FROM SERVEUR WHERE id_serveur = 1') === 'HORS_LIGNE', 'La restauration doit arrêter le serveur au préalable');
    $errors = $t->drainPhpErrors();
    check($errors === [], "Erreurs PHP : " . implode(' | ', $errors));
}];

$tests[] = ['Unitaires : sessions, rôles, permissions et motifs LIKE', static function (TestContext $t): void {
    require_once dirname(__DIR__) . '/src/Security.php';
    require_once dirname(__DIR__) . '/src/Auth.php';

    $now = 1_800_000_000;
    check(!Auth::isSessionExpired(['last_activity' => $now - 60], $now), 'Une session active ne doit pas expirer');
    check(Auth::isSessionExpired(['last_activity' => $now - Auth::SESSION_IDLE_TIMEOUT - 1], $now), 'Inactivité trop longue : expiration');
    check(Auth::isSessionExpired(['last_activity' => $now, 'user' => ['id' => 1], 'auth_time' => $now - Auth::SESSION_ABSOLUTE_TIMEOUT - 1], $now), 'Durée maximale dépassée : expiration');

    check(Auth::canAdministrate('ADMIN', 'UTILISATEUR') && Auth::canAdministrate('SUPER_ADMIN', 'ADMIN'), 'Hiérarchie de gestion attendue');
    check(!Auth::canAdministrate('ADMIN', 'ADMIN') && !Auth::canAdministrate('ADMIN', 'SUPER_ADMIN') && !Auth::canAdministrate('SUPER_ADMIN', 'SUPER_ADMIN'), 'Aucune gestion d\'un rang égal ou supérieur');
    check(!Auth::canAdministrate('UTILISATEUR', 'VISITEUR'), 'Un utilisateur n\'administre personne');

    check(Auth::serverLevelAllows('COMPLET', 'CONSOLE') && !Auth::serverLevelAllows('LECTURE', 'CONSOLE'), 'Ordre des niveaux de co-administration');
    check(!Auth::serverLevelAllows(null, 'LECTURE') && !Auth::serverLevelAllows('INCONNU', 'LECTURE'), 'Niveau absent ou inconnu : refus');

    check(Auth::isReservedIdentity('ANONYME_rgpd', 'x@example.test') && Auth::isReservedIdentity('joueur', 'a@Bootes.Local'), 'Identités réservées insensibles à la casse');
    check(Security::likeContains('50%_off!') === '%50!%!_off!!%', 'Les jokers LIKE doivent être neutralisés');
}];

$tests[] = ['Unitaires : configuration .env, limiteur de débit et lecture des journaux', static function (TestContext $t): void {
    require_once dirname(__DIR__) . '/config/constants.php';
    require_once dirname(__DIR__) . '/src/RateLimiter.php';
    require_once dirname(__DIR__) . '/src/Security.php';
    require_once dirname(__DIR__) . '/src/Auth.php';
    require_once dirname(__DIR__) . '/src/ResourceManager.php';
    require_once dirname(__DIR__) . '/src/ServerManager.php';

    $envFile = $t->storageDir . '/test.env';
    file_put_contents($envFile, "# commentaire\nBOOTES_TEST_VALUE=\"valeur secrète\"\ninvalid-key=1\n");
    \Bootes\Config\AppConfig::load($envFile);
    check(\Bootes\Config\AppConfig::env('BOOTES_TEST_VALUE') === 'valeur secrète', 'Les valeurs du .env doivent être lues (guillemets retirés)');
    check(\Bootes\Config\AppConfig::env('invalid-key') === null, 'Les clés invalides doivent être ignorées');

    $limiter = new RateLimiter($t->storageDir . '/ratelimit');
    for ($i = 0; $i < 3; $i++) {
        $limiter->hit('cle', 60);
    }
    check($limiter->tooManyAttempts('cle', 3) && !$limiter->tooManyAttempts('autre', 3), 'Le quota doit être atteint pour la seule clé concernée');
    check($limiter->availableIn('cle') > 0 && $limiter->availableIn('cle') <= 60, 'Délai de réouverture borné par la fenêtre');
    $limiter->clear('cle');
    check(!$limiter->tooManyAttempts('cle', 3), 'Une connexion réussie remet le compteur à zéro');

    $logDir = $t->storageDir . '/instances/server_9/logs';
    mkdir($logDir, 0777, true);
    $handle = fopen($logDir . '/latest.log', 'wb');
    for ($i = 1; $i <= 20000; $i++) {
        fwrite($handle, sprintf("[12:00:00] [Server thread/INFO]: ligne %05d %s\n", $i, str_repeat('x', 40)));
    }
    fclose($handle);
    $manager = new ServerManager($t->db(), $t->storageDir . '/instances', $t->storageDir . '/backups');
    $lines   = $manager->readLatestLog(9, 50);
    check(count($lines) === 50 && str_contains(end($lines), 'ligne 20000'), 'Seules les dernières lignes d\'un gros journal doivent être lues');
}];

$tests[] = ['Catalogue : filtre Modpacks fonctionnel', static function (TestContext $t): void {
    $body = $t->client()->get('/catalogue?type=modpack')['body'];
    check(str_contains($body, 'Pack Beta'), 'Le modpack validé doit apparaître dans l\'onglet Modpacks');
    check(!str_contains($body, 'Mod Suspendu'), 'Une ressource suspendue ne doit pas apparaître');
}];

// ---------------------------------------------------------------------------
// Annuaire, médias et pages Premium
// ---------------------------------------------------------------------------

/** Image PNG unie générée par GD pour les tests de téléversement. */
function make_png(int $width, int $height): string
{
    $img = imagecreatetruecolor($width, $height);
    imagefill($img, 0, 0, imagecolorallocate($img, 40, 120, 200));
    ob_start();
    imagepng($img);

    return (string) ob_get_clean();
}

$tests[] = ['Annuaire : serveur externe référencé, doublon, adresses internes, quota et panneau refusé', static function (TestContext $t): void {
    $alice  = $t->login('alice');
    $fields = [
        'action' => 'save_listing', 'nom_serveur' => 'Royaume Test', 'adresse_ip' => 'Play.Royaume-Test.fr', 'port' => '25565',
        'version_mc' => '1.21.4', 'mode_jeu' => 'SURVIE', 'resume' => 'Un serveur de test', 'description' => "Ligne 1\n\nLigne 2",
        'etiquettes' => ['survie', 'pvp'], 'est_public' => '1',
    ];
    $res = $alice->submit('/user/annuaire-edition', $fields);
    check($res['status'] === 302, "La création doit rediriger vers l'éditeur (reçu {$res['status']})");
    $id = (int) $t->scalar("SELECT id_serveur FROM SERVEUR WHERE origine = 'EXTERNE' AND adresse_ip = 'play.royaume-test.fr'");
    check($id > 0, 'La fiche externe doit être enregistrée avec une adresse normalisée');
    check($t->scalar('SELECT etiquettes FROM SERVEUR WHERE id_serveur = ?', [$id]) === ',survie,pvp,', 'Les étiquettes doivent être enregistrées');
    check(str_contains((string) $t->scalar('SELECT description FROM SERVEUR WHERE id_serveur = ?', [$id]), "\n\n"), 'Les paragraphes de la présentation doivent être conservés');

    $alice->submit('/user/annuaire-edition', ['nom_serveur' => 'Doublon'] + $fields);
    check((int) $t->scalar("SELECT COUNT(*) FROM SERVEUR WHERE LOWER(adresse_ip) = 'play.royaume-test.fr'") === 1, 'Un serveur déjà référencé ne doit pas être dupliqué');

    foreach (['192.168.1.20', '127.0.0.1', '10.0.0.5:25565', 'localhost', 'play.bootes.local', 'srv.internal'] as $forbidden) {
        $alice->submit('/user/annuaire-edition', ['adresse_ip' => $forbidden, 'nom_serveur' => 'Interne'] + $fields);
    }
    check((int) $t->scalar("SELECT COUNT(*) FROM SERVEUR WHERE nom_serveur = 'Interne'") === 0, 'Les adresses internes ou réservées doivent être refusées');

    $alice->submit('/user/annuaire-edition', ['adresse_ip' => 'deux.royaume-test.fr', 'nom_serveur' => 'Deuxième'] + $fields);
    $alice->submit('/user/annuaire-edition', ['adresse_ip' => 'trois.royaume-test.fr', 'nom_serveur' => 'Troisième'] + $fields);
    check((int) $t->scalar("SELECT COUNT(*) FROM SERVEUR WHERE id_proprietaire = ? AND origine = 'EXTERNE'", [Fixtures::ALICE]) === 2, 'Un compte Standard est limité à 2 fiches externes');

    check($alice->get('/serveur/dashboard?id=' . $id)['status'] === 404, "Une fiche externe n'a pas de panneau d'administration");
    check($t->login('eve')->get('/user/annuaire-edition?id=' . $id)['status'] === 404, 'Un tiers ne doit pas pouvoir modifier la fiche');

    $public = $t->client()->get('/serveurs?tag=pvp')['body'];
    check(str_contains($public, 'Royaume Test') && str_contains($public, 'play.royaume-test.fr'), "La fiche doit apparaître dans l'annuaire filtré, avec son adresse");

    $alice->submit('/user/annuaire-edition?id=' . $id, ['action' => 'delete_listing']);
    check((int) $t->scalar('SELECT COUNT(*) FROM SERVEUR WHERE id_serveur = ?', [$id]) === 0, 'Le propriétaire doit pouvoir retirer sa fiche');
    $errors = $t->drainPhpErrors();
    check($errors === [], 'Erreurs PHP : ' . implode(' | ', $errors));
}];

$tests[] = ['Pages Premium : mise en page refusée en Standard, visible par tous, barre IP fixe', static function (TestContext $t): void {
    $layoutPost = [
        'action' => 'save_layout', 'server_id' => 1, 'op' => 'save', 'theme' => 'rubis',
        'blocs'  => [['type' => 'annonce', 'texte' => 'Événement de test', 'ton' => 'evenement'], ['type' => 'presentation', 'colonnes' => 2]],
    ];
    $t->login('alice')->submit('/user/annuaire-edition?id=1', $layoutPost);
    check((int) $t->scalar('SELECT COUNT(*) FROM PAGE_PERSONNALISEE') === 0, 'Un compte Standard ne doit pas pouvoir enregistrer de mise en page');

    $t->db()->exec("UPDATE UTILISATEUR SET type_compte = 'PREMIUM' WHERE id_utilisateur = " . Fixtures::ALICE);
    $t->login('alice')->submit('/user/annuaire-edition?id=1', $layoutPost);
    check($t->scalar("SELECT theme FROM PAGE_PERSONNALISEE WHERE type_cible = 'SERVEUR' AND id_cible = 1") === 'rubis', 'Un compte Premium doit pouvoir enregistrer sa mise en page');

    // Fin de l'abonnement : la page reste visible telle quelle par tous les visiteurs
    $t->db()->exec("UPDATE UTILISATEUR SET type_compte = 'STANDARD' WHERE id_utilisateur = " . Fixtures::ALICE);
    $page = $t->client()->get('/serveur-public?id=1')['body'];
    check(str_contains($page, 'page-theme--rubis') && str_contains($page, 'Événement de test'), 'Tous les visiteurs voient la page personnalisée');
    $bar = strpos($page, 'class="connect-bar ');
    check(substr_count($page, 'class="connect-bar ') === 1 && $bar !== false && $bar < (int) strpos($page, 'class="page-blocks"'), 'La barre de connexion reste unique et placée avant les blocs');
    check(preg_match('/class="connect-bar[^"]*page-theme/', $page) === 0, "Le thème ne s'applique pas à la barre de connexion");
    check(str_contains($page, 'data-copy="play.bootes.local'), "Le bouton « Copier l'IP » doit porter l'adresse du serveur");
}];

$tests[] = ['Médias : image réencodée en WebP, faux fichiers refusés, galerie plafonnée', static function (TestContext $t): void {
    $alice = $t->login('alice');
    $alice->submit('/user/annuaire-edition?id=1', ['action' => 'upload_icon', 'server_id' => 1], ['image' => ['name' => 'logo.png', 'content' => make_png(300, 200)]]);
    $token = (string) $t->scalar("SELECT jeton FROM MEDIA WHERE type_cible = 'SERVEUR' AND id_cible = 1 AND role = 'ICONE'");
    check(preg_match('/^[a-f0-9]{32}$/', $token) === 1, "L'icône doit être enregistrée sous un jeton aléatoire");
    check((int) $t->scalar('SELECT largeur FROM MEDIA WHERE jeton = ?', [$token]) === 200, "L'icône doit être recadrée en carré, sans agrandissement");

    $res = $t->client()->get('/media?f=' . $token);
    check($res['status'] === 200 && str_starts_with($res['body'], 'RIFF') && str_contains(header_value($res, 'Content-Type'), 'image/webp'), "L'image doit être servie en WebP");
    check(str_contains(header_value($res, 'X-Content-Type-Options'), 'nosniff'), 'En-tête nosniff attendu');

    $alice->submit('/user/annuaire-edition?id=1', ['action' => 'upload_icon', 'server_id' => 1], ['image' => ['name' => 'shell.png', 'content' => "<?php system('id'); ?>"]]);
    $alice->submit('/user/annuaire-edition?id=1', ['action' => 'upload_icon', 'server_id' => 1], ['image' => ['name' => 'logo.svg', 'content' => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>']]);
    check($t->scalar("SELECT jeton FROM MEDIA WHERE role = 'ICONE'") === $token && (int) $t->scalar("SELECT COUNT(*) FROM MEDIA WHERE role = 'ICONE'") === 1, 'Les faux fichiers et le SVG doivent être refusés');

    for ($i = 0; $i < 5; $i++) {
        $alice->submit('/user/annuaire-edition?id=1', ['action' => 'upload_gallery', 'server_id' => 1, 'legende' => "Capture $i"], ['image' => ['name' => "capture$i.png", 'content' => make_png(640, 400)]]);
    }
    check((int) $t->scalar("SELECT COUNT(*) FROM MEDIA WHERE role = 'GALERIE'") === 4, "La galerie d'un compte Standard est plafonnée à 4 images");
    $alice->submit('/user/annuaire-edition?id=1', ['action' => 'upload_banner', 'server_id' => 1], ['image' => ['name' => 'banniere.png', 'content' => make_png(1600, 400)]]);
    check((int) $t->scalar("SELECT COUNT(*) FROM MEDIA WHERE role = 'BANNIERE'") === 0, 'La bannière est réservée aux comptes Premium');

    $page = $t->client()->get('/serveur-public?id=1')['body'];
    check(substr_count($page, 'data-gallery-item') === 4 && str_contains($page, 'Capture 0'), 'La galerie et ses légendes doivent apparaître sur la fiche');
    check($t->client()->get('/media?f=' . str_repeat('0', 32))['status'] === 404 && $t->client()->get('/media?f=../../.env')['status'] === 404, 'Jeton inconnu ou chemin forgé : 404');
    $errors = $t->drainPhpErrors();
    check($errors === [], 'Erreurs PHP : ' . implode(' | ', $errors));
}];

// ---------------------------------------------------------------------------
// Exécution
// ---------------------------------------------------------------------------

/**
 * Serveur PHP intégré dédié à un test. Un processus neuf par test isole les sessions et évite
 * les blocages du serveur intégré sous Windows après un grand nombre de connexions.
 */
final class TestServer
{
    /** @var resource|null */
    private $process = null;

    /**
     * @param array<string, string> $env
     */
    public function __construct(
        private readonly string $root,
        private readonly string $workDir,
        private readonly array $env
    ) {
    }

    public function start(TestContext $ctx): void
    {
        $port = 0;
        for ($attempt = 0; $attempt < 50 && $port === 0; $attempt++) {
            $candidate = random_int(18000, 18999);
            $probe     = @fsockopen('127.0.0.1', $candidate, $errno, $errstr, 0.2);
            if ($probe === false) {
                $port = $candidate;
            } else {
                fclose($probe);
            }
        }

        $command = [PHP_BINARY];
        if (php_ini_loaded_file() !== false) {
            array_push($command, '-c', php_ini_loaded_file());
        }
        array_push(
            $command,
            '-d', 'display_errors=0',
            '-d', 'log_errors=1',
            '-d', 'error_log=' . $ctx->errorLog,
            '-d', 'session.save_path=' . $this->workDir . '/sessions',
            '-d', 'output_buffering=0',
            '-S', '127.0.0.1:' . $port,
            '-t', $this->root,
            $this->root . '/tests/support/router.php'
        );

        $log           = $this->workDir . '/server.log';
        $this->process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $this->root, $this->env);
        if (!is_resource($this->process)) {
            throw new RuntimeException('Impossible de démarrer le serveur PHP intégré.');
        }

        for ($i = 0; $i < 100; $i++) {
            usleep(50000);
            $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($sock !== false) {
                fclose($sock);
                $ctx->baseUrl = 'http://127.0.0.1:' . $port;
                return;
            }
        }

        throw new RuntimeException('Le serveur PHP intégré ne répond pas.');
    }

    public function stop(): void
    {
        if (!is_resource($this->process)) {
            return;
        }

        // Arrêt direct du processus (sans shell intermédiaire) puis attente bornée de sa fin.
        proc_terminate($this->process);
        for ($i = 0; $i < 100 && proc_get_status($this->process)['running']; $i++) {
            usleep(50000);
        }
        proc_close($this->process);
        $this->process = null;
    }
}

$filter = $argv[1] ?? '';

// --list : affiche les tests numérotés ; --index=N : exécute uniquement le test N
if ($filter === '--list') {
    foreach ($tests as $index => [$name]) {
        echo $index, "\t", $name, "\n";
    }
    exit(0);
}
if (preg_match('/^--index=(\d+)$/', $filter, $m)) {
    $tests  = isset($tests[(int) $m[1]]) ? [$tests[(int) $m[1]]] : [];
    $filter = '';
}

$root    = dirname(__DIR__);
$workDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bootes-tests-' . bin2hex(random_bytes(4));
mkdir($workDir . '/sessions', 0777, true);

$ctx             = new TestContext();
$ctx->dbFile     = $workDir . '/bootes.sqlite';
$ctx->storageDir = $workDir . '/storage';
$ctx->errorLog   = $workDir . '/php-errors.log';

$env = getenv();
$env['BOOTES_TEST_DB']      = $ctx->dbFile;
$env['BOOTES_STORAGE_PATH'] = $ctx->storageDir;
$env['APP_ENV']             = 'test';
$env['BOOTES_DIRECTORY_PING'] = '0';

$server = new TestServer($root, $workDir, $env);
$passed = 0;
$failed = 0;
$start  = microtime(true);

foreach ($tests as [$name, $fn]) {
    if ($filter !== '' && !preg_match('~' . str_replace('~', '\~', $filter) . '~iu', $name)) {
        continue;
    }

    // Une seconde tentative n'est accordée que si le serveur intégré a cessé de répondre.
    for ($attempt = 1; $attempt <= 2; $attempt++) {
        $transportFailures = HttpClient::$transportFailures;
        try {
            $ctx->reset();
            $server->start($ctx);
            $fn($ctx);
            $passed++;
            echo "  \u{2714} {$name}\n";
            break;
        } catch (Throwable $e) {
            if ($attempt === 1 && HttpClient::$transportFailures > $transportFailures) {
                continue;
            }
            $failed++;
            echo "  \u{2718} {$name}\n      -> " . $e->getMessage() . "\n";
            break;
        } finally {
            $server->stop();
        }
    }
}

if ($failed === 0) {
    TestContext::removeTree($workDir);
} else {
    echo "\nJournaux conservés dans {$workDir}\n";
}

printf("\n%d réussi(s), %d échec(s) en %.1f s\n", $passed, $failed, microtime(true) - $start);
exit($failed === 0 ? 0 : 1);
