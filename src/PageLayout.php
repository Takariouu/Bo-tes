<?php

declare(strict_types=1);

namespace Bootes\Src;

use PDO;
use PDOException;

/**
 * Mise en page par blocs des fiches (serveurs, mods, plugins, modpacks).
 *
 * - Tous les visiteurs voient la page telle que son propriétaire l'a composée.
 * - Seul un propriétaire Premium peut modifier la structure (ordre, blocs, colonnes, thème) : la
 *   vérification est faite côté serveur par l'appelant avant save().
 * - Les éléments fixes (barre de connexion d'un serveur avec son adresse IP, colonne d'informations,
 *   avis, signalement) ne font PAS partie des blocs : ce moteur ne peut ni les déplacer ni les masquer.
 */
final class PageLayout
{
    public const THEMES = [
        'defaut'    => 'Thème du site',
        'emeraude'  => 'Émeraude',
        'ambre'     => 'Ambre',
        'rubis'     => 'Rubis',
        'amethyste' => 'Améthyste',
        'ocean'     => 'Océan',
        'aurore'    => 'Aurore',
        'nether'    => 'Nether',
        'glace'     => 'Glace',
    ];

    /** Types de blocs : libellé, icône du sprite et nombre maximal sur une page. */
    public const BLOCKS = [
        'presentation' => ['label' => 'Présentation',      'icon' => 'align-left', 'max' => 1],
        'galerie'      => ['label' => 'Galerie d\'images', 'icon' => 'images',     'max' => 1],
        'texte'        => ['label' => 'Section de texte',  'icon' => 'text',       'max' => 6],
        'points_forts' => ['label' => 'Points forts',      'icon' => 'sparkles',   'max' => 2],
        'annonce'      => ['label' => 'Annonce',           'icon' => 'megaphone',  'max' => 3],
        'liens'        => ['label' => 'Liens utiles',      'icon' => 'link',       'max' => 1],
    ];

    public const ANNOUNCE_TONES = [
        'info'      => 'Information',
        'succes'    => 'Bonne nouvelle',
        'attention' => 'Important',
        'evenement' => 'Événement',
    ];

    public const MAX_BLOCKS      = 12;
    public const MAX_LIST_ITEMS  = 8;
    public const MAX_LINKS       = 6;

    public function __construct(
        private readonly PDO $db
    ) {
    }

    /**
     * Mise en page d'une fiche ; la mise en page par défaut si aucune n'a été enregistrée.
     *
     * @return array{theme: string, blocs: array<int, array<string, mixed>>, personnalisee: bool}
     */
    public function load(string $targetType, int $targetId): array
    {
        try {
            $stmt = $this->db->prepare('SELECT theme, blocs FROM PAGE_PERSONNALISEE WHERE type_cible = :t AND id_cible = :id LIMIT 1');
            $stmt->execute([':t' => $targetType, ':id' => $targetId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log(sprintf('[Boötes][Mise en page] Lecture impossible : %s', $e->getMessage()));
            $row = false;
        }

        if ($row === false) {
            return self::defaultLayout();
        }

        $decoded = json_decode((string) $row['blocs'], true);
        [$theme, $blocks] = self::normalize($row['theme'], is_array($decoded) ? $decoded : []);

        return ['theme' => $theme, 'blocs' => $blocks, 'personnalisee' => true];
    }

    /** @param array<int, array<string, mixed>> $blocks blocs déjà normalisés */
    public function save(string $targetType, int $targetId, string $theme, array $blocks, int $authorId): bool
    {
        [$theme, $blocks] = self::normalize($theme, $blocks);
        $json = json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            $this->db->prepare('DELETE FROM PAGE_PERSONNALISEE WHERE type_cible = :t AND id_cible = :id')
                ->execute([':t' => $targetType, ':id' => $targetId]);
            $this->db->prepare('
                INSERT INTO PAGE_PERSONNALISEE (type_cible, id_cible, theme, blocs, id_auteur, date_maj)
                VALUES (:t, :id, :theme, :blocs, :author, NOW())
            ')->execute([':t' => $targetType, ':id' => $targetId, ':theme' => $theme, ':blocs' => (string) $json, ':author' => $authorId]);
        } catch (PDOException $e) {
            error_log(sprintf('[Boötes][Mise en page] Enregistrement impossible : %s', $e->getMessage()));
            return false;
        }

        return true;
    }

    public function reset(string $targetType, int $targetId): void
    {
        $this->db->prepare('DELETE FROM PAGE_PERSONNALISEE WHERE type_cible = :t AND id_cible = :id')
            ->execute([':t' => $targetType, ':id' => $targetId]);
    }

    /** @return array{theme: string, blocs: array<int, array<string, mixed>>, personnalisee: bool} */
    public static function defaultLayout(): array
    {
        return [
            'theme'         => 'defaut',
            'blocs'         => [
                ['type' => 'presentation', 'colonnes' => 1],
                ['type' => 'galerie', 'titre' => '', 'style' => 'carrousel'],
            ],
            'personnalisee' => false,
        ];
    }

    /**
     * Valide et normalise un thème et une liste de blocs (enregistrés ou issus du formulaire) :
     * types et options en liste blanche, longueurs bornées, nombre de blocs plafonné, bloc
     * « Présentation » toujours présent.
     *
     * @param array<int|string, mixed> $rawBlocks
     * @return array{0: string, 1: array<int, array<string, mixed>>}
     */
    public static function normalize(mixed $theme, array $rawBlocks): array
    {
        $theme  = is_string($theme) && isset(self::THEMES[$theme]) ? $theme : 'defaut';
        $counts = [];
        $blocks = [];

        foreach ($rawBlocks as $raw) {
            $type = is_array($raw) ? (string) ($raw['type'] ?? '') : '';
            if (!isset(self::BLOCKS[$type]) || ($counts[$type] ?? 0) >= self::BLOCKS[$type]['max'] || count($blocks) >= self::MAX_BLOCKS) {
                continue;
            }
            $counts[$type] = ($counts[$type] ?? 0) + 1;
            $blocks[]      = self::normalizeBlock($type, $raw);
        }

        if (!isset($counts['presentation'])) {
            array_unshift($blocks, ['type' => 'presentation', 'colonnes' => 1]);
        }

        return [$theme, $blocks];
    }

    /**
     * Applique une opération de l'éditeur : « up:3 », « down:3 », « remove:3 » ou « add:texte ».
     *
     * @param array<int, array<string, mixed>> $blocks
     * @return array{0: array<int, array<string, mixed>>, 1: ?string} blocs, message d'erreur éventuel
     */
    public static function applyOperation(array $blocks, string $operation): array
    {
        [$verb, $argument] = array_pad(explode(':', $operation, 2), 2, '');
        $index             = ctype_digit($argument) ? (int) $argument : -1;

        switch ($verb) {
            case 'up':
            case 'down':
                $swap = $verb === 'up' ? $index - 1 : $index + 1;
                if (isset($blocks[$index], $blocks[$swap])) {
                    [$blocks[$index], $blocks[$swap]] = [$blocks[$swap], $blocks[$index]];
                }
                break;

            case 'remove':
                if (isset($blocks[$index]) && $blocks[$index]['type'] !== 'presentation') {
                    array_splice($blocks, $index, 1);
                }
                break;

            case 'add':
                if (!isset(self::BLOCKS[$argument])) {
                    return [$blocks, 'Type de bloc inconnu.'];
                }
                $sameType = count(array_filter($blocks, static fn (array $b): bool => $b['type'] === $argument));
                if ($sameType >= self::BLOCKS[$argument]['max'] || count($blocks) >= self::MAX_BLOCKS) {
                    return [$blocks, sprintf('Limite atteinte pour le bloc « %s ».', self::BLOCKS[$argument]['label'])];
                }
                $blocks[] = self::normalizeBlock($argument, ['type' => $argument]);
                break;
        }

        return [array_values($blocks), null];
    }

    /**
     * Texte de bloc mis en forme en HTML sûr : paragraphes (ligne vide), retours à la ligne, listes
     * (lignes « - ») et **gras**. Tout est échappé avant la moindre mise en forme.
     */
    public static function richText(string $text): string
    {
        $html = '';
        foreach (preg_split("/\n{2,}/", trim($text)) ?: [] as $paragraph) {
            $lines = explode("\n", $paragraph);
            $isList = count(array_filter($lines, static fn (string $l): bool => preg_match('/^\s*[-•]\s+/u', $l) === 1)) === count($lines);

            if ($isList) {
                $items = array_map(static fn (string $l): string => '<li>' . self::inline((string) preg_replace('/^\s*[-•]\s+/u', '', $l)) . '</li>', $lines);
                $html .= '<ul>' . implode('', $items) . '</ul>';
            } else {
                $html .= '<p>' . implode('<br>', array_map([self::class, 'inline'], $lines)) . '</p>';
            }
        }

        return $html;
    }

    private static function inline(string $line): string
    {
        return (string) preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', Security::e($line));
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private static function normalizeBlock(string $type, array $raw): array
    {
        $title   = mb_substr(Security::validateString($raw['titre'] ?? '', 0, 200) ?? '', 0, 80);
        $columns = (int) ($raw['colonnes'] ?? 1) === 2 ? 2 : 1;

        return match ($type) {
            'presentation' => ['type' => $type, 'colonnes' => $columns],
            'galerie'      => [
                'type'  => $type,
                'titre' => $title,
                'style' => ($raw['style'] ?? '') === 'grille' ? 'grille' : 'carrousel',
            ],
            'texte' => [
                'type'     => $type,
                'titre'    => $title,
                'contenu'  => self::clip($raw['contenu'] ?? '', 4000),
                'colonnes' => $columns,
                'encadre'  => !empty($raw['encadre']),
            ],
            'points_forts' => [
                'type'   => $type,
                'titre'  => $title,
                'points' => self::lines($raw['points'] ?? [], self::MAX_LIST_ITEMS, 100),
            ],
            'annonce' => [
                'type'  => $type,
                'texte' => self::clip($raw['texte'] ?? '', 280),
                'ton'   => isset(self::ANNOUNCE_TONES[$raw['ton'] ?? '']) ? (string) $raw['ton'] : 'info',
            ],
            'liens' => [
                'type'  => $type,
                'titre' => $title,
                'liens' => self::links($raw['liens'] ?? []),
            ],
        };
    }

    private static function clip(mixed $text, int $max): string
    {
        return mb_substr(Security::validateMultiline(is_string($text) ? $text : '', 0, $max * 2) ?? '', 0, $max);
    }

    /**
     * Liste de lignes courtes ; accepte un tableau ou un texte (une entrée par ligne).
     *
     * @return array<int, string>
     */
    private static function lines(mixed $raw, int $maxItems, int $maxLength): array
    {
        $items = is_array($raw) ? $raw : explode("\n", is_string($raw) ? $raw : '');
        $clean = [];
        foreach ($items as $item) {
            $line = mb_substr(Security::validateString(is_string($item) ? $item : '', 1, 1000) ?? '', 0, $maxLength);
            if ($line !== '') {
                $clean[] = $line;
            }
        }

        return array_slice($clean, 0, $maxItems);
    }

    /**
     * Liens d'un bloc « Liens utiles » : tableau de {libelle, url} ou texte « Libellé | https://... ».
     *
     * @return array<int, array{libelle: string, url: string}>
     */
    private static function links(mixed $raw): array
    {
        $entries = is_array($raw) ? $raw : explode("\n", is_string($raw) ? $raw : '');
        $links   = [];
        foreach ($entries as $entry) {
            if (is_string($entry)) {
                [$label, $url] = array_pad(array_map('trim', explode('|', $entry, 2)), 2, '');
                if ($url === '') {
                    [$label, $url] = ['', $label];
                }
            } else {
                $label = (string) ($entry['libelle'] ?? '');
                $url   = (string) ($entry['url'] ?? '');
            }

            $url = Security::validateWebUrl($url);
            if ($url === null) {
                continue;
            }
            $label   = mb_substr(Security::validateString($label, 1, 200) ?? '', 0, 40);
            $links[] = ['libelle' => $label !== '' ? $label : (string) parse_url($url, PHP_URL_HOST), 'url' => $url];
        }

        return array_slice($links, 0, self::MAX_LINKS);
    }
}
