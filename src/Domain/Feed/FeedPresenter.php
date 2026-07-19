<?php

namespace Spoome\Domain\Feed;

use Spoome\Domain\Profiles\ProfilePresenter;

/**
 * Forma pubblica di un elemento del feed (post o attività), con l'autore idratato.
 */
final class FeedPresenter
{
    /**
     * @param array $row    riga grezza dalla timeline (kind, id, text, act_type, subject_id, created_at)
     * @param array $author riga profilo arricchita dell'autore
     */
    public static function item(array $row, array $author, array $extra = []): array
    {
        $isPost = $row['kind'] === 'post';
        return [
            'kind'       => $row['kind'], // 'post' | 'activity'
            'id'         => (int) $row['id'],
            'created_at' => $row['created_at'],
            'author'     => ProfilePresenter::card($author),
            'text'       => $isPost ? $row['text'] : null,
            'activity'   => $isPost ? null : [
                'type'       => $row['act_type'],
                'meta'       => $row['text'],
                'subject_id' => $row['subject_id'] !== null ? (int) $row['subject_id'] : null,
            ],
            'likes_count'    => $extra['likes_count'] ?? 0,
            'comments_count' => $extra['comments_count'] ?? 0,
            'liked'          => $extra['liked'] ?? false,
            'comments'       => $extra['comments'] ?? [],
            'link_preview'   => $extra['link_preview'] ?? null,
        ];
    }
}
